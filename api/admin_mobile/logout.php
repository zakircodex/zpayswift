<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/admin_push.php';

api_require_method('POST');
$auth = admin_mobile_require_session(false);
$uid = trim((string)($auth['user']['uid'] ?? ''));
$deviceId = admin_mobile_device_id(true);
$sessionHash = trim((string)($auth['session_hash'] ?? ''));

if ($sessionHash !== '') {
    fb_patch('USER_SESSIONS/' . $sessionHash, [
        'status' => 'EXPIRED',
        'last_seen_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);
}
if ($uid !== '') {
    admin_push_deactivate_device($uid, $deviceId);
    auth_mark_manual_logout($uid, $deviceId);
    fb_patch('ADMIN_MOBILE_DEVICES/' . $uid . '/' . auth_admin_mobile_device_key($deviceId), [
        'last_logout_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);
}

if (function_exists('system_log')) {
    system_log('ADMIN_MOBILE_LOGOUT', $uid, 'Admin mobile logout successful', [
        'uid' => $uid,
        'device_id_hash' => hash('sha256', $deviceId),
    ]);
}

api_response(true, 'LOGOUT_SUCCESS', 'Logout successful.');
