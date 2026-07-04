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

$adminUid = (string)($adminUser['uid'] ?? '');
$adminPhone = (string)($adminUser['phone'] ?? '');

if ($adminUid === '') {
    api_response(false, 'UNAUTHORIZED', 'Admin session missing uid', [], 401);
}

$requestId = make_topup_request_id();
$financials = topup_commission_breakdown($adminUid, $amount, $adminUser);
$walletDebit = (float)$financials['wallet_debit_amount'];

$hold = wallet_hold_amount($adminUid, $walletDebit, $requestId, 'TOPUP_HOLD');
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'SERVER_ERROR');

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
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'commission_amount' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'wallet_currency' => $financials['wallet_currency'],
    'rate_used' => $financials['rate_used'],
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
    wallet_refund_hold($adminUid, $walletDebit, $requestId, 'TOPUP_REFUND');
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
    fb_delete('TOPUP_REQUESTS/PENDING/' . $requestId);
    wallet_refund_hold($adminUid, $walletDebit, $requestId, 'TOPUP_REFUND');
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

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

api_response(true, 'TOPUP_REQUEST_CREATED', 'Admin direct topup request created', [
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
]);
