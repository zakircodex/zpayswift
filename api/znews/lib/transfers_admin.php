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

    $currentStatus = strtoupper(trim((string)($row['status'] ?? ''));
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

function znews_transfer_admin_approve(
    array $auth,
    string $requestId,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $adminUser = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $adminUid = znews_firebase_key((string)($adminUser['uid'] ?? ''), 'admin_uid');
    $requestId = znews_firebase_key($requestId, 'request_id');

    $adminClaim = znews_transfer_admin_claim(
        $adminUid,
        'APPROVE',
        $requestId,
        $expectedUpdatedAt,
        $idempotencyKey
    );
    if (empty($adminClaim['ok']) || !empty($adminClaim['idempotent_replay'])) {
        return $adminClaim;
    }

    $state = znews_transfer_request_claim_state(
        $requestId,
        $expectedUpdatedAt,
        'APPROVING',
        $adminUid
    );
    if (empty($state['ok'])) {
        znews_transfer_admin_finish($adminClaim, $state, true);
        return $state;
    }
    $request = (array)$state['row'];
    if (!empty($state['terminal'])) {
        $approved = strtoupper(trim((string)($request['status'] ?? ''))) === 'APPROVED';
        $result = [
            'ok' => $approved,
            'code' => $approved ? 'ZNEWS_TRANSFER_ALREADY_APPROVED' : 'ZNEWS_TRANSFER_ALREADY_REJECTED',
            'http_status' => $approved ? 200 : 409,
            'request' => znews_transfer_public($request),
        ];
        znews_transfer_admin_finish($adminClaim, $result, false);
        return $result;
    }

    $walletCredit = znews_transfer_wallet_credit($request, $auth);
    if (empty($walletCredit['ok'])) {
        $patch = [
            'status' => !empty($walletCredit['reconciliation_required'])
                ? 'RECONCILIATION_REQUIRED'
                : 'PENDING',
            'reconciliation_required' => !empty($walletCredit['reconciliation_required']),
            'reconciliation_code' => (string)($walletCredit['code'] ?? 'ZNEWS_TRANSFER_WALLET_CREDIT_FAILED'),
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ];
        @fb_patch((string)$state['path'], $patch);
        $result = [
            'ok' => false,
            'code' => (string)($walletCredit['code'] ?? 'ZNEWS_TRANSFER_WALLET_CREDIT_FAILED'),
            'http_status' => 503,
            'reconciliation_required' => !empty($walletCredit['reconciliation_required']),
        ];
        znews_transfer_admin_finish($adminClaim, $result, true);
        return $result;
    }

    $consume = znews_transfer_consume_balance(
        (string)$request['uid'],
        (string)$request['source_currency'],
        $requestId,
        (int)$request['source_amount_micros']
    );
    if (empty($consume['ok'])) {
        @fb_patch((string)$state['path'], [
            'status' => 'RECONCILIATION_REQUIRED',
            'reconciliation_required' => true,
            'reconciliation_code' => (string)($consume['code'] ?? 'ZNEWS_TRANSFER_SOURCE_BALANCE_CONSUME_FAILED'),
            'main_wallet_credit_status' => 'CREDITED',
            'main_wallet_transfer_id' => (string)($walletCredit['transfer_id'] ?? ''),
            'main_wallet_ledger_id' => (string)($walletCredit['ledger_id'] ?? ''),
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        $result = [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_RECONCILIATION_REQUIRED',
            'http_status' => 503,
            'reconciliation_required' => true,
        ];
        znews_transfer_admin_finish($adminClaim, $result, true);
        return $result;
    }

    $now = znews_now();
    $request['status'] = 'APPROVED';
    $request['reservation_status'] = 'CONSUMED';
    $request['main_wallet_credit_status'] = 'CREDITED';
    $request['main_wallet_transfer_id'] = (string)($walletCredit['transfer_id'] ?? '');
    $request['main_wallet_ledger_id'] = (string)($walletCredit['ledger_id'] ?? '');
    $request['approved_by_uid'] = $adminUid;
    $request['approved_at'] = $now;
    $request['lease_expires_at'] = 0;
    $request['reconciliation_required'] = false;
    $request['reconciliation_code'] = null;
    $request['updated_at'] = $now;

    $index = [
        'request_id' => $requestId,
        'uid' => (string)$request['uid'],
        'status' => 'APPROVED',
        'source_currency' => (string)$request['source_currency'],
        'source_amount_micros' => (int)$request['source_amount_micros'],
        'bdt_equivalent_micros' => (int)$request['bdt_equivalent_micros'],
        'destination_currency' => (string)$request['destination_currency'],
        'destination_amount_minor' => (int)$request['destination_amount_minor'],
        'created_at' => (int)$request['created_at'],
        'updated_at' => $now,
    ];
    $sourceLedger = [
        'entry_id' => $requestId,
        'request_id' => $requestId,
        'type' => 'MAIN_WALLET_TRANSFER',
        'direction' => 'DEBIT',
        'currency' => (string)$request['source_currency'],
        'amount_micros' => (int)$request['source_amount_micros'],
        'amount' => znews_transfer_micros_to_decimal((int)$request['source_amount_micros']),
        'bdt_equivalent_micros' => (int)$request['bdt_equivalent_micros'],
        'destination_currency' => (string)$request['destination_currency'],
        'destination_amount_minor' => (int)$request['destination_amount_minor'],
        'main_wallet_transfer_id' => (string)$request['main_wallet_transfer_id'],
        'main_wallet_ledger_id' => (string)$request['main_wallet_ledger_id'],
        'status' => 'POSTED',
        'created_at' => $now,
    ];

    $finalized = fb_patch('', [
        znews_transfer_request_path($requestId) => $request,
        znews_transfer_user_index_path((string)$request['uid'], $requestId) => $index,
        znews_transfer_queue_path($requestId) => null,
        znews_settlement_creator_ledger_path(
            (string)$request['uid'],
            (string)$request['source_currency'],
            $requestId
        ) => $sourceLedger,
    ]);
    if (!$finalized) {
        @fb_patch((string)$state['path'], [
            'status' => 'RECONCILIATION_REQUIRED',
            'reconciliation_required' => true,
            'reconciliation_code' => 'ZNEWS_TRANSFER_FINALIZATION_FAILED',
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        $result = [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_RECONCILIATION_REQUIRED',
            'http_status' => 503,
            'reconciliation_required' => true,
        ];
        znews_transfer_admin_finish($adminClaim, $result, true);
        return $result;
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_TRANSFER_APPROVED', $requestId, 'Z News balance transfer approved', [
            'uid' => (string)$request['uid'],
            'admin_uid' => $adminUid,
            'main_wallet_transfer_id' => (string)$request['main_wallet_transfer_id'],
            'main_wallet_ledger_id' => (string)$request['main_wallet_ledger_id'],
        ]);
    }

    $result = [
        'ok' => true,
        'code' => 'ZNEWS_TRANSFER_APPROVED',
        'http_status' => 200,
        'request' => znews_transfer_public($request),
        'balance' => is_array($consume['balance'] ?? null) ? (array)$consume['balance'] : [],
    ];
    znews_transfer_admin_finish($adminClaim, $result, false);
    return $result;
}

function znews_transfer_rejection_reason($value): string
{
    $reason = trim((string)$value);
    if ($reason === '' || strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
        api_response(false, 'ZNEWS_TRANSFER_REJECTION_REASON_INVALID', 'Valid rejection reason is required.', [], 422);
    }
    return $reason;
}

function znews_transfer_admin_reject(
    array $auth,
    string $requestId,
    int $expectedUpdatedAt,
    string $idempotencyKey,
    string $reason
): array {
    $adminUser = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $adminUid = znews_firebase_key((string)($adminUser['uid'] ?? ''), 'admin_uid');
    $requestId = znews_firebase_key($requestId, 'request_id');
    $reason = znews_transfer_rejection_reason($reason);

    $adminClaim = znews_transfer_admin_claim(
        $adminUid,
        'REJECT',
        $requestId,
        $expectedUpdatedAt,
        $idempotencyKey,
        ['reason' => $reason]
    );
    if (empty($adminClaim['ok']) || !empty($adminClaim['idempotent_replay'])) {
        return $adminClaim;
    }

    $state = znews_transfer_request_claim_state(
        $requestId,
        $expectedUpdatedAt,
        'REJECTING',
        $adminUid
    );
    if (empty($state['ok'])) {
        znews_transfer_admin_finish($adminClaim, $state, true);
        return $state;
    }
    $request = (array)$state['row'];
    if (!empty($state['terminal'])) {
        $rejected = strtoupper(trim((string)($request['status'] ?? ''))) === 'REJECTED';
        $result = [
            'ok' => $rejected,
            'code' => $rejected ? 'ZNEWS_TRANSFER_ALREADY_REJECTED' : 'ZNEWS_TRANSFER_ALREADY_APPROVED',
            'http_status' => $rejected ? 200 : 409,
            'request' => znews_transfer_public($request),
        ];
        znews_transfer_admin_finish($adminClaim, $result, false);
        return $result;
    }

    $release = znews_transfer_release_balance(
        (string)$request['uid'],
        (string)$request['source_currency'],
        $requestId,
        (int)$request['source_amount_micros']
    );
    if (empty($release['ok'])) {
        @fb_patch((string)$state['path'], [
            'status' => 'RECONCILIATION_REQUIRED',
            'reconciliation_required' => true,
            'reconciliation_code' => (string)($release['code'] ?? 'ZNEWS_TRANSFER_SOURCE_BALANCE_RELEASE_FAILED'),
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        $result = [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_RECONCILIATION_REQUIRED',
            'http_status' => 503,
            'reconciliation_required' => true,
        ];
        znews_transfer_admin_finish($adminClaim, $result, true);
        return $result;
    }

    $now = znews_now();
    $request['status'] = 'REJECTED';
    $request['reservation_status'] = 'RELEASED';
    $request['rejection_reason'] = $reason;
    $request['rejected_by_uid'] = $adminUid;
    $request['rejected_at'] = $now;
    $request['lease_expires_at'] = 0;
    $request['reconciliation_required'] = false;
    $request['reconciliation_code'] = null;
    $request['updated_at'] = $now;
    $index = [
        'request_id' => $requestId,
        'uid' => (string)$request['uid'],
        'status' => 'REJECTED',
        'source_currency' => (string)$request['source_currency'],
        'source_amount_micros' => (int)$request['source_amount_micros'],
        'bdt_equivalent_micros' => (int)$request['bdt_equivalent_micros'],
        'destination_currency' => (string)$request['destination_currency'],
        'destination_amount_minor' => (int)$request['destination_amount_minor'],
        'created_at' => (int)$request['created_at'],
        'updated_at' => $now,
    ];

    $finalized = fb_patch('', [
        znews_transfer_request_path($requestId) => $request,
        znews_transfer_user_index_path((string)$request['uid'], $requestId) => $index,
        znews_transfer_queue_path($requestId) => null,
    ]);
    if (!$finalized) {
        @fb_patch((string)$state['path'], [
            'status' => 'RECONCILIATION_REQUIRED',
            'reconciliation_required' => true,
            'reconciliation_code' => 'ZNEWS_TRANSFER_REJECT_FINALIZATION_FAILED',
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        $result = [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_RECONCILIATION_REQUIRED',
            'http_status' => 503,
            'reconciliation_required' => true,
        ];
        znews_transfer_admin_finish($adminClaim, $result, true);
        return $result;
    }

    $result = [
        'ok' => true,
        'code' => 'ZNEWS_TRANSFER_REJECTED',
        'http_status' => 200,
        'request' => znews_transfer_public($request),
        'balance' => is_array($release['balance'] ?? null) ? (array)$release['balance'] : [],
    ];
    znews_transfer_admin_finish($adminClaim, $result, false);
    return $result;
}
