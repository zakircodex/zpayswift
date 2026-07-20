<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$documentType = auth_app_identity_type($body);
$identityNumber = auth_app_identity_number($body);

function forgot_identity_require_same_device(array $body, array $preAuthRow): void
{
    $storedDeviceId = trim((string)($preAuthRow['device_id'] ?? ''));
    $requestDeviceId = auth_app_device_id($body, 'ANDROID_FORGOT');
    if ($storedDeviceId !== '' && $requestDeviceId !== '' && $storedDeviceId !== $requestDeviceId) {
        api_response(false, 'DEVICE_MISMATCH', 'Device mismatch for this reset session.', [], 400);
    }
}

if ($preAuthToken === '') {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Session expired. Please start again.', [], 410);
}

if (!in_array($documentType, ['NID', 'PASSPORT'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'document_type NID or PASSPORT is required.', [], 422);
}

if ($identityNumber === '') {
    api_response(false, 'VALIDATION_ERROR', 'NID/Passport number is required.', [], 422);
}

$now = function_exists('now_ts') ? (int)now_ts() : time();
$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow) || (int)($preAuthRow['expires_at'] ?? 0) <= $now) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

if (strtoupper(trim((string)($preAuthRow['status'] ?? ''))) === 'COMPLETED') {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

forgot_identity_require_same_device($body, $preAuthRow);

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

auth_app_guard_user_login($user);

$storedType = strtoupper(trim((string)($user['identity_type'] ?? $user['KYC']['type'] ?? $user['KYC']['document_type'] ?? '')));
if ($storedType !== '' && in_array($storedType, ['NID', 'PASSPORT'], true) && $storedType !== $documentType) {
    api_response(false, 'IDENTITY_MISMATCH', 'Identity information does not match.', [], 403);
}

$identityState = auth_app_identity_match_state($user, $identityNumber);
if (empty($identityState['configured'])) {
    api_response(false, 'IDENTITY_NOT_CONFIGURED', 'Identity information is not configured for this account. Please contact support.', [], 409);
}

if (empty($identityState['match'])) {
    api_response(false, 'IDENTITY_MISMATCH', 'Identity information does not match.', [], 403);
}

$identityHash = auth_app_identity_hash($identityNumber);
$identityLast4 = auth_app_identity_last4($identityNumber);

@fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'identity_verified' => true,
    'identity_verified_at' => $now,
    'identity_type' => $documentType,
    'identity_number_hash' => $identityHash,
    'identity_number_last4' => $identityLast4,
    'status' => 'IDENTITY_VERIFIED',
    'updated_at' => $now,
    'expires_at' => $now + 3600,
]);

api_response(true, 'IDENTITY_VERIFIED', 'Identity verified.', [
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'pre_auth_token' => $preAuthToken,
    'identity_verified' => true,
]);
