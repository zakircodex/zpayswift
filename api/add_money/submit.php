<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/add_money.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'UNAUTHORIZED', 'Session is required', [], 401);
}

$role = strtoupper(trim((string)($user['role'] ?? $user['account_type'] ?? 'USER')));
if (!in_array($role, ['USER', 'RETAILER'], true)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
}

$wallet = fb_get('USER_WALLETS/' . $uid);
$wallet = is_array($wallet) ? $wallet : [];

$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
$body = str_contains($contentType, 'multipart/form-data')
    ? $_POST
    : api_read_json_body();

$res = add_money_create_request($uid, $user, $wallet, $body, $_FILES);

if (empty($res['ok'])) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');
    $clientCodes = [
        'VALIDATION_ERROR',
        'INVALID_AMOUNT',
        'INVALID_METHOD',
        'TXN_REQUIRED',
        'SENDER_REQUIRED',
        'DUPLICATE_TXN_ID',
        'DUPLICATE_TRANSACTION_ID',
        'DUPLICATE_RECEIPT',
        'RECEIPT_REQUIRED',
        'INVALID_RECEIPT',
        'INVALID_RECEIPT_SIZE',
        'INVALID_RECEIPT_TYPE',
        'PAYMENT_ACCOUNT_REQUIRED',
        'PAYMENT_ACCOUNT_INVALID',
        'PAYMENT_ACCOUNT_UNAVAILABLE',
        'ADD_MONEY_DISABLED',
    ];

    api_response(
        false,
        $code,
        (string)($res['message'] ?? 'Failed to submit add money request'),
        (array)($res['data'] ?? []),
        in_array($code, $clientCodes, true) ? 422 : 500
    );
}

$data = (array)($res['data'] ?? []);
if (is_array($data['request'] ?? null)) {
    $data['request'] = add_money_public_request_row($data['request']);
}

api_response(
    true,
    'ADD_MONEY_SUBMITTED',
    'Add money request submitted. Please wait for approval.',
    $data
);
