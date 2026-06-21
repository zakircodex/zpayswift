<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function admin_resend_mask_phone(string $phone): string
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
$now = now_ts();

if ($preAuthToken === '' || $otpRequestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
}

$preAuthRow = fb_get('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken);

if (!is_array($preAuthRow)) {
    api_response(false, 'PREAUTH_NOT_FOUND', 'Login session expired. Please login again.', [], 404);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));

if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
    api_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));

if ($preAuthStatus !== 'OTP_PENDING') {
    api_response(false, 'OTP_NOT_PENDING', 'OTP is not pending for this login session', [], 400);
}

$preAuthExpiresAt = (int)($preAuthRow['expires_at'] ?? 0);

if ($preAuthExpiresAt <= $now) {
    fb_patch('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    api_response(false, 'PREAUTH_EXPIRED', 'Login session expired. Please login again.', [], 410);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);

if (!is_array($otpRow)) {
    api_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$otpUid = trim((string)($otpRow['uid'] ?? ''));

if ($uid === '' || $otpUid === '' || $otpUid !== $uid) {
    api_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this admin account', [], 400);
}

$otpPurpose = strtoupper(trim((string)($otpRow['purpose'] ?? '')));

if ($otpPurpose !== 'ADMIN_LOGIN') {
    api_response(false, 'OTP_PURPOSE_MISMATCH', 'OTP purpose mismatch', [], 400);
}

if (!empty($otpRow['used'])) {
    api_response(false, 'OTP_ALREADY_USED', 'OTP already used. Please login again.', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));

if (!in_array($otpStatus, ['SENT', 'RESENT'], true)) {
    api_response(false, 'OTP_INVALID_STATUS', 'OTP is not active. Please login again.', [], 400);
}

$user = fb_get('USERS/' . $uid);

if (!is_array($user)) {
    api_response(false, 'USER_NOT_FOUND', 'Admin account not found', [], 404);
}

$userStatus = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
$userRole = strtoupper(trim((string)($user['role'] ?? '')));

if ($userStatus !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'Admin account is not active', [], 403);
}

if ($userRole !== 'ADMIN') {
    api_response(false, 'FORBIDDEN', 'Admin access required', [], 403);
}

$resendState = auth_otp_resend_state($otpRow, $now);
if (empty($resendState['ok'])) {
    api_response(false, (string)$resendState['code'], (string)$resendState['message'], [
        'retry_after_seconds' => (int)($resendState['retry_after_seconds'] ?? 0),
        'resend_count' => (int)($resendState['resend_count'] ?? 0),
        'resend_limit' => (int)($resendState['resend_limit'] ?? auth_otp_resend_limit()),
    ], (int)($resendState['http_status'] ?? 429));
}

$phoneCountry = auth_normalize_country_code((string)($preAuthRow['phone_country'] ?? $otpRow['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = auth_phone_country_from_user($user);
}

$phone = normalize_phone_by_country(
    (string)($preAuthRow['phone'] ?? $otpRow['phone'] ?? $user['phone'] ?? ''),
    $phoneCountry
);

if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', 'Phone number missing for OTP resend', [], 422);
}

$newOtpCode = (string)random_int(100000, 999999);
$expiresAt = $now + 300;

$message = 'Z-Pay Swift admin login OTP is ' . $newOtpCode . '. Valid for 5 minutes. Do not share this code.';

$okOtp = fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'code_hash' => password_hash($newOtpCode, PASSWORD_DEFAULT),
    'status' => 'RESENT',
    'used' => false,
    'resend_count' => (int)($resendState['resend_count'] ?? 0) + 1,
    'resent_at' => $now,
    'expires_at' => $expiresAt,
    'updated_at' => $now,
] + auth_otp_reset_attempts_patch());

$okPre = $okOtp ? fb_patch('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken, [
    'status' => 'OTP_PENDING',
    'resent_at' => $now,
    'expires_at' => $expiresAt,
    'updated_at' => $now,
]) : false;

if (!($okOtp && $okPre)) {
    api_response(false, 'SERVER_ERROR', 'Failed to prepare OTP resend', [], 500);
}

$smsResult = auth_send_otp_sms_by_country(
    $phoneCountry,
    $phone,
    $message,
    $otpRequestId,
    'ADMIN_LOGIN',
    $newOtpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ] + $smsPatch);

    fb_patch('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'SMS_FAILED', 'Failed to resend OTP SMS', [], 500);
}

fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'phone' => $phone,
    'country' => $phoneCountry,
    'phone_country' => $phoneCountry,
    'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164' => $phone,
    'updated_at' => now_ts(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('ADMIN_LOGIN_OTP_RESENT', $otpRequestId, 'Admin login OTP resent', [
        'uid' => $uid,
        'phone' => $phone,
        'phone_country' => $phoneCountry,
        'resend_count' => (int)($resendState['resend_count'] ?? 0) + 1,
        'ip' => client_ip(),
    ]);
}

api_response(true, 'SUCCESS', 'OTP resent successfully', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => admin_resend_mask_phone($phone),
    'expires_in_seconds' => 300,
    'phone_country' => $phoneCountry,
]);
