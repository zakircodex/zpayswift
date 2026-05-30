<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];
$uid = (string)$user['uid'];
$userPhone = (string)$user['phone'];

$body = api_read_json_body();

$topupNumber = normalize_bd_topup_number($body['topup_number'] ?? '');
$operator = normalize_operator($body['operator'] ?? '');
$amount = (float)($body['amount'] ?? 0);
$pin = trim((string)($body['pin'] ?? ''));

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

if (!is_valid_user_pin($pin)) {
    api_response(false, 'VALIDATION_ERROR', 'PIN must be exactly 4 digits', [
        'field' => 'pin',
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Global app config check
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Operator runtime check
|--------------------------------------------------------------------------
*/
$runtime = require_active_operator($operator);

/*
|--------------------------------------------------------------------------
| Verify user transaction PIN
|--------------------------------------------------------------------------
*/
$userPinHash = (string)($user['pin_hash'] ?? '');
if ($userPinHash === '' || !password_verify($pin, $userPinHash)) {
    api_response(false, 'INVALID_PIN', 'Transaction PIN is incorrect', [], 401);
}

/*
|--------------------------------------------------------------------------
| Create request + hold balance
|--------------------------------------------------------------------------
*/
$requestId = make_topup_request_id();

$hold = wallet_hold_amount($uid, $amount, $requestId, 'TOPUP_HOLD');
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'SERVER_ERROR');

    if ($code === 'INSUFFICIENT_BALANCE') {
        api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough balance', [
            'available_balance' => (float)($hold['available_balance'] ?? 0),
            'required_amount' => (float)($hold['required_amount'] ?? $amount),
        ], 422);
    }

    api_response(false, $code, (string)($hold['message'] ?? 'Wallet hold failed'), [], 500);
}

/*
|--------------------------------------------------------------------------
| Save topup request
|--------------------------------------------------------------------------
*/
$pendingSaved = create_topup_pending_request(
    $requestId,
    $uid,
    $userPhone,
    $topupNumber,
    $operator,
    $amount
);

if (!$pendingSaved) {
    wallet_refund_hold($uid, $amount, $requestId, 'TOPUP_REFUND');
    api_response(false, 'SERVER_ERROR', 'Failed to create topup request', [], 500);
}

/*
|--------------------------------------------------------------------------
| Save request status
|--------------------------------------------------------------------------
*/
$statusSaved = create_request_status(
    $requestId,
    'TOPUP',
    $uid,
    'PENDING',
    'Topup request created successfully'
);

if (!$statusSaved) {
    fb_delete('TOPUP_REQUESTS/PENDING/' . $requestId);
    wallet_refund_hold($uid, $amount, $requestId, 'TOPUP_REFUND');
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

system_log('TOPUP_SUBMIT', $requestId, 'Topup request created successfully', [
    'uid' => $uid,
    'operator' => $operator,
    'amount' => $amount,
    'topup_number' => $topupNumber,
    'operator_active' => (bool)($runtime['active'] ?? false),
]);

api_response(true, 'TOPUP_REQUEST_CREATED', 'Topup request submitted', [
    'request_id' => $requestId,
    'status' => 'PENDING',
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
]);