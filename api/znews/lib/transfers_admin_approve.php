<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_admin_claims.php';

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
