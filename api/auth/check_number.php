<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$account = auth_app_lookup_user_by_body($body);
$uid = (string)$account['uid'];
$user = (array)$account['user'];

auth_app_guard_user_login($user);

$deviceId = auth_app_device_id($body);
$deviceTrusted = $deviceId !== '' && auth_app_trusted_login_allowed($uid, $deviceId);
$trustedBrowser = ['ok' => false];
$trustedDeviceCookie = trim((string)($body['trusted_device_cookie'] ?? ''));

if ($deviceId === 'USER_WEB' && $deviceTrusted && $trustedDeviceCookie !== '') {
    $trustedBrowser = auth_trusted_browser_cookie_context(
        $uid,
        $trustedDeviceCookie,
        $deviceId,
        $user,
        false
    );
}

$trustedLoginAvailable = !empty($trustedBrowser['ok']);
$preAuthToken = '';
if ($trustedLoginAvailable) {
    $preAuthToken = auth_app_create_preauth($uid, (string)$account['phone'], $body, [
        'phone_country' => (string)$account['phone_country'],
        'pricing_country' => (string)$account['pricing_country'],
        'password_verified' => true,
        'pin_verified' => false,
        'trusted_browser_verified' => true,
        'trusted_browser_selector_hash' => (string)($trustedBrowser['selector_hash'] ?? ''),
        'status' => 'TRUSTED_DEVICE_RECOGNIZED',
    ]);
}

api_response(true, 'ACCOUNT_FOUND', 'Account found.', [
    'exists' => true,
    'phone' => (string)$account['phone'],
    'account' => (string)$account['phone'],
    'name' => (string)($user['name'] ?? ''),
    'masked_name' => auth_app_mask_name((string)($user['name'] ?? '')),
    'masked_phone' => auth_app_mask_phone((string)$account['phone']),
    'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
    'kyc_status' => (string)($user['kyc_status'] ?? $user['KYC']['status'] ?? ''),
    'phone_country' => (string)$account['phone_country'],
    'pricing_country' => (string)$account['pricing_country'],
    'device_trusted' => $deviceTrusted,
    'trusted_login_available' => $trustedLoginAvailable,
    'pre_auth_token' => $preAuthToken,
    'otp_required' => !$deviceTrusted,
]);
