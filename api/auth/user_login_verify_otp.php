<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

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

function user_verify_issue_session(array $user, string $uid, string $deviceId, string $deviceName, array $preAuthRow = []): string
{
    $token = random_token(32);
    $hash = session_hash($token);
    $sessionId = make_session_id();
    $now = now_ts();

    $session = [
        'session_id'   => $sessionId,
        'uid'          => $uid,
        'phone'        => (string)($user['phone'] ?? ''),
        'token_last8'  => substr($token, -8),
        'device_name'  => $deviceName,
        'device_id'    => $deviceId,
        'status'       => 'ACTIVE',
        'ip'           => client_ip(),
        'created_at'   => $now,
        'expires_at'   => $now + SESSION_TTL_SECONDS,
        'last_seen_at' => $now,
        'auth_session_epoch' => auth_session_epoch_from_user($user),
    ];

    if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
        api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
    }

    fb_patch('USERS/' . $uid, [
        'last_login_at' => $now,
        'last_login_ip' => (string)($preAuthRow['created_ip'] ?? ''),
        'last_login_ip_country' => (string)($preAuthRow['ip_country'] ?? ''),
        'last_login_user_agent' => (string)($preAuthRow['user_agent'] ?? ''),
        'browser_timezone' => (string)($preAuthRow['browser_timezone'] ?? ($user['browser_timezone'] ?? '')),
        'updated_at'    => $now,
    ]);

    auth_activate_user_device(
        $uid,
        $deviceId,
        $deviceName,
        (string)($preAuthRow['app_version'] ?? ''),
        $hash
    );

    return $token;
}

function user_verify_create_trusted_device(string $uid, string $deviceId, string $deviceName): array
{
    $selector = bin2hex(random_bytes(8));
    $rawToken = bin2hex(random_bytes(24));
    $now = now_ts();
    $expiresAt = $now + (60 * 60 * 24 * 30);

    $row = [
        'selector'     => $selector,
        'token_hash'   => hash('sha256', $rawToken),
        'uid'          => $uid,
        'device_id'    => $deviceId,
        'device_name'  => $deviceName,
        'created_at'   => $now,
        'updated_at'   => $now,
        'last_used_at' => $now,
        'expires_at'   => $expiresAt,
        'trusted'      => true,
        'otp_verified' => true,
        'manual_logout' => false,
        'revoked'      => false,
        'status'       => 'ACTIVE',
    ];

    if (!fb_put('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, $row)) {
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

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    api_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

if ((string)($otpRow['uid'] ?? '') !== (string)($preAuthRow['uid'] ?? '')) {
    api_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account', [], 400);
}

if ((bool)($otpRow['used'] ?? false)) {
    api_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
if (!in_array($otpStatus, ['SENT', 'RESENT', 'LOCKED'], true)) {
    api_response(false, 'OTP_INVALID_STATUS', 'OTP is not active', [], 400);
}

if ((int)($otpRow['expires_at'] ?? 0) <= now_ts()) {
    fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'OTP_EXPIRED', 'OTP expired', [], 410);
}

$codeHash = (string)($otpRow['code_hash'] ?? '');

$lockState = auth_otp_lock_state($otpRow);
if (!empty($lockState['locked'])) {
    api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
        'attempts_left' => 0,
    ], 423);
}

if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    $failedState = auth_otp_record_failed_attempt($otpRequestId, $otpRow, now_ts());

    if (!empty($failedState['locked'])) {
        api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
            'attempts_left' => 0,
        ], 423);
    }

    api_response(false, 'OTP_INVALID', 'Invalid OTP', [
        'attempts_left' => (int)($failedState['attempts_left'] ?? 0),
    ], 400);
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

$sessionToken = user_verify_issue_session($user, $uid, $deviceId, $deviceName, $preAuthRow);

$now = now_ts();

fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
    'status' => 'VERIFIED',
    'verified_at' => $now,
    'updated_at' => $now,
]);

$trustedDeviceCookie = null;

if ($trustDevice) {
    $trusted = user_verify_create_trusted_device($uid, $deviceId, $deviceName);

    if (!empty($trusted['ok'])) {
        $trustedDeviceCookie = [
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
