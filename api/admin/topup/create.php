<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/operators.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';
require_once dirname(__DIR__, 2) . '/lib/topup.php';

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

$appConfig = fb_get('APP_CONFIG');
if (is_array($appConfig)) {
    if (!(bool)($appConfig['topup_enabled'] ?? true)) {
        api_response(false, 'TOPUP_DISABLED', 'Topup service is currently disabled', [], 422);
    }

    if ((bool)($appConfig['maintenance_mode'] ?? false)) {
        api_response(false, 'TOPUP_DISABLED', 'System is under maintenance', [], 422);
    }

    $min = (float)($appConfig['min_topup_amount'] ?? 0);
    $max = (float)($appConfig['max_topup_amount'] ?? 0);

    if ($min > 0 && $amount < $min) {
        api_response(false, 'VALIDATION_ERROR', 'Amount is below minimum limit', [
            'field' => 'amount',
            'min_topup_amount' => $min,
        ], 422);
    }

    if ($max > 0 && $amount > $max) {
        api_response(false, 'VALIDATION_ERROR', 'Amount exceeds maximum limit', [
            'field' => 'amount',
            'max_topup_amount' => $max,
        ], 422);
    }
}

$runtime = require_active_operator($operator);

$adminUid = (string)($adminUser['uid'] ?? '');
$adminPhone = (string)($adminUser['phone'] ?? '');

if ($adminUid === '') {
    api_response(false, 'UNAUTHORIZED', 'Admin session missing uid', [], 401);
}

$requestId = make_topup_request_id();

$hold = wallet_hold_amount($adminUid, $amount, $requestId, 'TOPUP_HOLD');
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'SERVER_ERROR');

    if ($code === 'INSUFFICIENT_BALANCE') {
        api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough admin balance', [
            'available_balance' => (float)($hold['available_balance'] ?? 0),
            'required_amount' => (float)($hold['required_amount'] ?? $amount),
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
    'request_pin_verified' => true,
    'wallet_hold_amount' => $amount,
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
    wallet_refund_hold($adminUid, $amount, $requestId, 'TOPUP_REFUND');
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
    wallet_refund_hold($adminUid, $amount, $requestId, 'TOPUP_REFUND');
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

admin_action_log('ADMIN_DIRECT_TOPUP_CREATE', $requestId, 'Admin created direct topup request', [
    'request_id' => $requestId,
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'admin_uid' => $adminUid,
    'admin_note' => $note,
]);

system_log('ADMIN_DIRECT_TOPUP_CREATE', $requestId, 'Admin created direct topup request', [
    'request_id' => $requestId,
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
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
    'created_by_admin' => true,
]);
