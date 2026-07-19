<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];
$session = $auth['session'];
$role = auth_status_value($user['role'] ?? '');
$uid = (string)$user['uid'];
$wallet = fb_get('USER_WALLETS/' . $uid);
$wallet = is_array($wallet) ? $wallet : [];
$pricingCountry = auth_pricing_country_from_user($user, $wallet);
$walletCurrency = function_exists('wallet_account_currency')
    ? wallet_account_currency($user, $wallet)
    : strtoupper((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? ($pricingCountry === 'MY' ? 'MYR' : 'BDT')));
$deviceId = (string)($session['device_id'] ?? '');
$deviceName = (string)($session['device_name'] ?? '');
$client = strtoupper(trim((string)(api_get_header('X-ZPAY-CLIENT') ?? api_get_header('X-CLIENT') ?? '')));
$mobileSession = str_starts_with($deviceId, 'zpa-')
    || stripos($deviceName, 'Android') !== false
    || in_array($client, ['ANDROID', 'ZPAY_ANDROID', 'MOBILE_APP'], true);

if ($mobileSession && !zpay_dash_allowed_mobile_role($role)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
}

api_response(true, 'SESSION_OK', 'Session valid', [
    'uid' => $uid,
    'name' => (string)$user['name'],
    'phone' => (string)$user['phone'],
    'email' => (string)($user['email'] ?? ''),
    'status' => (string)$user['status'],
    'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
    'role' => $role,
    'phone_country' => auth_phone_country_from_user($user),
    'pricing_country' => $pricingCountry,
    'wallet_currency' => $walletCurrency !== '' ? $walletCurrency : 'BDT',
    'created_at' => (int)($user['created_at'] ?? 0),
    'last_login_at' => (int)($user['last_login_at'] ?? 0),
    'profile_photo_url' => (string)($user['profile_photo_url'] ?? $user['profile_photo'] ?? $user['photo_url'] ?? ''),
    'device_id' => (string)($session['device_id'] ?? ''),
    'device_trusted' => auth_device_is_trusted((string)$user['uid'], (string)($session['device_id'] ?? '')),
]);
