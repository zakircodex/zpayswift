<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';

api_require_method('POST');
api_require_app_key();

$body = reg_app_body();
$registerToken = reg_app_find_preauth_token($body);
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));

if ($registerToken === '' || $otpRequestId === '' || $otp === '') {
    api_response(false, 'VALIDATION_ERROR', 'register_token, otp_request_id and otp are required.', [], 422);
}

$now = reg_app_now();
$preAuth = reg_app_get_preauth($registerToken);
$storedOtpRequestId = trim((string)($preAuth['otp_request_id'] ?? ''));

if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
    api_response(false, 'OTP_MISMATCH', 'OTP request mismatch.', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuth['status'] ?? '')));
if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
    if (!empty($preAuth['otp_verified'])) {
        api_response(true, 'REGISTER_OTP_VERIFIED', 'OTP already verified.', [
            'register_token' => $registerToken,
            'pre_auth_token' => $registerToken,
            'otp_verified' => true,
        ]);
    }

    api_response(false, 'REGISTER_SESSION_EXPIRED', 'Registration session expired. Please start again.', [], 410);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    api_response(false, 'OTP_NOT_FOUND', 'OTP request not found.', [], 404);
}

if (trim((string)($otpRow['register_token'] ?? $otpRow['pre_auth_token'] ?? '')) !== $registerToken) {
    api_response(false, 'OTP_MISMATCH', 'OTP does not match this registration.', [], 400);
}

if (!empty($otpRow['used'])) {
    api_response(false, 'OTP_ALREADY_USED', 'OTP already used.', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
if (!in_array($otpStatus, ['SENT', 'RESENT', 'LOCKED'], true)) {
    api_response(false, 'OTP_INVALID_STATUS', 'OTP is not active.', [], 400);
}

$otpExpiresAt = (int)($otpRow['expires_at'] ?? 0);
if ($otpExpiresAt <= $now) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);
    api_response(false, 'OTP_EXPIRED', 'OTP expired. Please request a new OTP.', [], 410);
}

$lockState = auth_otp_lock_state($otpRow);
if (!empty($lockState['locked'])) {
    api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
        'attempts_left' => 0,
    ], 423);
}

$codeHash = trim((string)($otpRow['code_hash'] ?? ''));
if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    reg_app_otp_attempt_error($otpRequestId, $otpRow, $now);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

if (!fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, [
    'otp_verified' => true,
    'status' => 'OTP_VERIFIED',
    'verified_at' => $now,
    'updated_at' => $now,
    'expires_at' => $now + 3600,
])) {
    api_response(false, 'SERVER_ERROR', 'Failed to update registration state.', [], 500);
}

api_response(true, 'REGISTER_OTP_VERIFIED', 'OTP verified successfully.', [
    'register_token' => $registerToken,
    'pre_auth_token' => $registerToken,
    'otp_verified' => true,
]);
