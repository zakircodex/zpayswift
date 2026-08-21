<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();
system_require_user_service_available();

$body = api_read_json_body();
$password = (string)($body['password'] ?? '');

if ($password === '') {
    api_response(false, 'VALIDATION_ERROR', 'Password is required.', [], 422);
}

$account = auth_app_lookup_user_by_body($body);
$uid = (string)$account['uid'];
$user = (array)$account['user'];

auth_app_guard_user_login($user);

if (!auth_app_password_ok($user, $password)) {
    api_response(false, 'WRONG_PASSWORD', 'Password ভুল হয়েছে।', [], 401);
}

$preAuthToken = auth_app_create_preauth($uid, (string)$account['phone'], $body, [
    'phone_country' => (string)$account['phone_country'],
    'pricing_country' => (string)$account['pricing_country'],
    'password_verified' => true,
    'pin_verified' => false,
    'status' => 'PASSWORD_VERIFIED',
]);

api_response(true, 'PASSWORD_VERIFIED', 'Password verified.', [
    'pre_auth_token' => $preAuthToken,
    'user' => auth_app_public_user($uid, $user),
]);
