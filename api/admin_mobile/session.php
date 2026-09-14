<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';

api_require_method('GET');
$auth = admin_mobile_require_session(true);
$session = (array)($auth['session'] ?? []);
$user = (array)($auth['user'] ?? []);

api_response(true, 'ADMIN_MOBILE_SESSION_OK', 'Admin session is active.', [
    'session_expires_at' => max(0, (int)($session['expires_at'] ?? 0)),
    'user' => [
        'uid' => (string)($user['uid'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
    ],
]);
