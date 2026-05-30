<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_resend_mask_phone(string $phone): string
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

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));

if ($preAuthToken === '' || $otpRequestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
}

$preAuthRow = fb_get('AUTH_LOGIN_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow)) {
    api_response(false, 'PREAUTH_NOT_FOUND', 'Login session expired. Please login again.', [], 404);
}

if ((string)($preAuthRow['otp_request_id'] ?? '') !== $otpRequestId) {
    api_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
if ($preAuthStatus !== 'OTP_PENDING') {
    api_response(false, 'OTP_NOT_PENDING', 'OTP is not pending for this login session', [], 400);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$phone = normalize_login_phone((string)($preAuthRow['phone'] ?? ''));

if ($uid === '' || $phone === '') {
    api_response(false, 'PREAUTH_INVALID', 'Login session is invalid', [], 400);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'USER_NOT_FOUND', 'User not found', [], 404);
}

$status = strtoupper(trim((string)($user['status'] ?? '')));
$role = strtoupper(trim((string)($user['role'] ?? '')));

if ($status !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'User account is not active', [], 403);
}

if (!in_array($role, ['USER', 'RETAILER'], true)) {
    api_response(false, 'FORBIDDEN', 'User dashboard access required', [], 403);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    api_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

if ((string)($otpRow['uid'] ?? '') !== $uid) {
    api_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account', [], 400);
}

if ((bool)($otpRow['used'] ?? false)) {
    api_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$currentOtpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
if (in_array($currentOtpStatus, ['VERIFIED', 'EXPIRED', 'CANCELLED'], true)) {
    api_response(false, 'OTP_INVALID_STATUS', 'OTP can no longer be resent', [], 400);
}

$now = now_ts();
$newExpiresAt = $now + 300;
$newOtpCode = (string)random_int(100000, 999999);

$patchOtp = [
    'code_hash' => password_hash($newOtpCode, PASSWORD_DEFAULT),
    'status' => 'RESENT',
    'resent_at' => $now,
    'resend_count' => (int)($otpRow['resend_count'] ?? 0) + 1,
    'expires_at' => $newExpiresAt,
    'updated_at' => $now,
];

$patchPreAuth = [
    'expires_at' => $newExpiresAt,
    'updated_at' => $now,
];

$okOtp = fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, $patchOtp);
$okPre = $okOtp ? fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, $patchPreAuth) : false;

if (!($okOtp && $okPre)) {
    api_response(false, 'SERVER_ERROR', 'Failed to resend OTP', [], 500);
}

$message = 'ZawTopup login OTP is ' . $newOtpCode . '. Valid for 5 minutes. Do not share this code.';
$smsOk = auth_send_otp_sms($phone, $message);

if (!$smsOk) {
    fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'SMS_FAILED', 'Failed to resend OTP SMS', [], 500);
}

if (function_exists('system_log')) {
    system_log('USER_LOGIN_OTP_RESENT', $otpRequestId, 'User login OTP resent', [
        'uid' => $uid,
        'phone' => $phone,
    ]);
}

api_response(true, 'SUCCESS', 'OTP resent successfully', [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => user_resend_mask_phone($phone),
    'expires_in_seconds' => 300,
]);