<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/user_forgot_recovery.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$identityNumber = auth_app_identity_number($body);
$now = function_exists('now_ts') ? (int)now_ts() : time();
$path = 'AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken;

if ($preAuthToken === '' || $identityNumber === '') {
    api_response(false, 'VALIDATION_ERROR', 'Registered identity number is required.', [], 422);
}

$snapshot = fb_get_with_etag($path);
$row = is_array($snapshot['value'] ?? null) ? $snapshot['value'] : null;
$etag = trim((string)($snapshot['etag'] ?? ''));
if (!is_array($row) || $etag === '' || (int)($row['expires_at'] ?? 0) <= $now) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Recovery session expired. Please start again.', [], 410);
}

$status = strtoupper(trim((string)($row['status'] ?? '')));
$deviceId = trim((string)($row['device_id'] ?? ''));
if ($deviceId !== '' && !hash_equals($deviceId, 'USER_WEB')) {
    api_response(false, 'DEVICE_MISMATCH', 'Recovery session does not match this device.', [], 400);
}

if (!empty($row['identity_verified']) && in_array($status, ['IDENTITY_VERIFIED', 'SMS_FAILED', 'OTP_SENDING', 'OTP_PENDING'], true)) {
    api_response(true, 'IDENTITY_VERIFIED', 'Identity verified.', [
        'pre_auth_token' => $preAuthToken,
        'identity_type' => (string)($row['identity_type'] ?? ''),
        'identity_verified' => true,
    ]);
}

$attemptState = user_forgot_identity_attempt_state($row, $now);
if (!empty($attemptState['blocked'])) {
    api_response(false, 'IDENTITY_ATTEMPTS_EXCEEDED', 'Identity verification failed. Please restart account recovery.', [
        'restart_required' => true,
        'attempts_remaining' => 0,
    ], 423);
}
if (!empty($attemptState['rate_limited'])) {
    api_response(false, 'IDENTITY_RATE_LIMITED', 'Please wait briefly before trying again.', [
        'retry_after_seconds' => (int)$attemptState['retry_after_seconds'],
        'attempts_remaining' => (int)$attemptState['attempts_remaining'],
    ], 429);
}
if ($status !== 'PHONE_VERIFIED') {
    api_response(false, 'FORGOT_SESSION_INVALID', 'Recovery verification is incomplete. Please start again.', [], 409);
}

$uid = trim((string)($row['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account recovery is unavailable.', [], 404);
}

$role = strtoupper(trim((string)($user['role'] ?? '')));
$accountStatus = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
if (!in_array($role, ['USER', 'RETAILER'], true) || $accountStatus !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'This account is not eligible for recovery.', [], 403);
}

$identityType = strtoupper(trim((string)($row['identity_type'] ?? '')));
$registeredIdentityType = user_forgot_registered_identity_type($user);
if (!in_array($identityType, ['NID', 'PASSPORT'], true)
    || $registeredIdentityType === ''
    || !hash_equals($identityType, $registeredIdentityType)) {
    api_response(false, 'IDENTITY_NOT_CONFIGURED', 'Identity verification is unavailable for this account. Please contact support.', [], 409);
}

$identityState = user_forgot_identity_match_state($user, $identityNumber, $identityType);
if (empty($identityState['configured'])) {
    api_response(false, 'IDENTITY_NOT_CONFIGURED', 'Identity verification is unavailable for this account. Please contact support.', [], 409);
}

if (empty($identityState['match'])) {
    $failure = user_forgot_identity_failure_patch($row, $now);
    $next = $row;
    foreach (['identity_failed_attempts', 'identity_next_attempt_at', 'status', 'updated_at'] as $key) {
        $next[$key] = $failure[$key];
    }
    $write = fb_put_if_match($path, $next, $etag);
    if (empty($write['ok'])) {
        api_response(false, 'IDENTITY_RETRY_REQUIRED', 'Identity verification could not be completed. Please try again.', [], 409);
    }

    api_response(false, !empty($failure['blocked']) ? 'IDENTITY_ATTEMPTS_EXCEEDED' : 'IDENTITY_VERIFICATION_FAILED',
        !empty($failure['blocked'])
            ? 'Identity verification failed. Please restart account recovery.'
            : 'Identity verification failed. Please check your information.', [
            'restart_required' => !empty($failure['blocked']),
            'attempts_remaining' => (int)$failure['attempts_remaining'],
        ], !empty($failure['blocked']) ? 423 : 403);
}

$next = $row;
$next['identity_verified'] = true;
$next['identity_verified_at'] = $now;
$next['identity_next_attempt_at'] = 0;
$next['status'] = 'IDENTITY_VERIFIED';
$next['updated_at'] = $now;
$next['expires_at'] = $now + 900;
$write = fb_put_if_match($path, $next, $etag);
if (empty($write['ok'])) {
    $latest = fb_get($path);
    if (!is_array($latest) || empty($latest['identity_verified'])) {
        api_response(false, 'IDENTITY_RETRY_REQUIRED', 'Identity verification could not be completed. Please try again.', [], 409);
    }
    $next = $latest;
}

api_response(true, 'IDENTITY_VERIFIED', 'Identity verified.', [
    'pre_auth_token' => $preAuthToken,
    'identity_type' => (string)($next['identity_type'] ?? ''),
    'identity_verified' => true,
]);
