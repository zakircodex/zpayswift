<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';

api_require_method('POST');
$auth = admin_mobile_require_session(false);
$token = admin_mobile_current_token();
$deviceId = admin_mobile_device_id(true);
$result = admin_mobile_internal_request('POST', 'auth/logout.php', [], $token, $deviceId);

$uid = trim((string)($auth['user']['uid'] ?? ''));
if ($uid !== '') {
    fb_patch('ADMIN_MOBILE_DEVICES/' . $uid . '/' . auth_admin_mobile_device_key($deviceId), [
        'last_logout_at' => now_ts(),
    ]);
}

admin_mobile_emit_internal($result);
