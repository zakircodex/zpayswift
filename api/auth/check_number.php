<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();
system_require_user_service_available();

$body = api_read_json_body();
$deviceId = auth_app_device_id($body);
$trustedDeviceCookie = trim((string)($body['trusted_device_cookie'] ?? ''));
$phoneInput = trim((string)($body['phone'] ?? $body['account'] ?? ''));

if ($phoneInput === '' && $deviceId === 'USER_WEB' && $trustedDeviceCookie !== '') {
    $uid = auth_trusted_browser_cookie_uid_hint($trustedDeviceCookie);
    if ($uid === '') {
        api_response(false, 'TRUSTED_DEVICE_NOT_FOUND', 'Trusted login is not available.', [], 404);
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        api_response(false, 'TRUSTED_DEVICE_INVALID', 'Trusted login is not available.', [], 401);
    }
    auth_app_guard_user_login($user);

    $trustedBrowser = auth_trusted_browser_cookie_context(
        $uid,
        $trustedDeviceCookie,
        $deviceId,
        $user,
        false
    );
    if (empty($trustedBrowser['ok'])) {
        api_response(false, (string)($trustedBrowser['code'] ?? 'TRUSTED_DEVICE_INVALID'), 'Trusted login is not available.', [], 401);
    }

    $phoneCountry = auth_phone_country_from_user($user);
    $phone = normalize_phone_by_country((string)($user['phone'] ?? ''), $phoneCountry);
    if ($phone === '' || !in_array($phoneCountry, ['BD', 'MY'], true)) {
        api_response(false, 'TRUSTED_DEVICE_INVALID', 'Trusted login is not available.', [], 401);
    }
    $wallet = fb_get('USER_WALLETS/' . $uid);
    $pricingCountry = auth_pricing_country_from_user($user, is_array($wallet) ? $wallet : []);
    $preAuthToken = auth_app_create_preauth($uid, $phone, $body, [
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'password_verified' => true,
        'pin_verified' => false,
        'trusted_browser_verified' => true,
        'trusted_browser_selector_hash' => (string)($trustedBrowser['selector_hash'] ?? ''),
        'status' => 'TRUSTED_DEVICE_RECOGNIZED',
    ]);

    api_response(true, 'TRUSTED_ACCOUNT_FOUND', 'Trusted account found.', [
        'exists' => true,
        'phone' => $phone,
        'account' => $phone,
        'name' => (string)($user['name'] ?? ''),
        'masked_name' => auth_app_mask_name((string)($user['name'] ?? '')),
        'masked_phone' => auth_app_mask_phone($phone),
        'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
        'kyc_status' => (string)($user['kyc_status'] ?? $user['KYC']['status'] ?? ''),
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'device_trusted' => true,
        'trusted_login_available' => true,
        'pre_auth_token' => $preAuthToken,
        'otp_required' => false,
    ]);
}

$account = auth_app_lookup_user_by_body($body);
$uid = (string)$account['uid'];
$user = (array)$account['user'];

auth_app_guard_user_login($user);

$deviceTrusted = $deviceId !== '' && auth_app_trusted_login_allowed($uid, $deviceId);
$trustedBrowser = ['ok' => false];

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
