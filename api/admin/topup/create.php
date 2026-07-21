<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/operators.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';
require_once dirname(__DIR__, 2) . '/lib/topup.php';
require_once dirname(__DIR__, 2) . '/lib/topup_config.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];

$body = api_read_json_body();

$topupNumber = normalize_bd_topup_number($body['topup_number'] ?? '');
$operator = normalize_operator($body['operator'] ?? '');
$amount = (float)($body['amount'] ?? 0);
$note = trim((string)($body['note'] ?? ''));

if (!is_valid_bd_topup_number($topupNumber)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid topup number', [
        'field' => 'topup_number',
    ], 422);
}

if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Invalid operator', [
        'field' => 'operator',
    ], 422);
}

if ($amount <= 0) {
    api_response(false, 'VALIDATION_ERROR', 'Amount must be greater than zero', [
        'field' => 'amount',
    ], 422);
}

$validation = topup_validate_request('BD', $operator, topup_money($amount), true, true);
if (empty($validation['ok'])) {
    topup_api_error($validation);
}
$runtime = (array)($validation['operator'] ?? []);

$adminUid = (string)($adminUser['uid'] ?? '');
$adminPhone = (string)($adminUser['phone'] ?? '');

if ($adminUid === '') {
    api_response(false, 'UNAUTHORIZED', 'Admin session missing uid', [], 401);
}

$requestId = make_topup_request_id();
$financials = topup_commission_breakdown($adminUid, $amount, $adminUser);
if (empty($financials['ok'])) {
    api_response(false, (string)($financials['code'] ?? 'TOPUP_CALCULATION_FAILED'), (string)($financials['message'] ?? 'Top-up calculation failed'), [], 422);
}
$walletDebit = (float)$financials['wallet_debit_amount'];

$operationSeed = implode('|', [
    $adminUid,
    $operator,
    $topupNumber,
    number_format($amount, 2, '.', ''),
    number_format($walletDebit, 2, '.', ''),
    (string)floor(now_ts() / 120),
]);
$operationRef = 'ADMIN_TOPUP_CREATE:' . hash('sha256', $operationSeed);
$operation = wallet_financial_operation_begin(
    $operationRef,
    'ADMIN_TOPUP_CREATE_HOLD',
    'REQUEST_CREATE',
    $adminUid,
    $walletDebit,
    (string)$financials['wallet_debit_currency'],
    [
        'request_id' => $requestId,
        'operator' => $operator,
        'topup_number_hash' => hash('sha256', $topupNumber),
        'admin_uid' => $adminUid,
    ]
);
if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
    $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
    api_response(true, 'TOPUP_REQUEST_CREATED', 'Admin direct topup request created', $resultData);
}
if (empty($operation['ok']) || empty($operation['claim'])) {
    api_response(false, (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'), (string)($operation['message'] ?? 'Wallet operation is unavailable'), [], 409);
}
$financialClaim = (array)$operation['claim'];
$requestId = trim((string)($financialClaim['meta']['request_id'] ?? $requestId));

$hold = wallet_hold_amount($adminUid, $walletDebit, $operationRef, 'ADMIN_TOPUP_HOLD', [
    'financial_operation' => $financialClaim,
    'ledger_extra' => [
        'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'ADMIN_TOPUP_CREATE_HOLD'),
        'request_id' => $requestId,
        'ref_id' => $requestId,
        'admin_uid' => $adminUid,
        'operator' => $operator,
        'topup_number_hash' => hash('sha256', $topupNumber),
        'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? $amount),
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => (string)$financials['wallet_debit_currency'],
        'wallet_currency' => (string)$financials['wallet_currency'],
        'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
        'rate_snapshot' => $financials['rate_snapshot'] ?? null,
        'rate_used' => (float)($financials['rate_used'] ?? 0),
        'commission_per_1000' => (float)($financials['commission_per_1000'] ?? 0),
        'commission_bdt' => (float)($financials['commission_bdt'] ?? 0),
        'source' => 'ADMIN_PANEL',
        'note' => 'Balance moved to hold for admin direct topup request',
    ],
]);
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'SERVER_ERROR');
    wallet_financial_operation_mark_failed($financialClaim, $code, (string)($hold['message'] ?? 'Wallet hold failed'));

    if ($code === 'INSUFFICIENT_BALANCE') {
        api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough admin balance', [
            'available_balance' => (float)($hold['available_balance'] ?? 0),
            'required_amount' => (float)($hold['required_amount'] ?? $walletDebit),
        ], 422);
    }

    api_response(false, $code, (string)($hold['message'] ?? 'Wallet hold failed'), [], 500);
}

