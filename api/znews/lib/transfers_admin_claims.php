<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_wallet.php';

function znews_transfer_admin_claim(
    string $adminUid,
    string $action,
    string $requestId,
    int $expectedUpdatedAt,
    string $idempotencyKey,
    array $extraPayload = []
): array {
    $adminUid = znews_firebase_key($adminUid, 'admin_uid');
    $action = strtoupper(trim($action));
    $requestId = znews_firebase_key($requestId, 'request_id');
    $path = znews_transfer_admin_idempotency_path($adminUid, $action, $idempotencyKey);
    $payloadHash = hash('sha256', json_encode(array_merge([
        'action' => $action,
        'request_id' => $requestId,
        'expected_updated_at' => $expectedUpdatedAt,
    ], $extraPayload), JSON_UNESCAPED_SLASHES));
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_ADMIN_REQUEST_READ_FAILED', 'http_status' => 503];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedHash === '' || !hash_equals($savedHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT', 'http_status' => 409];
            }
            if (strtoupper(trim((string)($existing['status'] ?? ''))) === 'COMPLETED') {
                $result = is_array($existing['result'] ?? null) ? (array)$existing['result'] : [];
                $result['idempotent_replay'] = true;
                return array_merge(['ok' => !empty($result['ok'])], $result, [
                    '_idempotency_path' => $path,
                ]);
            }
            if ((int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_ADMIN_REQUEST_IN_PROGRESS', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_ADMIN_REQUEST_INVALID', 'http_status' => 409];
        }

        $claim = [
            'admin_uid' => $adminUid,
            'action' => $action,
            'request_id' => $requestId,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + 90,
            'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_ADMIN_REQUEST_CLAIM_FAILED', 'http_status' => 503];
        }
        return [
            'ok' => true,
            'claim' => $claim,
            '_idempotency_path' => $path,
            'idempotent_replay' => false,
        ];
    }

    return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_ADMIN_REQUEST_BUSY', 'http_status' => 409];
}

function znews_transfer_admin_finish(array $claim, array $result, bool $retryable = false): void
{
    $path = trim((string)($claim['_idempotency_path'] ?? ''));
    if ($path === '') {
        return;
    }
    $row = is_array($claim['claim'] ?? null) ? (array)$claim['claim'] : [];
    $row['status'] = $retryable ? 'FAILED_RETRYABLE' : 'COMPLETED';
    $row['result'] = $result;
    $row['lease_expires_at'] = 0;
    $row['completed_at'] = znews_now();
    $row['updated_at'] = znews_now();
    @fb_put($path, $row);
}

function znews_transfer_request_claim_state(
    string $requestId,
    int $expectedUpdatedAt,
    string $targetStatus,
    string $adminUid
): array {
    $requestId = znews_firebase_key($requestId, 'request_id');
    $path = znews_transfer_request_path($requestId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_READ_FAILED', 'http_status' => 503];
    }
    $row = $snapshot['value'] ?? null;
    if (!is_array($row)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_NOT_FOUND', 'http_status' => 404];
    }

    $currentUpdatedAt = (int)($row['updated_at'] ?? 0);
    if ($expectedUpdatedAt <= 0 || $expectedUpdatedAt !== $currentUpdatedAt) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_VERSION_CONFLICT',
            'http_status' => 409,
            'current_updated_at' => $currentUpdatedAt,
        ];
    }

    $currentStatus = strtoupper(trim((string)($row['status'] ?? '')));
    if ($currentStatus === 'APPROVED' || $currentStatus === 'REJECTED') {
        return [
            'ok' => true,
            'terminal' => true,
            'row' => $row,
            'path' => $path,
        ];
    }

    $allowed = $targetStatus === 'APPROVING'
        ? ['PENDING', 'APPROVING', 'RECONCILIATION_REQUIRED']
        : ['PENDING', 'REJECTING'];
    if (!in_array($currentStatus, $allowed, true)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_STATE_INVALID', 'http_status' => 409];
    }
    if (in_array($currentStatus, ['APPROVING', 'REJECTING'], true)
        && (int)($row['lease_expires_at'] ?? 0) > znews_now()) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_IN_PROGRESS', 'http_status' => 409];
    }

    $now = znews_now();
    $updated = $row;
    $updated['status'] = $targetStatus;
    $updated['lease_expires_at'] = $now + znews_transfer_lease_seconds();
    $updated['processing_admin_uid'] = $adminUid;
    $updated['processing_started_at'] = $now;
    $updated['updated_at'] = $now;
    $write = fb_put_if_match($path, $updated, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_VERSION_CONFLICT', 'http_status' => 409];
    }
    if (empty($write['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_CLAIM_FAILED', 'http_status' => 503];
    }

    return [
        'ok' => true,
        'terminal' => false,
        'row' => $updated,
        'path' => $path,
    ];
}
