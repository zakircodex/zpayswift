<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();
system_require_user_service_available();

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
    $quickLogin = auth_app_quick_login_context($body);
    if (empty($quickLogin['ok'])) {
        api_response(
            false,
            (string)($quickLogin['code'] ?? 'SESSION_EXPIRED'),
            (string)($quickLogin['message'] ?? 'Session expired. Please sign in again.'),
            [],
            (int)($quickLogin['http_status'] ?? 401)
        );
    }

    $uid = (string)$quickLogin['uid'];
    $user = (array)$quickLogin['user'];
    auth_app_guard_user_login($user);
    $meta = auth_request_metadata($body);
}

$trustedCookieContext = auth_trusted_browser_cookie_context(
    $uid,
    $trustedDeviceCookie,
    $deviceId,
    $user,
    true
);
if (empty($trustedCookieContext['ok'])) {
    api_response(false, 'DEVICE_REPLACED', 'This account is logged in on another device.', [], 401);
}

$meta['app_version'] = trim((string)($body['app_version'] ?? ($meta['app_version'] ?? '')));
$meta['verification_method'] = 'BIOMETRIC';
if ($preAuthToken !== '') {
    $session = auth_app_issue_session($user, $uid, $deviceId, $deviceName, $meta);
} else {
    $session = auth_app_complete_quick_login_session(
        $quickLogin,
        $deviceName,
        (string)$meta['app_version']
    );
    if (empty($session['ok'])) {
        api_response(
            false,
            (string)($session['code'] ?? 'SESSION_EXPIRED'),
            (string)($session['message'] ?? 'Session expired. Please sign in again.'),
            [],
            (int)($session['http_status'] ?? 401)
        );
    }
}

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
