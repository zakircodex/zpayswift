<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$identityNumber = auth_app_identity_number($body);

if ($preAuthToken === '' || $identityNumber === '') {
    api_response(false, 'VALIDATION_ERROR', 'pre_auth_token and NID/Passport number are required.', [], 422);
}

$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow) || (int)($preAuthRow['expires_at'] ?? 0) <= now_ts()) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

$identityState = auth_app_identity_match_state($user, $identityNumber);
if (empty($identityState['configured'])) {
    api_response(false, 'IDENTITY_NOT_CONFIGURED', 'Identity information is not configured for this account. Please contact support.', [], 409);
}

if (empty($identityState['match'])) {
    api_response(false, 'IDENTITY_MISMATCH', 'NID or Passport number does not match this account.', [], 403);
}

@fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'identity_verified' => true,
    'identity_verified_at' => now_ts(),
    'updated_at' => now_ts(),
]);

api_response(true, 'IDENTITY_VERIFIED', 'Identity verified.', [
    'forgot_token' => $preAuthToken,
]);
