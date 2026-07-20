<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function forgot_app_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function forgot_app_mask_phone(string $phone): string
{
    return function_exists('auth_app_mask_phone') ? auth_app_mask_phone($phone) : substr($phone, 0, 3) . '***' . substr($phone, -3);
}

function forgot_app_allowed_role(array $user): bool
{
    $role = strtoupper(trim((string)($user['role'] ?? '')));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function forgot_app_token(int $bytes = 16): string
{
    return 'UFPA' . strtoupper(bin2hex(random_bytes($bytes)));
}

$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? $body['country'] ?? $body['country_code'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? ''));
}
if ($phoneCountry === '') {
    api_response(false, 'LOCATION_REQUIRED', 'Please enable location to detect your country.', [], 422);
}

$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

$uid = auth_find_uid_by_phone_country($phone, $phoneCountry);
if ($uid === '') {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found. Please register first.', [], 404);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found. Please register first.', [], 404);
}

auth_app_guard_user_login($user);

if (!forgot_app_allowed_role($user)) {
    api_response(false, 'FORBIDDEN', 'Only user accounts can use this recovery.', [], 403);
}

$status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
if ($status !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'Account is not active.', [], 403);
}

$storedPhoneCountry = auth_phone_country_from_user($user);
if ($storedPhoneCountry !== $phoneCountry) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found. Please register first.', [], 404);
}

$now = forgot_app_now();
$preAuthToken = forgot_app_token();
$pricingCountry = auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));
$deviceId = auth_app_device_id($body, 'ANDROID_FORGOT');
$deviceName = auth_app_device_name($body, 'Android App');

$row = [
    'pre_auth_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'uid' => $uid,
    'phone' => normalize_phone_by_country((string)($user['phone'] ?? $phone), $phoneCountry) ?: $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'masked_phone' => forgot_app_mask_phone($phone),
    'status' => 'IDENTITY_PENDING',
    'identity_verified' => false,
    'biometric_verified' => false,
    'otp_verified' => false,
    'reset_type' => 'PASSWORD_PIN',
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'app_version' => trim((string)($body['app_version'] ?? '')),
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $now + 3600,
];

if (!fb_put('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, $row)) {
    api_response(false, 'SERVER_ERROR', 'Failed to start forgot flow.', [], 500);
}

api_response(true, 'FORGOT_STARTED', 'Forgot flow started.', [
    'pre_auth_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'masked_phone' => forgot_app_mask_phone($phone),
    'phone_country' => $phoneCountry,
    'country_code' => $phoneCountry === 'BD' ? '+880' : '+60',
    'requires_identity' => true,
]);
