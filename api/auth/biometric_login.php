<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

function biometric_login_has_valid_trusted_cookie(string $uid, string $cookieValue): bool
{
    $cookieValue = trim($cookieValue);
    if ($uid === '' || $cookieValue === '' || strpos($cookieValue, ':') === false) {
        return false;
    }

    [$selector, $token] = explode(':', $cookieValue, 2);
    $selector = trim($selector);
    $token = trim($token);

    if ($selector === '' || $token === '') {
        return false;
    }

    $row = fb_get('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector);
    if (!is_array($row)) {
        return false;
    }

    $storedHash = trim((string)($row['token_hash'] ?? ''));
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    $expiresAt = (int)($row['expires_at'] ?? 0);

    if ($storedHash === '' || $status !== 'ACTIVE' || $expiresAt < now_ts()) {
        return false;
    }

    if (!hash_equals($storedHash, hash('sha256', $token))) {
        return false;
    }

    @fb_patch('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, [
        'last_used_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);

    return true;
}

$body = api_read_json_body();
$deviceId = auth_app_device_id($body);
$deviceName = auth_app_device_name($body);
$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$trustedDeviceCookie = trim((string)($body['trusted_device_cookie'] ?? ''));

if (!auth_app_bool($body['biometric_verified'] ?? false)) {
    api_response(false, 'BIOMETRIC_REQUIRED', 'Fingerprint verification required.', [], 422);
}

if ($deviceId === '') {
    api_response(false, 'DEVICE_REQUIRED', 'Device verification required.', [], 422);
}

if ($preAuthToken !== '') {
    $preAuthRow = auth_app_get_valid_preauth($preAuthToken);

    if (empty($preAuthRow['password_verified'])) {
        api_response(false, 'PASSWORD_REQUIRED', 'Password verification required first.', [], 400);
    }

    if ((string)($preAuthRow['device_id'] ?? '') !== '' && (string)($preAuthRow['device_id'] ?? '') !== $deviceId) {
        api_response(false, 'DEVICE_MISMATCH', 'Device mismatch for this login verification.', [], 400);
    }

    $account = auth_app_preauth_user($preAuthRow);
    $uid = (string)$account['uid'];
    $user = (array)$account['user'];
    $meta = $preAuthRow;
} else {
    $account = auth_app_lookup_user_by_body($body);
    $uid = (string)$account['uid'];
    $user = (array)$account['user'];
    auth_app_guard_user_login($user);
    $meta = auth_request_metadata($body);
}

if (!biometric_login_has_valid_trusted_cookie($uid, $trustedDeviceCookie)) {
    api_response(false, 'DEVICE_REPLACED', 'This account is logged in on another device.', [], 401);
}

$meta['app_version'] = trim((string)($body['app_version'] ?? ($meta['app_version'] ?? '')));
$meta['verification_method'] = 'BIOMETRIC';
$trust = auth_app_repair_device_trust_from_current_session(
    $uid,
    $deviceId,
    $deviceName,
    (string)$meta['app_version']
);
if (empty($trust['ok'])) {
    api_response(
        false,
        (string)($trust['code'] ?? 'SESSION_EXPIRED'),
        (string)($trust['message'] ?? 'Session expired. Please sign in again.'),
        [],
        (int)($trust['http_status'] ?? 401)
    );
}

$session = auth_app_issue_session($user, $uid, $deviceId, $deviceName, $meta);

if ($preAuthToken !== '') {
    @fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
        'biometric_verified' => true,
        'verification_method' => 'BIOMETRIC',
        'status' => 'VERIFIED',
        'verified_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);
}

api_response(true, 'LOGIN_SUCCESS', 'Login successful', [
    'session_token' => (string)$session['session_token'],
    'user' => auth_app_user_payload($uid, $user),
    'device_trusted' => true,
    'verification_method' => 'BIOMETRIC',
]);
