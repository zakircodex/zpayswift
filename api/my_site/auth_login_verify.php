<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$loginToken = trim((string)($body['login_token'] ?? ''));
$otpId = trim((string)($body['otp_id'] ?? ''));
$otp = preg_replace('/\D+/', '', (string)($body['otp'] ?? '')) ?? '';

if ($loginToken === '' || $otpId === '' || strlen($otp) !== 6) {
    api_response(false, 'INVALID_INPUT', 'Valid OTP input required', [], 422);
}

$loginHash = zb_token_hash($loginToken);
$loginPath = 'Z_BUILDER_OWNER_LOGIN/' . $loginHash;
$login = fb_get($loginPath);
if (!is_array($login) || ($login['status'] ?? '') !== 'OTP_PENDING') {
    api_response(false, 'INVALID_LOGIN', 'Invalid login session', [], 400);
}

if (strtotime((string)($login['expires_at'] ?? '')) < time()) {
    fb_patch($loginPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'LOGIN_EXPIRED', 'Login session expired', [], 400);
}

if ((string)($login['otp_id'] ?? '') !== $otpId) { api_response(false, 'OTP_MISMATCH', 'OTP mismatch', [], 400); }

$otpPath = 'Z_BUILDER_OWNER_OTPS/' . $otpId;
$row = fb_get($otpPath);
if (!is_array($row) || ($row['status'] ?? '') !== 'SENT') { api_response(false, 'INVALID_OTP_REQUEST', 'Invalid OTP request', [], 400); }
if (strtotime((string)($row['expires_at'] ?? '')) < time()) {
    fb_patch($otpPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'OTP_EXPIRED', 'OTP expired', [], 400);
}

$attempts = (int)($row['attempts'] ?? 0);
$max = (int)($row['max_attempts'] ?? 5);
if ($attempts >= $max) { api_response(false, 'OTP_LOCKED', 'Too many attempts', [], 429); }
if (!password_verify($otp, (string)($row['code_hash'] ?? ''))) {
    fb_patch($otpPath, ['attempts' => $attempts + 1, 'last_failed_at' => zb_now_iso()]);
    api_response(false, 'OTP_INVALID', 'Invalid OTP', ['attempts_left' => max(0, $max - $attempts - 1)], 400);
}

$ownerId = (string)($login['owner_id'] ?? '');
$owner = fb_get('Z_BUILDER_OWNERS/' . $ownerId);
if (!is_array($owner) || ($owner['status'] ?? '') !== 'ACTIVE') { api_response(false, 'OWNER_NOT_ACTIVE', 'Account not active', [], 403); }

$now = time();
fb_patch($otpPath, ['status' => 'USED', 'used_at' => zb_now_iso($now)]);
fb_patch($loginPath, ['status' => 'COMPLETED', 'completed_at' => zb_now_iso($now)]);
fb_patch('Z_BUILDER_OWNERS/' . $ownerId, ['last_login_at' => zb_now_iso($now), 'updated_at' => zb_now_iso($now)]);
$session = zb_create_session($ownerId);

api_response(true, 'SUCCESS', 'Logged in', [
    'owner' => [
        'owner_id' => $ownerId,
        'name' => (string)($owner['name'] ?? ''),
        'phone_local' => (string)($owner['phone_local'] ?? ''),
        'email' => (string)($owner['email'] ?? ''),
        'dob' => (string)($owner['dob'] ?? ''),
        'address' => (string)($owner['address'] ?? ''),
        'status' => (string)($owner['status'] ?? ''),
        'phone_verified' => (bool)($owner['phone_verified'] ?? false),
    ],
    'session_token' => $session['session_token'],
    'session_expires_at' => $session['expires_at'],
]);
