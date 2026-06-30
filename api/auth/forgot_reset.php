<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function forgot_reset_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
if ($preAuthToken === '') {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Session expired. Please start again.', [], 410);
}

$now = forgot_reset_now();
$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow) || (int)($preAuthRow['expires_at'] ?? 0) <= $now) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

if (empty($preAuthRow['identity_verified']) || empty($preAuthRow['biometric_verified']) || empty($preAuthRow['otp_verified'])) {
    api_response(false, 'OTP_REQUIRED', 'OTP verification is required before reset.', [], 409);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

$newPassword = (string)($body['new_password'] ?? $body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$newPin = trim((string)($body['new_pin'] ?? $body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));

if ($newPassword === '' || $confirmPassword === '') {
    api_response(false, 'VALIDATION_ERROR', 'New password and confirm password are required.', [], 422);
}

if ($newPassword !== $confirmPassword) {
    api_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match.', [], 422);
}

if (strlen($newPassword) < 6) {
    api_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters.', [], 422);
}

if ($newPin === '' || $confirmPin === '') {
    api_response(false, 'VALIDATION_ERROR', 'New PIN and confirm PIN are required.', [], 422);
}

if ($newPin !== $confirmPin) {
    api_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match.', [], 422);
}

if (!preg_match('/^\d{4,8}$/', $newPin)) {
    api_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits.', [], 422);
}

$update = [
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'pin_hash' => password_hash($newPin, PASSWORD_DEFAULT),
    'password_updated_at' => $now,
    'pin_updated_at' => $now,
    'updated_at' => $now,
];

if (!fb_patch('USERS/' . $uid, $update)) {
    api_response(false, 'SERVER_ERROR', 'Failed to update password and PIN.', [], 500);
}

auth_app_revoke_user_sessions_and_trust($uid);

@fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'status' => 'COMPLETED',
    'completed_at' => $now,
    'updated_at' => $now,
]);

if (function_exists('system_log')) {
    system_log('USER_FORGOT_PASSWORD_PIN_RESET', $uid, 'User password and PIN reset completed', [
        'uid' => $uid,
        'sessions_revoked' => true,
    ]);
}

api_response(true, 'RESET_SUCCESS', 'Password and PIN reset successful.', [
    'uid' => $uid,
    'sessions_revoked' => true,
]);
