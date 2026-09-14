<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/admin_push.php';

api_require_method('POST');
$auth = admin_mobile_require_session(true);
$body = api_read_json_body();
$action = admin_push_clean_code($body['action'] ?? 'REGISTER');
$uid = trim((string)($auth['user']['uid'] ?? ''));
$deviceId = admin_mobile_device_id(true);

if ($action === 'DEACTIVATE') {
    $count = admin_push_deactivate_device($uid, $deviceId);
    api_response(true, 'ADMIN_PUSH_DEACTIVATED', 'Admin notifications disabled for this device.', [
        'deactivated_tokens' => $count,
    ]);
}
if ($action !== 'REGISTER') {
    api_response(false, 'VALIDATION_ERROR', 'Notification action is invalid.', [], 422);
}

$result = admin_push_register_device_token(
    $uid,
    $deviceId,
    trim((string)($body['token'] ?? '')),
    trim((string)($body['app_version'] ?? admin_mobile_header('X-ADMIN-APP-VERSION-NAME')))
);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ADMIN_PUSH_SAVE_FAILED'),
    (string)($result['message'] ?? 'Notification token could not be saved.'),
    ['token_hash' => (string)($result['token_hash'] ?? '')],
    !empty($result['ok']) ? 200 : (int)($result['status'] ?? 500)
);
