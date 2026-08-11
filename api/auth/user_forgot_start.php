<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/user_forgot_recovery.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? ''));
}

if (!in_array($phoneCountry, ['BD', 'MY'], true)) {
    api_response(false, 'LOCATION_REQUIRED', 'Phone country could not be detected.', [], 422);
}

$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

$uid = auth_find_uid_by_phone_country($phone, $phoneCountry);
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for this phone number.', [], 404);
}

$role = strtoupper(trim((string)($user['role'] ?? '')));
$status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
$storedPhoneCountry = auth_phone_country_from_user($user);
if (!in_array($role, ['USER', 'RETAILER'], true) || $status !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'This account is not eligible for recovery.', [], 403);
}
if ($storedPhoneCountry !== $phoneCountry) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for this phone number.', [], 404);
}

$identityType = user_forgot_registered_identity_type($user);
if ($identityType === '' || !user_forgot_identity_is_configured($user)) {
    api_response(false, 'IDENTITY_NOT_CONFIGURED', 'Identity verification is unavailable for this account. Please contact support.', [], 409);
}

$registeredPhone = normalize_phone_by_country((string)($user['phone'] ?? $phone), $storedPhoneCountry) ?: $phone;
$now = function_exists('now_ts') ? (int)now_ts() : time();
$preAuthToken = 'UFW' . strtoupper(bin2hex(random_bytes(18)));
$row = [
    'pre_auth_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'uid' => $uid,
    'phone' => $registeredPhone,
    'phone_country' => $storedPhoneCountry,
    'masked_phone' => auth_app_mask_phone($registeredPhone),
    'identity_type' => $identityType,
    'identity_verified' => false,
    'identity_failed_attempts' => 0,
    'identity_attempt_limit' => 5,
    'identity_next_attempt_at' => 0,
    'otp_verified' => false,
    'reset_allowed' => false,
    'reset_type' => 'PASSWORD_PIN',
    'status' => 'PHONE_VERIFIED',
    'device_id' => 'USER_WEB',
    'device_name' => 'User Forgot',
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'browser_timezone' => auth_request_browser_timezone($body),
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $now + 900,
];

if (!fb_put('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, $row)) {
    api_response(false, 'SERVER_ERROR', 'Account recovery could not be started. Please try again.', [], 500);
}

api_response(true, 'PHONE_VERIFIED', 'Account verified. Confirm your registered identity.', [
    'pre_auth_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'identity_type' => $identityType,
    'requires_identity' => true,
    'expires_in_seconds' => 900,
]);
