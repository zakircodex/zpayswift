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

function user_forgot_send_normalize_phone(string $phone): string
{
    if (function_exists('normalize_login_phone')) {
        return (string)normalize_login_phone($phone);
    }

    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function user_forgot_send_mask_phone(string $phone): string
{
    $phone = user_forgot_send_normalize_phone($phone);
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function user_forgot_send_find_uid_by_phone(string $phone): string
{
    $phone = user_forgot_send_normalize_phone($phone);
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

function user_forgot_send_sms(string $phone, string $message): bool
{
    if (function_exists('auth_send_otp_sms')) {
        return (bool)auth_send_otp_sms($phone, $message);
    }

    return false;
}

$phone = user_forgot_send_normalize_phone((string)($body['phone'] ?? ''));
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Forgot'));

if ($phone === '') {
    user_forgot_send_response(false, 'VALIDATION_ERROR', 'Phone is required', [], 422);
}

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    user_forgot_send_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

$uid = user_forgot_send_find_uid_by_phone($phone);

if ($uid === '') {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$userRow = fb_get('USERS/' . $uid);

if (!is_array($userRow)) {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$status = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));
$role = strtoupper(trim((string)($userRow['role'] ?? '')));

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
    'purpose' => $purpose,
    'reset_type' => $resetType,
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_forgot_send_mask_phone($phone),
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
    'phone' => $phone,
    'masked_phone' => user_forgot_send_mask_phone($phone),
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

$smsOk = user_forgot_send_sms($phone, $message);

if (!$smsOk) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_send_now(),
    ]);

    @fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_send_now(),
    ]);

    user_forgot_send_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

if (function_exists('system_log')) {
    system_log('USER_FORGOT_OTP_SENT', $otpRequestId, 'User forgot OTP sent', [
        'uid' => $uid,
        'phone' => $phone,
        'reset_type' => $resetType,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
    ]);
}

user_forgot_send_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => user_forgot_send_mask_phone($phone),
    'expires_in_seconds' => 300,
    'reset_type' => $resetType,
]);