<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_access.php';

$znewsTransferNotificationLib = dirname(__DIR__, 2) . '/lib/notifications.php';
if (is_file($znewsTransferNotificationLib)) {
    require_once $znewsTransferNotificationLib;
}

function znews_transfer_wallet_credit(array $request, array $admin): array
{
    $requestId = znews_firebase_key((string)($request['request_id'] ?? ''), 'request_id');
    $uid = znews_firebase_key((string)($request['uid'] ?? ''), 'uid');
    $destinationCurrency = znews_transfer_currency($request['destination_currency'] ?? '');
    $destinationMinor = max(0, (int)($request['destination_amount_minor'] ?? 0));
    if ($destinationMinor <= 0) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_DESTINATION_AMOUNT_INVALID'];
    }
    $destinationAmount = znews_transfer_minor_to_float($destinationMinor);

    $user = fb_get('USERS/' . $uid);
    $wallet = fb_get('USER_WALLETS/' . $uid);
    if (!is_array($user) || !is_array($wallet)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_WALLET_NOT_FOUND'];
    }
    if (strtoupper(trim((string)($user['status'] ?? 'ACTIVE'))) !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_USER_NOT_ACTIVE'];
    }
    $currentCurrency = wallet_account_currency($user, $wallet);
    if ($currentCurrency === ''
        || !hash_equals($destinationCurrency, $currentCurrency)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_DESTINATION_CURRENCY_CHANGED',
            'current_currency' => $currentCurrency,
        ];
    }

    $adminUser = is_array($admin['user'] ?? null) ? (array)$admin['user'] : [];
    $adminUid = znews_firebase_key((string)($adminUser['uid'] ?? ''), 'admin_uid');
    $operationRef = znews_transfer_wallet_operation_ref($requestId);
    $transferId = trim((string)($request['main_wallet_transfer_id'] ?? ''));
    if ($transferId === '') {
        $transferId = znews_transfer_wallet_transfer_id($requestId);
    }

    $operation = wallet_financial_operation_begin(
        $operationRef,
        'ZNEWS_BALANCE_TRANSFER',
        'REQUEST_FINAL',
        $uid,
        $destinationAmount,
        $destinationCurrency,
        [
            'actor_uid' => $adminUid,
            'target_uid' => $uid,
            'transfer_id' => $transferId,
            'source' => 'ZNEWS_TRANSFER_APPROVAL',
            'znews_request_id' => $requestId,
        ]
    );

    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        $saved = is_array($operation['operation']['result_data'] ?? null)
            ? (array)$operation['operation']['result_data']
            : [];
        if ($saved === [] || trim((string)($saved['ledger_id'] ?? '')) === '') {
            return [
                'ok' => false,
                'code' => 'ZNEWS_TRANSFER_WALLET_RECONCILIATION_REQUIRED',
                'reconciliation_required' => true,
            ];
        }
        return [
            'ok' => true,
            'idempotent_replay' => true,
            'ledger_id' => (string)$saved['ledger_id'],
            'transfer_id' => (string)($saved['transfer_id'] ?? $transferId),
            'available_balance' => (float)($saved['available_balance'] ?? 0),
            'currency' => (string)($saved['currency'] ?? $destinationCurrency),
        ];
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'),
            'reconciliation_required' => (string)($operation['code'] ?? '') === 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
        ];
    }

    $claim = (array)$operation['claim'];
    $ledgerId = wallet_financial_operation_side_ledger_id(
        $operationRef,
        'ZNEWS_BALANCE_TRANSFER',
        'user_credited'
    );
    $now = wallet_now();
    $identity = wallet_identity($uid, $user, (string)($user['role'] ?? 'USER'));
    $note = 'Z Sky 24 balance transferred to main wallet';

    $credit = wallet_credit_available(
        $uid,
        $destinationAmount,
        $operationRef,
        'ZNEWS_BALANCE_TRANSFER',
        $note,
        [
            'ledger_id' => $ledgerId,
            'type' => 'ZNEWS_BALANCE_TRANSFER',
            'currency' => $destinationCurrency,
            'wallet_currency' => $destinationCurrency,
            'country_code' => wallet_account_country_code($user, $wallet),
            'transfer_id' => $transferId,
            'transfer_type' => 'ZNEWS_BALANCE_TRANSFER',
            'znews_request_id' => $requestId,
            'source_currency' => (string)($request['source_currency'] ?? ''),
            'source_amount_micros' => (int)($request['source_amount_micros'] ?? 0),
            'bdt_equivalent_micros' => (int)($request['bdt_equivalent_micros'] ?? 0),
            'source_to_bdt_rate_micros' => (int)($request['source_to_bdt_rate_micros'] ?? 0),
            'myr_to_bdt_rate_micros' => (int)($request['myr_to_bdt_rate_micros'] ?? 0),
            'sender_uid' => 'ZNEWS',
            'sender_name' => 'Z Sky 24',
            'sender_role' => 'SYSTEM',
            'receiver_uid' => $identity['uid'],
            'receiver_name' => $identity['name'],
            'receiver_phone' => $identity['phone'],
            'receiver_role' => $identity['role'],
            'created_by_uid' => $adminUid,
            'created_by_role' => 'ADMIN',
            'created_at' => $now,
            'updated_at' => $now,
            'source' => 'ZNEWS',
            'status' => 'SUCCESS',
        ],
        ['financial_operation' => $claim]
    );
    if (empty($credit['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($credit['code'] ?? 'ZNEWS_TRANSFER_WALLET_CREDIT_FAILED'),
            'reconciliation_required' => in_array(
                (string)($credit['code'] ?? ''),
                ['LEDGER_WRITE_FAILED', 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED'],
                true
            ),
        ];
    }

    $ledgerId = trim((string)($credit['ledger_id'] ?? $ledgerId));
    $ledger = $ledgerId !== ''
        ? fb_get('WALLET_LEDGER/' . $uid . '/' . wallet_month_key($now) . '/' . $ledgerId)
        : null;
    if (!is_array($ledger)) {
        wallet_financial_operation_mark_reconciliation_required(
            $claim,
            'LEDGER_EVIDENCE_MISSING',
            'Z Sky 24 wallet credit ledger evidence is missing'
        );
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_WALLET_RECONCILIATION_REQUIRED',
            'reconciliation_required' => true,
        ];
    }

    $beforeAvailable = wallet_round_money((float)($credit['before_available'] ?? 0));
    $afterAvailable = wallet_round_money((float)($credit['after_available'] ?? $credit['available_balance'] ?? 0));
    $beforeHold = wallet_round_money((float)($wallet['hold_balance'] ?? $credit['hold_balance'] ?? 0));
    $afterHold = wallet_round_money((float)($credit['hold_balance'] ?? $beforeHold));
    $transfer = [
        'transfer_id' => $transferId,
        'ledger_id' => $ledgerId,
        'receiver_ledger_id' => $ledgerId,
        'sender_ledger_id' => '',
        'type' => 'ZNEWS_BALANCE_TRANSFER',
        'direction' => 'CREDIT',
        'amount' => $destinationAmount,
        'currency' => $destinationCurrency,
        'receiver_currency' => $destinationCurrency,
        'receiver_country_code' => wallet_account_country_code($user, $wallet),
        'sender_uid' => 'ZNEWS',
        'sender_name' => 'Z Sky 24',
        'sender_phone' => '',
        'sender_role' => 'SYSTEM',
        'receiver_uid' => $identity['uid'],
        'receiver_name' => $identity['name'],
        'receiver_phone' => $identity['phone'],
        'receiver_role' => $identity['role'],
        'before_balance' => $beforeAvailable,
        'after_balance' => $afterAvailable,
        'before_available' => $beforeAvailable,
        'after_available' => $afterAvailable,
        'before_hold' => $beforeHold,
        'after_hold' => $afterHold,
        'receiver_before_available' => $beforeAvailable,
        'receiver_after_available' => $afterAvailable,
        'receiver_before_hold' => $beforeHold,
        'receiver_after_hold' => $afterHold,
        'sender_wallet_debited' => false,
        'note' => $note,
        'reference' => $requestId,
        'ref_id' => $operationRef,
        'znews_request_id' => $requestId,
        'source_currency' => (string)($request['source_currency'] ?? ''),
        'source_amount_micros' => (int)($request['source_amount_micros'] ?? 0),
        'bdt_equivalent_micros' => (int)($request['bdt_equivalent_micros'] ?? 0),
        'created_by_uid' => $adminUid,
        'created_by_role' => 'ADMIN',
        'created_at' => $now,
        'updated_at' => $now,
        'source' => 'ZNEWS',
        'status' => 'SUCCESS',
    ];
    $history = wallet_store_transfer_records($transfer);
    if (empty($history['ok'])) {
        wallet_financial_operation_mark_failed(
            $claim,
            'TRANSFER_HISTORY_FAILED',
            'Z Sky 24 wallet transfer history could not be saved',
            [
                'wallet_applied' => true,
                'ledger_written' => true,
                'transfer' => $transfer,
            ]
        );
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_WALLET_HISTORY_FAILED',
            'reconciliation_required' => true,
        ];
    }

    $resultData = [
        'ledger_id' => $ledgerId,
        'transfer_id' => $transferId,
        'available_balance' => $afterAvailable,
        'currency' => $destinationCurrency,
    ];
    if (!wallet_financial_operation_mark_completed($claim, [
        'request_finalized' => true,
        'wallet_applied' => true,
        'ledger_written' => true,
        'transfer_history_written' => true,
        'transfer' => $transfer,
        'result_data' => $resultData,
    ])) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_WALLET_FINALIZE_FAILED',
            'reconciliation_required' => true,
        ];
    }

    if (function_exists('notification_record_user')) {
        @notification_record_user(
            $uid,
            'ZNEWS_BALANCE_TRANSFER',
            'Balance transferred',
            'Your Z Sky 24 balance was transferred to your main wallet.',
            'ZNEWS_TRANSFER_REQUESTS',
            $requestId,
            'ZNEWS_BALANCE_TRANSFER:' . $requestId,
            [
                'request_id' => $requestId,
                'transfer_id' => $transferId,
                'ledger_id' => $ledgerId,
            ]
        );
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'ledger_id' => $ledgerId,
        'transfer_id' => $transferId,
        'available_balance' => $afterAvailable,
        'currency' => $destinationCurrency,
    ];
}
