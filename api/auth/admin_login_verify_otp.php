<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function admin_verify_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON'], true);
}

function admin_verify_issue_session(array $user, string $uid, string $deviceId, string $deviceName): string
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
    ];

    if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
        api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
    }

    fb_patch('USERS/' . $uid, [
        'last_login_at' => $now,
        'updated_at' => $now,
    ]);

    return $token;
}

function admin_verify_create_trusted_device(string $uid, string $deviceId, string $deviceName): array
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
        'status' => 'ACTIVE',
        'created_at' => $now,
        'last_used_at' => $now,
        'expires_at' => $expiresAt,
        'updated_at' => $now,
    ];

    $ok = fb_put('AUTH_ADMIN_TRUSTED_DEVICES/' . $uid . '/' . $selector, $row);

    if (!$ok) {
        return [
            'ok' => false,
            'selector' => '',
            'token' => '',
            'expires_at' => 0,
        ];
    }

    return [
        'ok' => true,
        'selector' => $selector,
        'token' => $rawToken,
        'expires_at' => $expiresAt,
    ];
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));
$trustDevice = admin_verify_bool_value($body['trust_device'] ?? true);
$deviceId = trim((string)($body['device_id'] ?? 'ADMIN_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'Admin Dashboard'));
$now = now_ts();

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    api_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
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
    api_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));

if (!in_array($otpStatus, ['SENT', 'RESENT'], true)) {
    api_response(false, 'OTP_INVALID_STATUS', 'OTP is not active', [], 400);
}

$otpExpiresAt = (int)($otpRow['expires_at'] ?? 0);

if ($otpExpiresAt <= $now) {
    fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    api_response(false, 'OTP_EXPIRED', 'OTP expired', [], 410);
}

$codeHash = trim((string)($otpRow['code_hash'] ?? ''));

if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'failed_attempt_at' => $now,
        'updated_at' => $now,
    ]);

    api_response(false, 'OTP_INVALID', 'Invalid OTP', [], 400);
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

$sessionToken = admin_verify_issue_session($user, $uid, $deviceId, $deviceName);

fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

fb_patch('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken, [
    'status' => 'VERIFIED',
    'verified_at' => $now,
    'updated_at' => $now,
]);

$trustedDeviceCookie = null;

if ($trustDevice) {
    $trusted = admin_verify_create_trusted_device($uid, $deviceId, $deviceName);

    if (!empty($trusted['ok'])) {
        $trustedDeviceCookie = [
            'selector' => (string)($trusted['selector'] ?? ''),
            'token' => (string)($trusted['token'] ?? ''),
            'expires_at' => (int)($trusted['expires_at'] ?? 0),
        ];
    }
}

if (function_exists('system_log')) {
    system_log('ADMIN_LOGIN_OTP_VERIFIED', $otpRequestId, 'Admin login OTP verified', [
        'uid' => $uid,
        'phone' => (string)($user['phone'] ?? ''),
        'trusted_device' => $trustDevice,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'ip' => client_ip(),
    ]);
}

api_response(true, 'SUCCESS', 'OTP verified successfully', [
    'session_token' => $sessionToken,
    'trusted_device_cookie' => $trustedDeviceCookie,
    'redirect' => 'dashboard',
    'uid' => $uid,
    'name' => (string)($user['name'] ?? ''),
    'phone' => (string)($user['phone'] ?? ''),
    'role' => $userRole,
]);