<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_forgot_send_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_forgot_send_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_forgot_send_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function user_forgot_send_normalize_phone(string $phone, string $country = 'BD'): string
{
    if (function_exists('normalize_phone_by_country')) {
        return normalize_phone_by_country($phone, $country);
    }

    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function user_forgot_send_mask_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function user_forgot_send_find_uid_by_phone(string $phone, string $country): string
{
    if (function_exists('auth_find_uid_by_phone_country')) {
        return auth_find_uid_by_phone_country($phone, $country);
    }

    $phone = user_forgot_send_normalize_phone($phone, $country);
    if ($phone === '') {
        return '';
    }

    $row = fb_get('USER_INDEX/PHONE/' . $phone);

    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function user_forgot_send_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function user_forgot_send_sms(
    string $country,
    string $phone,
    string $message,
    string $referenceId,
    string $otpCode
): array
{
    if (function_exists('auth_send_otp_sms_by_country')) {
        return auth_send_otp_sms_by_country(
            $country,
            $phone,
            $message,
            $referenceId,
            'USER_RESET',
            $otpCode
        );
    }

    return ['ok' => false, 'gateway' => '', 'code' => 'SMS_HELPER_MISSING', 'message' => 'SMS helper missing'];
}

$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? ''));
}
if ($phoneCountry === '') {
    $phoneCountry = 'BD';
}

$phone = user_forgot_send_normalize_phone((string)($body['phone'] ?? ''), $phoneCountry);
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Forgot'));

if ($phone === '') {
    user_forgot_send_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    user_forgot_send_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

$uid = user_forgot_send_find_uid_by_phone($phone, $phoneCountry);

if ($uid === '') {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$userRow = fb_get('USERS/' . $uid);

if (!is_array($userRow)) {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$status = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));
$role = strtoupper(trim((string)($userRow['role'] ?? '')));
$storedPhoneCountry = auth_phone_country_from_user($userRow);
$pricingCountry = auth_pricing_country_from_user($userRow, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));

if ($storedPhoneCountry !== $phoneCountry) {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$otpPhone = normalize_phone_by_country((string)($userRow['phone'] ?? $phone), $storedPhoneCountry);
if ($otpPhone === '') {
    $otpPhone = $phone;
}

if ($status !== 'ACTIVE') {
    user_forgot_send_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
}

if (!user_forgot_send_allowed_role($role)) {
    user_forgot_send_response(false, 'FORBIDDEN', 'Only USER or RETAILER account can use this recovery', [], 403);
}

$now = user_forgot_send_now();
$expiresAt = $now + 300;

$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'UFOTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = 'UFPA' . user_forgot_send_token(16);

$label = $resetType === 'PIN' ? 'PIN reset' : 'password reset';
$purpose = $resetType === 'PIN' ? 'USER_FORGOT_PIN' : 'USER_FORGOT_PASSWORD';

$message = 'Z-Pay Swift ' . $label . ' OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $phone,
    'country' => $storedPhoneCountry,
    'phone_country' => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
    'dial_code' => $storedPhoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164' => $otpPhone,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'purpose' => $purpose,
    'reset_type' => $resetType,
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_forgot_send_mask_phone($otpPhone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$preAuthRow = [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $otpPhone,
    'phone_country' => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'browser_timezone' => auth_request_browser_timezone($body),
    'masked_phone' => user_forgot_send_mask_phone($otpPhone),
    'reset_type' => $resetType,
    'purpose' => $purpose,
    'status' => 'OTP_PENDING',
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    if (function_exists('fb_delete')) {
        @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
        @fb_delete('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
    }

    user_forgot_send_response(false, 'SERVER_ERROR', 'Failed to prepare OTP verification', [], 500);
}

$smsResult = user_forgot_send_sms(
    $storedPhoneCountry,
    $otpPhone,
    $message,
    $otpRequestId,
    $otpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_send_now(),
    ] + $smsPatch);

    @fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_send_now(),
    ]);

    user_forgot_send_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => user_forgot_send_now(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('USER_FORGOT_OTP_SENT', $otpRequestId, 'User forgot OTP sent', [
        'uid' => $uid,
        'phone' => $otpPhone,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => $pricingCountry,
        'reset_type' => $resetType,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
    ]);
}

user_forgot_send_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => user_forgot_send_mask_phone($otpPhone),
    'expires_in_seconds' => 300,
    'reset_type' => $resetType,
    'phone_country' => $storedPhoneCountry,
]);
