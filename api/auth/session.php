<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];

api_response(true, 'SUCCESS', 'Session valid', [
    'uid' => (string)$user['uid'],
    'name' => (string)$user['name'],
    'phone' => (string)$user['phone'],
    'email' => (string)($user['email'] ?? ''),
    'status' => (string)$user['status'],
    'role' => (string)$user['role'],
]);