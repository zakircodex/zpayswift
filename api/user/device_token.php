<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/fcm.php';

api_require_method('POST');
api_require_app_key();
$auth = auth_require_user(true);
$body = api_read_json_body();

$uid = (string)($auth['user']['uid'] ?? '');
$sessionDeviceId = (string)($auth['session']['device_id'] ?? '');
$action = strtoupper(fcm_clean_text($body['action'] ?? 'REGISTER', 40));
$token = trim((string)($body['token'] ?? ''));
$deviceId = fcm_clean_text($body['device_id'] ?? $sessionDeviceId, 120);
$appVersion = fcm_clean_text($body['app_version'] ?? '', 40);

if ($action === 'DEACTIVATE' || $action === 'DELETE' || $action === 'LOGOUT') {
    if ($token !== '') {
        fcm_deactivate_user_token($uid, $token);
    } else {
        fcm_deactivate_user_device_tokens($uid, $deviceId);
    }
    api_response(true, 'FCM_DEVICE_TOKEN_DEACTIVATED', 'Notification device detached.', []);
}

$result = fcm_register_device_token($uid, $token, $deviceId, $appVersion);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
}

api_response(true, 'FCM_DEVICE_TOKEN_REGISTERED', 'Notification device registered.', [
    'token_hash' => (string)($result['token_hash'] ?? ''),
]);
