<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/bundle.php';
require_once dirname(__DIR__) . '/lib/telegram.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];
$uid = (string)$user['uid'];
$userPhone = (string)$user['phone'];

$body = api_read_json_body();

$bundleNumber = normalize_bd_topup_number($body['bundle_number'] ?? '');
$operator = normalize_operator($body['operator'] ?? '');
$bundleName = trim((string)($body['bundle_name'] ?? ''));
$amount = (float)($body['amount'] ?? 0);
$pin = trim((string)($body['pin'] ?? ''));
$note = trim((string)($body['note'] ?? ''));

if (!is_valid_bd_topup_number($bundleNumber)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid bundle number', [
        'field' => 'bundle_number',
    ], 422);
}

if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Invalid operator', [
        'field' => 'operator',
    ], 422);
}

if ($bundleName === '') {
    api_response(false, 'VALIDATION_ERROR', 'bundle_name is required', [
        'field' => 'bundle_name',
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

$appConfig = fb_get('APP_CONFIG');
if (is_array($appConfig)) {
    if (!(bool)($appConfig['bundle_enabled'] ?? true)) {
        api_response(false, 'BUNDLE_DISABLED', 'Bundle service is currently disabled', [], 422);
    }

    if ((bool)($appConfig['maintenance_mode'] ?? false)) {
        api_response(false, 'BUNDLE_DISABLED', 'System is under maintenance', [], 422);
    }

    $min = (float)($appConfig['min_bundle_amount'] ?? 0);
    $max = (float)($appConfig['max_bundle_amount'] ?? 0);

    if ($min > 0 && $amount < $min) {
        api_response(false, 'VALIDATION_ERROR', 'Amount is below minimum limit', [
            'field' => 'amount',
            'min_bundle_amount' => $min,
        ], 422);
    }

    if ($max > 0 && $amount > $max) {
        api_response(false, 'VALIDATION_ERROR', 'Amount exceeds maximum limit', [
            'field' => 'amount',
            'max_bundle_amount' => $max,
        ], 422);
    }
}

require_active_operator($operator);

$userPinHash = (string)($user['pin_hash'] ?? '');
if ($userPinHash === '' || !password_verify($pin, $userPinHash)) {
    api_response(false, 'INVALID_PIN', 'Transaction PIN is incorrect', [], 401);
}

$requestId = make_bundle_request_id();

$hold = wallet_hold_amount($uid, $amount, $requestId, 'BUNDLE_HOLD');
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

$pendingSaved = create_bundle_pending_request(
    $requestId,
    $uid,
    $userPhone,
    $bundleNumber,
    $operator,
    $bundleName,
    $amount,
    $note,
    false,
    ''
);

if (!$pendingSaved) {
    wallet_refund_hold($uid, $amount, $requestId, 'BUNDLE_REFUND');
    api_response(false, 'SERVER_ERROR', 'Failed to create bundle request', [], 500);
}

$statusSaved = create_request_status(
    $requestId,
    'BUNDLE',
    $uid,
    'WAITING_ADMIN',
    'Bundle request submitted and waiting for admin'
);

if (!$statusSaved) {
    fb_delete('BUNDLE_REQUESTS/PENDING/' . $requestId);
    wallet_refund_hold($uid, $amount, $requestId, 'BUNDLE_REFUND');
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

$bundleRow = fb_get('BUNDLE_REQUESTS/PENDING/' . $requestId);
$tgMessage = build_bundle_telegram_message(is_array($bundleRow) ? $bundleRow : [], $user);
$queueId = telegram_queue_create('BUNDLE_ALERT', $requestId, $tgMessage);

$send = telegram_send_message($tgMessage);
if ($send['ok'] ?? false) {
    telegram_queue_mark_sent($queueId);
    fb_patch('BUNDLE_REQUESTS/PENDING/' . $requestId, [
        'telegram_sent' => true,
        'telegram_queue_id' => $queueId,
        'updated_at' => now_ts(),
    ]);
} else {
    telegram_queue_mark_failed($queueId, (string)($send['message'] ?? 'Telegram send failed'));
    fb_patch('BUNDLE_REQUESTS/PENDING/' . $requestId, [
        'telegram_sent' => false,
        'telegram_queue_id' => $queueId,
        'updated_at' => now_ts(),
    ]);
}

system_log('BUNDLE_SUBMIT', $requestId, 'Bundle request created successfully', [
    'uid' => $uid,
    'operator' => $operator,
    'amount' => $amount,
    'bundle_number' => $bundleNumber,
    'telegram_sent' => (bool)($send['ok'] ?? false),
]);

api_response(true, 'BUNDLE_REQUEST_CREATED', 'Bundle request submitted', [
    'request_id' => $requestId,
    'status' => 'WAITING_ADMIN',
    'bundle_number' => $bundleNumber,
    'operator' => $operator,
    'bundle_name' => $bundleName,
    'amount' => $amount,
    'telegram_sent' => (bool)($send['ok'] ?? false),
    'telegram_message' => (string)($send['message'] ?? ''),
]);