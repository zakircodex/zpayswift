<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function sub_verify_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON'], true);
}

function sub_verify_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['SUBADMIN', 'ADMIN'], true);
}

function sub_verify_issue_session(array $user, string $uid, string $deviceId, string $deviceName, array $preAuthRow = []): array
{
    $token = random_token(32);
    $hash = session_hash($token);
    $sessionId = make_session_id();
    $now = now_ts();

    $session = [
        'session_id' => $sessionId,
        'uid' => $uid,
        'phone' => (string)($user['phone'] ?? ''),
        'token_last8' => substr($token, -8),
        'device_name' => $deviceName,
        'device_id' => $deviceId,
        'status' => 'ACTIVE',
        'ip' => client_ip(),
        'created_at' => $now,
        'expires_at' => $now + SESSION_TTL_SECONDS,
        'last_seen_at' => $now,
        'auth_session_epoch' => auth_session_epoch_from_user($user),
    ];

    if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
        return ['ok' => false, 'code' => 'SESSION_WRITE_FAILED'];
    }

    fb_patch('USERS/' . $uid, [
        'last_login_at' => $now,
        'last_login_ip' => (string)($preAuthRow['created_ip'] ?? ''),
        'last_login_ip_country' => (string)($preAuthRow['ip_country'] ?? ''),
        'last_login_user_agent' => (string)($preAuthRow['user_agent'] ?? ''),
        'browser_timezone' => (string)($preAuthRow['browser_timezone'] ?? ($user['browser_timezone'] ?? '')),
        'updated_at' => $now,
    ]);

    return [
        'ok' => true,
        'session_token' => $token,
        'session_hash' => $hash,
    ];
}

function sub_verify_create_trusted_device(string $uid, string $deviceId, string $deviceName): array
{
    $selector = bin2hex(random_bytes(8));
    $rawToken = bin2hex(random_bytes(24));
    $now = now_ts();
    $expiresAt = $now + (60 * 60 * 24 * 30);

    $row = [
        'selector' => $selector,
        'token_hash' => hash('sha256', $rawToken),
        'uid' => $uid,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'created_at' => $now,
        'last_used_at' => $now,
        'expires_at' => $expiresAt,
        'status' => 'ACTIVE',
    ];

    if (!fb_put('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, $row)) {
        return [
            'ok' => false,
            'selector' => '',
            'token' => '',
            'cookie_value' => '',
            'expires_at' => 0,
        ];
    }

    return [
        'ok' => true,
        'selector' => $selector,
        'token' => $rawToken,
        'cookie_value' => $selector . ':' . $rawToken,
        'expires_at' => $expiresAt,
    ];
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));
$trustDevice = sub_verify_bool_value($body['trust_device'] ?? true);
$deviceId = trim((string)($body['device_id'] ?? 'SUBADMIN_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'Subadmin Panel'));

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

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
if ($preAuthStatus !== 'OTP_PENDING') {
    api_response(false, 'OTP_NOT_PENDING', 'OTP is not pending for this login session', [], 400);
}

if ((int)($preAuthRow['expires_at'] ?? 0) < now_ts()) {
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

if (!sub_verify_allowed_role($role)) {
    api_response(false, 'FORBIDDEN', 'Subadmin access required', [], 403);
}

$now = now_ts();
$expectedPurpose = $role === 'ADMIN' ? 'ADMIN_LOGIN' : 'SUBADMIN_LOGIN';
$otpClaim = auth_otp_claim_verification($otpRequestId, $expectedPurpose, $uid, $otp, $now);
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
$sessionResult = sub_verify_issue_session($user, $uid, $deviceId, $deviceName, $preAuthRow);
if (empty($sessionResult['ok'])) {
    auth_otp_release_verification($otpRequestId, $otpOwner, $now);
    api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
}

$sessionToken = (string)($sessionResult['session_token'] ?? '');
$sessionHash = (string)($sessionResult['session_hash'] ?? '');

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

$trustedDeviceSaved = false;
$trustedDeviceCookie = null;
$trustedCookieValue = '';
$trustedCookieExpiresAt = 0;

if ($trustDevice) {
    $trusted = sub_verify_create_trusted_device($uid, $deviceId, $deviceName);

    if (!empty($trusted['ok'])) {
        $trustedDeviceSaved = true;
        $trustedCookieValue = (string)($trusted['cookie_value'] ?? '');
        $trustedCookieExpiresAt = (int)($trusted['expires_at'] ?? 0);

        $trustedDeviceCookie = [
            'selector' => (string)($trusted['selector'] ?? ''),
            'token' => (string)($trusted['token'] ?? ''),
            'cookie_value' => $trustedCookieValue,
            'expires_at' => $trustedCookieExpiresAt,
        ];
    }
}

if (function_exists('system_log')) {
    system_log(
        'SUBADMIN_LOGIN_OTP_VERIFIED',
        $otpRequestId,
        'Subadmin login OTP verified',
        [
            'uid' => $uid,
            'phone' => (string)($user['phone'] ?? ''),
            'trusted_device' => $trustDevice,
            'trusted_device_saved' => $trustedDeviceSaved,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
        ]
    );
}

api_response(true, 'SUCCESS', 'OTP verified successfully', [
    'session_token' => $sessionToken,
    'trusted_device_saved' => $trustedDeviceSaved,
    'trusted_device_cookie' => $trustedDeviceCookie,
    'trusted_device_cookie_value' => $trustedCookieValue,
    'trusted_device_cookie_expires_at' => $trustedCookieExpiresAt,
    'redirect' => 'dashboard',
]);
