<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_admin_approve.php';

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
