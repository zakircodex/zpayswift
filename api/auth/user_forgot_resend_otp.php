<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_forgot_res_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_forgot_res_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_forgot_res_mask_phone(string $phone): string
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

function user_forgot_res_send_sms(string $phone, string $message): bool
{
    if (function_exists('auth_send_otp_sms')) {
        return (bool)auth_send_otp_sms($phone, $message);
    }

    return false;
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$oldOtpRequestId = trim((string)($body['otp_request_id'] ?? $body['request_id'] ?? ''));

if ($preAuthToken === '' || $oldOtpRequestId === '') {
    user_forgot_res_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
}

$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);

if (!is_array($preAuthRow)) {
    user_forgot_res_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));

if ($storedOtpRequestId === '' || $storedOtpRequestId !== $oldOtpRequestId) {
    user_forgot_res_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));

if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
    user_forgot_res_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$phone = trim((string)($preAuthRow['phone'] ?? ''));
$uid = trim((string)($preAuthRow['uid'] ?? ''));
$resetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? 'PASSWORD')));

if ($phone === '' || $uid === '') {
    user_forgot_res_response(false, 'FORGOT_SESSION_INVALID', 'Forgot session is invalid. Please start again.', [], 400);
}

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    $resetType = 'PASSWORD';
}

$now = user_forgot_res_now();
$expiresAt = $now + 300;

$newOtpCode = (string)random_int(100000, 999999);
$newOtpRequestId = 'UFOTP' . strtoupper(bin2hex(random_bytes(6)));

@fb_patch('AUTH_OTP_REQUESTS/' . $oldOtpRequestId, [
    'status' => 'CANCELLED',
    'updated_at' => $now,
]);

$oldOtpRow = fb_get('AUTH_OTP_REQUESTS/' . $oldOtpRequestId);
$oldResendCount = is_array($oldOtpRow) ? (int)($oldOtpRow['resend_count'] ?? 0) : 0;

$purpose = $resetType === 'PIN' ? 'USER_FORGOT_PIN' : 'USER_FORGOT_PASSWORD';

$otpRow = [
    'otp_request_id' => $newOtpRequestId,
    'uid' => $uid,
    'phone' => $phone,
    'purpose' => $purpose,
    'reset_type' => $resetType,
    'code_hash' => password_hash($newOtpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_forgot_res_mask_phone($phone),
    'status' => 'RESENT',
    'used' => false,
    'resend_count' => $oldResendCount + 1,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $newOtpRequestId, $otpRow);

if (!$okOtp) {
    user_forgot_res_response(false, 'SERVER_ERROR', 'Failed to prepare new OTP', [], 500);
}

$okPre = fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'otp_request_id' => $newOtpRequestId,
    'status' => 'OTP_PENDING',
    'updated_at' => $now,
    'expires_at' => $expiresAt,
]);

if (!$okPre) {
    if (function_exists('fb_delete')) {
        @fb_delete('AUTH_OTP_REQUESTS/' . $newOtpRequestId);
    }

    user_forgot_res_response(false, 'SERVER_ERROR', 'Failed to update forgot OTP session', [], 500);
}

$label = $resetType === 'PIN' ? 'PIN reset' : 'password reset';
$message = 'Z-Pay Swift ' . $label . ' OTP is ' . $newOtpCode . '. Valid for 5 minutes. Do not share this code.';

$smsOk = user_forgot_res_send_sms($phone, $message);

if (!$smsOk) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $newOtpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_res_now(),
    ]);

    user_forgot_res_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

if (function_exists('system_log')) {
    system_log('USER_FORGOT_OTP_RESENT', $newOtpRequestId, 'User forgot OTP resent', [
        'uid' => $uid,
        'phone' => $phone,
        'reset_type' => $resetType,
    ]);
}

user_forgot_res_response(true, 'SUCCESS', 'OTP resent successfully', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'otp_request_id' => $newOtpRequestId,
    'request_id' => $newOtpRequestId,
    'masked_phone' => user_forgot_res_mask_phone($phone),
    'expires_in_seconds' => 300,
    'reset_type' => $resetType,
]);