<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements_access.php';

function znews_settlement_admin_request(
    array $auth,
    string $impressionId,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $adminUid = znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid');
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $key = znews_idempotency_key($idempotencyKey);
    $path = znews_settlement_admin_request_path($adminUid, $key);
    $payloadHash = hash('sha256', json_encode([
        'action' => 'SETTLE_AD_IMPRESSION',
        'impression_id' => $impressionId,
        'expected_updated_at' => $expectedUpdatedAt,
        'creator_share_bps' => znews_settlement_creator_share_bps(),
    ], JSON_UNESCAPED_SLASHES));
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_REQUEST_READ_FAILED', 'http_status' => 503];
        }

        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedHash === '' || !hash_equals($savedHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT', 'http_status' => 409];
            }

            $status = strtoupper(trim((string)($existing['status'] ?? '')));
            if ($status === 'COMPLETED') {
                $result = is_array($existing['result'] ?? null)
                    ? (array)$existing['result']
                    : [];
                return [
                    'ok' => !empty($result['ok']),
                    'code' => (string)($result['code'] ?? 'ZNEWS_SETTLEMENT_COMPLETED'),
                    'http_status' => (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500)),
                    'idempotent_replay' => true,
                    'settlement' => is_array($result['settlement'] ?? null)
                        ? (array)$result['settlement']
                        : [],
                    'current_updated_at' => isset($result['current_updated_at'])
                        ? (int)$result['current_updated_at']
                        : null,
                ];
            }

            if ($status === 'PROCESSING'
                && (int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_REQUEST_IN_PROGRESS', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_REQUEST_INVALID', 'http_status' => 409];
        }

        $claim = [
            'admin_uid' => $adminUid,
            'action' => 'SETTLE_AD_IMPRESSION',
            'impression_id' => $impressionId,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + znews_settlement_lease_seconds(),
            'created_at' => is_array($existing)
                ? (int)($existing['created_at'] ?? $now)
                : $now,
            'updated_at' => $now,
        ];

        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_REQUEST_CLAIM_FAILED', 'http_status' => 503];
        }

        $result = znews_settle_impression(
            $auth,
            $impressionId,
            $expectedUpdatedAt
        );
        $claim['status'] = 'COMPLETED';
        $claim['result'] = [
            'ok' => !empty($result['ok']),
            'code' => (string)($result['code'] ?? ''),
            'http_status' => (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500)),
            'settlement' => is_array($result['settlement'] ?? null)
                ? (array)$result['settlement']
                : [],
            'current_updated_at' => isset($result['current_updated_at'])
                ? (int)$result['current_updated_at']
                : null,
        ];
        $claim['completed_at'] = znews_now();
        $claim['updated_at'] = znews_now();
        $claim['lease_expires_at'] = 0;
        @fb_put($path, $claim);

        return $result;
    }

    return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_REQUEST_BUSY', 'http_status' => 409];
}