$now = now_ts();

$row = [
    'request_id' => $requestId,
    'uid' => $adminUid,
    'user_phone' => $adminPhone,
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? $amount),
    'account_country' => (string)($financials['account_country'] ?? ''),
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
    'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
    'commission_amount' => (float)($financials['commission_amount'] ?? $financials['commission_bdt'] ?? 0),
    'commission_credit' => (float)($financials['commission_credit'] ?? 0),
    'fee_amount' => (float)($financials['fee_amount'] ?? 0),
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'wallet_currency' => $financials['wallet_currency'],
    'display_currency' => (string)($financials['display_currency'] ?? $financials['wallet_currency']),
    'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
    'rate_snapshot' => $financials['rate_snapshot'] ?? null,
    'rate_used' => $financials['rate_used'],
    'balance_before' => (float)($financials['balance_before'] ?? 0),
    'balance_after' => (float)($financials['balance_after'] ?? 0),
    'calculation_version' => (string)($financials['calculation_version'] ?? ''),
    'total_debit_bdt' => $financials['total_debit_bdt'],
    'total_debit' => $walletDebit,
    'charged_amount' => $walletDebit,
    'request_pin_verified' => true,
    'wallet_hold_amount' => $walletDebit,
    'status' => 'PENDING',
    'assigned_device_id' => '',
    'assigned_slot' => '',
    'created_at' => $now,
    'updated_at' => $now,

    'created_by_admin' => true,
    'request_source' => 'ADMIN_PANEL',
    'admin_uid' => $adminUid,
    'admin_name' => (string)($adminUser['name'] ?? ''),
    'admin_phone' => $adminPhone,
    'admin_note' => $note,
];

if (!fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $row)) {
    wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_CREATE_FAILED', 'Admin direct topup request could not be saved after wallet hold', [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_id' => $requestId,
        'request_row' => $row,
        'request_finalized' => false,
    ]);
    api_response(false, 'SERVER_ERROR', 'Failed to create admin topup request', [], 500);
}

$statusSaved = create_request_status(
    $requestId,
    'TOPUP',
    $adminUid,
    'PENDING',
    'Admin direct topup request created'
);

if (!$statusSaved) {
    wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_STATUS_CREATE_FAILED', 'Admin direct topup request status could not be saved after wallet hold', [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_id' => $requestId,
        'request_row' => $row,
        'request_finalized' => false,
    ]);
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

topup_write_history($row);

admin_action_log('ADMIN_DIRECT_TOPUP_CREATE', $requestId, 'Admin created direct topup request', [
    'request_id' => $requestId,
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'admin_uid' => $adminUid,
    'admin_note' => $note,
]);

system_log('ADMIN_DIRECT_TOPUP_CREATE', $requestId, 'Admin created direct topup request', [
    'request_id' => $requestId,
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'operator_active' => (bool)($runtime['active'] ?? false),
    'admin_uid' => $adminUid,
]);

topup_notify_telegram_request($row);

$responseData = [
    'request_id' => $requestId,
    'status' => 'PENDING',
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'total_debit' => $walletDebit,
    'created_by_admin' => true,
];

wallet_financial_operation_mark_completed($financialClaim, [
    'wallet_applied' => true,
    'ledger_written' => true,
    'request_finalized' => true,
    'history_written' => true,
    'notification_written' => true,
    'request_id' => $requestId,
    'ledger_id' => (string)($hold['ledger_id'] ?? ''),
    'result_data' => $responseData,
]);

api_response(true, 'TOPUP_REQUEST_CREATED', 'Admin direct topup request created', $responseData);
