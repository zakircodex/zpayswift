<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/user_forgot_recovery.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$resetAuthorizationToken = trim((string)($body['reset_authorization_token'] ?? ''));
$newPassword = (string)($body['new_password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$newPin = trim((string)($body['new_pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));
$now = function_exists('now_ts') ? (int)now_ts() : time();

if ($preAuthToken === '' || $resetAuthorizationToken === '' || !hash_equals($preAuthToken, $resetAuthorizationToken)) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Reset authorization expired. Please start again.', [], 410);
}

$validation = user_forgot_combined_validate_credentials($newPassword, $confirmPassword, $newPin, $confirmPin);
if (empty($validation['ok'])) {
    api_response(false, (string)$validation['code'], (string)$validation['message'], [], 422);
}

$path = 'AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken;
$preAuthState = fb_get_with_etag($path);
$preAuthRow = is_array($preAuthState['value'] ?? null) ? $preAuthState['value'] : null;
$etag = trim((string)($preAuthState['etag'] ?? ''));

if (!is_array($preAuthRow) || $etag === '' || (int)($preAuthRow['expires_at'] ?? 0) <= $now) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$status = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
$resetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? '')));
if ($status === 'COMPLETED') {
    api_response(false, 'RESET_TOKEN_USED', 'This reset authorization has already been used.', [], 409);
}

if ($resetType !== 'PASSWORD_PIN' || empty($preAuthRow['otp_verified'])) {
    api_response(false, 'OTP_REQUIRED', 'OTP verification is required before reset.', [], 409);
}

$storedDeviceId = trim((string)($preAuthRow['device_id'] ?? ''));
$requestDeviceId = auth_app_device_id($body, 'USER_WEB');
if ($storedDeviceId !== '' && $requestDeviceId !== '' && !hash_equals($storedDeviceId, $requestDeviceId)) {
    api_response(false, 'DEVICE_MISMATCH', 'Device mismatch for this reset session.', [], 400);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

$role = strtoupper(trim((string)($user['role'] ?? '')));
$accountStatus = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
if (!in_array($role, ['USER', 'RETAILER'], true) || $accountStatus !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'Account recovery is not allowed.', [], 403);
}

if ($status === 'RESETTING') {
    if (!user_forgot_combined_credentials_match($user, $newPassword, $newPin)) {
        api_response(false, 'RESET_IN_PROGRESS', 'Credential reset is already in progress.', [], 409);
    }

    auth_app_revoke_user_sessions_and_trust($uid);
    @fb_patch($path, [
        'status' => 'COMPLETED',
        'completed_at' => $now,
        'updated_at' => $now,
    ]);
    api_response(true, 'RESET_SUCCESS', 'Password and PIN reset successful.', [
        'sessions_revoked' => true,
        'recovered_completion' => true,
    ]);
}

if ($status !== 'OTP_VERIFIED') {
    api_response(false, 'FORGOT_SESSION_INVALID', 'Forgot verification is incomplete. Please start again.', [], 409);
}

$claim = $preAuthRow;
$claim['status'] = 'RESETTING';
$claim['reset_started_at'] = $now;
$claim['updated_at'] = $now;
$claimResult = fb_put_if_match($path, $claim, $etag);
if (empty($claimResult['ok'])) {
    api_response(false, 'RESET_IN_PROGRESS', 'Credential reset is already in progress.', [], 409);
}

$update = user_forgot_combined_build_update($newPassword, $newPin, $now);
if (!fb_patch('USERS/' . $uid, $update)) {
    @fb_patch($path, [
        'status' => 'OTP_VERIFIED',
        'updated_at' => $now,
        'reset_error_code' => 'CREDENTIAL_WRITE_FAILED',
    ]);
    api_response(false, 'SERVER_ERROR', 'Password and PIN could not be updated. Please try again.', [], 500);
}

auth_app_revoke_user_sessions_and_trust($uid);

$finalized = fb_patch($path, [
    'status' => 'COMPLETED',
    'completed_at' => $now,
    'updated_at' => $now,
]);

if (function_exists('system_log')) {
    system_log('USER_WEB_FORGOT_PASSWORD_PIN_RESET', $uid, 'Web user password and PIN reset completed', [
        'uid' => $uid,
        'sessions_revoked' => true,
        'finalized' => $finalized,
    ]);
}

api_response(true, 'RESET_SUCCESS', 'Password and PIN reset successful.', [
    'sessions_revoked' => true,
    'finalization_pending' => !$finalized,
]);
