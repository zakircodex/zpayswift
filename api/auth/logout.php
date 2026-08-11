<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/fcm.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$auth = auth_require_user(false);
$sessionHash = $auth['session_hash'];
$uid = (string)$auth['user']['uid'];
$deviceId = (string)($auth['session']['device_id'] ?? '');
$preserveRequested = in_array(
    strtoupper(trim((string)($body['preserve_trusted_device'] ?? ''))),
    ['1', 'TRUE', 'YES', 'ON'],
    true
);
$trustedDeviceCookie = trim((string)($body['trusted_device_cookie'] ?? ''));
$preserveTrustedDevice = $preserveRequested
    && $trustedDeviceCookie !== ''
    && auth_web_logout_preserves_trusted_device(
        $uid,
        $deviceId,
        $trustedDeviceCookie,
        (array)($auth['user'] ?? [])
    );

fb_patch('USER_SESSIONS/' . $sessionHash, [
    'status' => 'EXPIRED',
    'last_seen_at' => now_ts(),
]);

if (!$preserveTrustedDevice) {
    auth_mark_manual_logout($uid, $deviceId);
}
fcm_deactivate_user_device_tokens($uid, $deviceId);

system_log('LOGOUT', $uid, 'User logout successful', [
    'uid' => $uid,
    'device_id' => $deviceId,
    'trusted_device_preserved' => $preserveTrustedDevice,
    'ip' => client_ip(),
]);

api_response(true, 'LOGOUT_SUCCESS', 'Logout successful', [
    'trusted_device_preserved' => $preserveTrustedDevice,
]);
