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

$account = auth_app_lookup_user_by_body($body);
$uid = (string)$account['uid'];
$user = (array)$account['user'];

auth_app_guard_user_login($user);

$activeDeviceId = auth_clean_string($user['active_device_id'] ?? $user['ACTIVE_DEVICE_ID'] ?? '');
if ($activeDeviceId === '' || $activeDeviceId !== $deviceId) {
    api_response(false, 'DEVICE_REPLACED', 'এই অ্যাকাউন্ট অন্য ডিভাইসে লগইন করা হয়েছে।', [], 401);
}

if (!auth_app_pin_ok($user, $pin)) {
    api_response(false, 'WRONG_PIN', 'PIN ভুল হয়েছে।', [], 401);
}

$trust = auth_app_repair_device_trust_from_current_session(
    $uid,
    $deviceId,
    $deviceName,
    trim((string)($body['app_version'] ?? ''))
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

$session = auth_app_issue_session($user, $uid, $deviceId, $deviceName, auth_request_metadata($body) + [
    'app_version' => trim((string)($body['app_version'] ?? '')),
]);

api_response(true, 'LOGIN_SUCCESS', 'Login successful', [
    'session_token' => (string)$session['session_token'],
    'user' => auth_app_user_payload($uid, $user),
    'device_trusted' => true,
]);
