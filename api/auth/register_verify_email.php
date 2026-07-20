<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';

api_require_method('POST');
api_require_app_key();

$body = reg_app_body();
$registerToken = reg_app_find_preauth_token($body);
$preAuth = reg_app_get_preauth($registerToken);
reg_app_require_otp_verified($preAuth);

$email = strtolower(trim((string)($body['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_response(false, 'VALIDATION_ERROR', 'Valid email is required.', ['field' => 'email'], 422);
}

if (reg_app_email_uid($email) !== '') {
    api_response(false, 'EMAIL_ALREADY_REGISTERED', 'This email is already registered. Please login.', [], 409);
}

$now = reg_app_now();
if (!fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, [
    'email' => $email,
    'email_verified' => true,
    'email_verified_at' => $now,
    'updated_at' => $now,
    'expires_at' => $now + 3600,
])) {
    api_response(false, 'SERVER_ERROR', 'Failed to update email verification state.', [], 500);
}

api_response(true, 'EMAIL_AVAILABLE', 'Email verified for registration.', [
    'email_available' => true,
    'register_token' => $registerToken,
    'pre_auth_token' => $registerToken,
    'email' => $email,
]);
