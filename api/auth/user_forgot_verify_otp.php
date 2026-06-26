<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_forgot_verify_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_forgot_verify_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? $body['request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$identityNumber = auth_app_identity_number($body);

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    user_forgot_verify_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    user_forgot_verify_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
}

if ($identityNumber === '') {
    user_forgot_verify_response(false, 'IDENTITY_REQUIRED', 'NID or Passport number is required', [
        'field' => 'nid_or_passport_number',
    ], 422);
}

$now = user_forgot_verify_now();

$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);

if (!is_array($preAuthRow)) {
    user_forgot_verify_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));

if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
    user_forgot_verify_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));

if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
    user_forgot_verify_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$preAuthExpiresAt = (int)($preAuthRow['expires_at'] ?? 0);

if ($preAuthExpiresAt <= $now) {
    @fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    user_forgot_verify_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
}

$storedResetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? $resetType)));

if (!in_array($storedResetType, ['PASSWORD', 'PIN'], true)) {
    $storedResetType = $resetType;
}

if ($storedResetType !== $resetType) {
    user_forgot_verify_response(false, 'RESET_TYPE_MISMATCH', 'Reset type mismatch', [], 400);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));

if ($uid === '') {
    user_forgot_verify_response(false, 'FORGOT_SESSION_INVALID', 'Forgot session is invalid. Please start again.', [], 400);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);

if (!is_array($otpRow)) {
    user_forgot_verify_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

if (trim((string)($otpRow['uid'] ?? '')) !== $uid) {
    user_forgot_verify_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account', [], 400);
}

if (!empty($otpRow['used'])) {
    user_forgot_verify_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));

if (!in_array($otpStatus, ['SENT', 'RESENT', 'LOCKED'], true)) {
    user_forgot_verify_response(false, 'OTP_INVALID_STATUS', 'OTP is not active', [], 400);
}

$otpExpiresAt = (int)($otpRow['expires_at'] ?? 0);

if ($otpExpiresAt <= $now) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    user_forgot_verify_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
}

$codeHash = trim((string)($otpRow['code_hash'] ?? ''));

$lockState = auth_otp_lock_state($otpRow);
if (!empty($lockState['locked'])) {
    user_forgot_verify_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
        'attempts_left' => 0,
    ], 423);
}

if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    $failedState = auth_otp_record_failed_attempt($otpRequestId, $otpRow, $now);

    if (!empty($failedState['locked'])) {
        user_forgot_verify_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
            'attempts_left' => 0,
        ], 423);
    }

    user_forgot_verify_response(false, 'OTP_INVALID', 'Invalid OTP', [
        'attempts_left' => (int)($failedState['attempts_left'] ?? 0),
    ], 400);
}

$userRow = fb_get('USERS/' . $uid);

if (!is_array($userRow)) {
    user_forgot_verify_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$status = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));

if ($status !== 'ACTIVE') {
    user_forgot_verify_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
}

$identityState = auth_app_identity_match_state($userRow, $identityNumber);
if (empty($identityState['configured'])) {
    user_forgot_verify_response(false, 'IDENTITY_NOT_CONFIGURED', 'Identity information is not configured for this account. Please contact support.', [], 409);
}

if (empty($identityState['match'])) {
    user_forgot_verify_response(false, 'IDENTITY_MISMATCH', 'NID or Passport number does not match this account.', [], 403);
}

$update = [
    'updated_at' => $now,
];

if ($resetType === 'PIN') {
    $newPin = trim((string)($body['new_pin'] ?? ''));
    $confirmPin = trim((string)($body['confirm_pin'] ?? ''));

    if ($newPin === '' || $confirmPin === '') {
        user_forgot_verify_response(false, 'VALIDATION_ERROR', 'New PIN and confirm PIN are required', [], 422);
    }

    if ($newPin !== $confirmPin) {
        user_forgot_verify_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match', [], 422);
    }

    if (!preg_match('/^\d{4,8}$/', $newPin)) {
        user_forgot_verify_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits', [], 422);
    }

    $update['pin_hash'] = password_hash($newPin, PASSWORD_DEFAULT);
    $update['pin_updated_at'] = $now;
} else {
    $newPassword = (string)($body['new_password'] ?? '');
    $confirmPassword = (string)($body['confirm_password'] ?? '');

    if ($newPassword === '' || $confirmPassword === '') {
        user_forgot_verify_response(false, 'VALIDATION_ERROR', 'New password and confirm password are required', [], 422);
    }

    if ($newPassword !== $confirmPassword) {
        user_forgot_verify_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match', [], 422);
    }

    if (strlen($newPassword) < 6) {
        user_forgot_verify_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters', [], 422);
    }

    $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $update['password_updated_at'] = $now;
}

$okUser = fb_patch('USERS/' . $uid, $update);

if (!$okUser) {
    user_forgot_verify_response(false, 'SERVER_ERROR', 'Failed to update account credentials', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

@fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'status' => 'COMPLETED',
    'verified_at' => $now,
    'completed_at' => $now,
    'updated_at' => $now,
]);

auth_app_revoke_user_sessions_and_trust($uid);

if (function_exists('system_log')) {
    system_log('USER_FORGOT_RESET_COMPLETED', $otpRequestId, 'User forgot reset completed', [
        'uid' => $uid,
        'reset_type' => $resetType,
        'sessions_revoked' => true,
    ]);
}

user_forgot_verify_response(true, 'SUCCESS', ($resetType === 'PIN' ? 'PIN' : 'Password') . ' reset successful', [
    'uid' => $uid,
    'reset_type' => $resetType,
]);
