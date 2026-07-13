<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/auth_android.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/mobile_transfer.php';

api_require_method('GET');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$refresh = !empty($_GET['refresh']);

$status = function_exists('auth_status_value') ? auth_status_value($user['status'] ?? '') : strtoupper(trim((string)($user['status'] ?? '')));
$accountStatus = function_exists('auth_status_value') ? auth_status_value($user['account_status'] ?? $status) : $status;
if ($uid === '' || $status !== 'ACTIVE' || $accountStatus !== 'ACTIVE') {
    api_response(false, 'ACCOUNT_INACTIVE', 'Your account is not active.', [], 403);
}

$token = zpay_transfer_issue_qr_token($uid, $refresh);
if ($token === []) {
    api_response(false, 'QR_TOKEN_FAILED', 'QR could not be created. Please try again.', [], 500);
}

api_response(true, 'QR_READY', 'QR ready.', [
    'qr_payload' => (string)($token['payload'] ?? ''),
    'expires_at' => (int)($token['expires_at'] ?? 0),
    'user_name' => (string)($user['name'] ?? ''),
    'uid' => $uid,
    'caption' => 'Scan to send money',
]);
