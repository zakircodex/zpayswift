<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();
system_require_user_service_available();

$body = api_read_json_body();

function user_verify_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON'], true);
}

function user_verify_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function user_verify_issue_session(array $user, string $uid, string $deviceId, string $deviceName, array $preAuthRow = []): array
{
    return auth_issue_website_user_session($user, $uid, $deviceId, $deviceName, $preAuthRow);
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));
$trustDevice = user_verify_bool_value($body['trust_device'] ?? true);
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Dashboard'));

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    api_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
}

$preAuthRow = fb_get('AUTH_LOGIN_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow)) {
    api_response(false, 'PREAUTH_NOT_FOUND', 'Login session expired. Please login again.', [], 404);
}

if ((string)($preAuthRow['otp_request_id'] ?? '') !== $otpRequestId) {
    api_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

if (strtoupper(trim((string)($preAuthRow['status'] ?? ''))) !== 'OTP_PENDING') {
    api_response(false, 'OTP_NOT_PENDING', 'OTP is not pending for this login session', [], 400);
}

if ((int)($preAuthRow['expires_at'] ?? 0) <= now_ts()) {
    fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'PREAUTH_EXPIRED', 'Login session expired. Please login again.', [], 410);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$user = fb_get('USERS/' . $uid);

if (!is_array($user)) {
    api_response(false, 'USER_NOT_FOUND', 'User not found', [], 404);
}

$status = strtoupper(trim((string)($user['status'] ?? '')));
$role = strtoupper(trim((string)($user['role'] ?? '')));

if ($status !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'User account is not active', [], 403);
}

if (!user_verify_allowed_role($role)) {
    api_response(false, 'FORBIDDEN', 'User dashboard access required', [], 403);
}

$otpClaim = auth_otp_claim_verification($otpRequestId, 'USER_LOGIN', $uid, $otp, now_ts());
if (empty($otpClaim['ok'])) {
    api_response(
        false,
        (string)($otpClaim['code'] ?? 'OTP_VERIFY_FAILED'),
        (string)($otpClaim['message'] ?? 'OTP verification failed'),
        (array)($otpClaim['data'] ?? []),
        (int)($otpClaim['http_status'] ?? 400)
    );
}

$otpOwner = (string)($otpClaim['owner_token'] ?? '');
$sessionResult = user_verify_issue_session($user, $uid, $deviceId, $deviceName, $preAuthRow);
if (empty($sessionResult['ok'])) {
    auth_otp_release_verification($otpRequestId, $otpOwner, now_ts());
    $sessionCode = (string)($sessionResult['code'] ?? 'SERVER_ERROR');
    api_response(
        false,
        $sessionCode,
        $sessionCode === 'MAINTENANCE'
            ? system_maintenance_message()
            : 'Failed to create session',
        [],
        (int)($sessionResult['http_status'] ?? 500)
    );
}

$sessionToken = (string)($sessionResult['session_token'] ?? '');
$sessionHash = (string)($sessionResult['session_hash'] ?? '');

$now = now_ts();

if (!auth_otp_complete_verification($otpRequestId, $otpOwner, $now)) {
    if ($sessionHash !== '') {
        @fb_delete('USER_SESSIONS/' . $sessionHash);
    }
    auth_otp_release_verification($otpRequestId, $otpOwner, $now);
    api_response(false, 'OTP_VERIFY_CONFLICT', 'OTP verification could not be finalized. Please retry.', [], 409);
}

@fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
    'status' => 'VERIFIED',
    'verified_at' => $now,
    'updated_at' => $now,
]);

$trustedDeviceCookie = null;

if ($trustDevice) {
    $trusted = auth_issue_trusted_device_cookie(
        $uid,
        $deviceId,
        $deviceName,
        (string)($sessionResult['auth_session_epoch'] ?? '')
    );

    if (!empty($trusted['ok'])) {
        $trustedDeviceCookie = [
            'uid' => (string)($trusted['uid'] ?? ''),
            'selector' => (string)($trusted['selector'] ?? ''),
            'token' => (string)($trusted['token'] ?? ''),
            'expires_at' => (int)($trusted['expires_at'] ?? 0),
        ];
    }
}

if (function_exists('system_log')) {
    system_log('USER_LOGIN_OTP_VERIFIED', $otpRequestId, 'User login OTP verified', [
        'uid' => $uid,
        'phone' => (string)($user['phone'] ?? ''),
        'trusted_device' => $trustDevice,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
    ]);
}

api_response(true, 'LOGIN_SUCCESS', 'OTP verified successfully', [
    'session_token' => $sessionToken,
    'trusted_device_cookie' => $trustedDeviceCookie,
    'device_trusted' => true,
    'user' => [
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'phone_country' => auth_phone_country_from_user($user),
        'pricing_country' => auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: [])),
    ],
    'redirect' => 'dashboard',
]);
