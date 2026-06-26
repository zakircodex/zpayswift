<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];
$session = $auth['session'];

api_response(true, 'SESSION_OK', 'Session valid', [
    'uid' => (string)$user['uid'],
    'name' => (string)$user['name'],
    'phone' => (string)$user['phone'],
    'email' => (string)($user['email'] ?? ''),
    'status' => (string)$user['status'],
    'role' => (string)$user['role'],
    'phone_country' => auth_phone_country_from_user($user),
    'pricing_country' => auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . (string)$user['uid']) ?: [])),
    'device_id' => (string)($session['device_id'] ?? ''),
    'device_trusted' => auth_device_is_trusted((string)$user['uid'], (string)($session['device_id'] ?? '')),
]);
