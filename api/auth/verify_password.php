<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();
system_require_user_service_available();

$body = api_read_json_body();
$password = (string)($body['password'] ?? '');

if ($password === '') {
    api_response(false, 'VALIDATION_ERROR', 'Password is required.', [], 422);
}

$phoneCountry = auth_app_phone_country($body);
$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

$limitIdentity = auth_user_login_phone_identity($phoneCountry, $phone);
$limitState = auth_user_login_limit_state('PASSWORD', $limitIdentity);
auth_app_enforce_login_limit($limitState);

$account = auth_app_lookup_user_result($body, false);
if (empty($account['ok'])) {
    auth_app_dummy_password_verify($password);
    $failure = auth_user_login_record_attempt('PASSWORD', $limitIdentity);
    auth_app_enforce_login_limit($failure);
    api_response(false, 'WRONG_PASSWORD', 'Password ভুল হয়েছে।', [], 401);
}

$uid = (string)$account['uid'];
$user = (array)$account['user'];

if (!auth_app_password_ok($user, $password)) {
    $failure = auth_user_login_record_attempt('PASSWORD', $limitIdentity);
    auth_app_enforce_login_limit($failure);
    api_response(false, 'WRONG_PASSWORD', 'Password ভুল হয়েছে।', [], 401);
}

$reset = auth_user_login_reset_attempts('PASSWORD', $limitIdentity, $limitState);
if (empty($reset['ok'])) {
    api_response(false, 'LOGIN_PROTECTION_UNAVAILABLE', 'Login protection is temporarily unavailable.', [], 503);
}

auth_app_guard_user_login($user);

$wallet = fb_get('USER_WALLETS/' . $uid);
$pricingCountry = auth_pricing_country_from_user($user, is_array($wallet) ? $wallet : []);

$preAuthToken = auth_app_create_preauth($uid, (string)$account['phone'], $body, [
    'phone_country' => (string)$account['phone_country'],
    'pricing_country' => $pricingCountry,
    'password_verified' => true,
    'pin_verified' => false,
    'status' => 'PASSWORD_VERIFIED',
]);

api_response(true, 'PASSWORD_VERIFIED', 'Password verified.', [
    'pre_auth_token' => $preAuthToken,
    'user' => auth_app_public_user($uid, $user),
]);
