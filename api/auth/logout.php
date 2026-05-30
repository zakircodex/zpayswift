<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(false);
$sessionHash = $auth['session_hash'];
$uid = (string)$auth['user']['uid'];

fb_patch('USER_SESSIONS/' . $sessionHash, [
    'status' => 'EXPIRED',
    'last_seen_at' => now_ts(),
]);

system_log('LOGOUT', $uid, 'User logout successful', [
    'uid' => $uid,
    'ip' => client_ip(),
]);

api_response(true, 'SUCCESS', 'Logout successful', []);