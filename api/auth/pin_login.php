<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$pin = trim((string)($body['pin'] ?? ''));
$deviceId = auth_app_device_id($body);
$deviceName = auth_app_device_name($body);

if ($pin === '') {
    api_response(false, 'VALIDATION_ERROR', 'PIN is required.', [], 422);
}

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

if (!auth_app_pin_ok($user, $pin)) {
    api_response(false, 'WRONG_PIN', 'PIN ভুল হয়েছে।', [], 401);
}

$session = auth_app_complete_quick_login_session(
    $quickLogin,
    $deviceName,
    trim((string)($body['app_version'] ?? ''))
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

api_response(true, 'LOGIN_SUCCESS', 'Login successful', [
    'session_token' => (string)$session['session_token'],
    'user' => auth_app_user_payload($uid, $user),
    'device_trusted' => true,
]);
