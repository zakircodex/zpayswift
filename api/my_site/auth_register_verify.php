<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$preToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpId = trim((string)($body['otp_id'] ?? ''));
$otp = preg_replace('/\D+/', '', (string)($body['otp'] ?? '')) ?? '';

if ($preToken === '' || $otpId === '' || strlen($otp) !== 6) {
    api_response(false, 'INVALID_INPUT', 'Valid OTP input required', [], 422);
}

$preHash = zb_token_hash($preToken);
$prePath = 'Z_BUILDER_OWNER_PREAUTH/' . $preHash;
$pre = fb_get($prePath);
if (!is_array($pre) || ($pre['status'] ?? '') !== 'OTP_PENDING') {
    api_response(false, 'INVALID_PREAUTH', 'Invalid registration session', [], 400);
}

if (strtotime((string)($pre['expires_at'] ?? '')) < time()) {
    fb_patch($prePath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'PREAUTH_EXPIRED', 'Registration session expired', [], 400);
}

if ((string)($pre['otp_id'] ?? '') !== $otpId) {
    api_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$otpPath = 'Z_BUILDER_OWNER_OTPS/' . $otpId;
$row = fb_get($otpPath);
if (!is_array($row) || ($row['status'] ?? '') !== 'SENT') {
    api_response(false, 'INVALID_OTP_REQUEST', 'Invalid OTP request', [], 400);
}

if (strtotime((string)($row['expires_at'] ?? '')) < time()) {
    fb_patch($otpPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'OTP_EXPIRED', 'OTP expired', [], 400);
}

$attempts = (int)($row['attempts'] ?? 0);
$max = (int)($row['max_attempts'] ?? 5);
if ($attempts >= $max) {
    api_response(false, 'OTP_LOCKED', 'Too many attempts', [], 429);
}

if (!password_verify($otp, (string)($row['code_hash'] ?? ''))) {
    fb_patch($otpPath, ['attempts' => $attempts + 1, 'last_failed_at' => zb_now_iso()]);
    api_response(false, 'OTP_INVALID', 'Invalid OTP', ['attempts_left' => max(0, $max - $attempts - 1)], 400);
}

$phoneHash = (string)($pre['phone_hash'] ?? '');
$emailHash = (string)($pre['email_hash'] ?? '');
if (fb_get('Z_BUILDER_OWNER_PHONES/' . $phoneHash) !== null || fb_get('Z_BUILDER_OWNER_EMAILS/' . $emailHash) !== null) {
    api_response(false, 'ACCOUNT_EXISTS', 'Account already exists', [], 409);
}

$now = time();
$ownerId = zb_make_owner_id();
$owner = [
    'owner_id' => $ownerId,
    'name' => (string)$pre['name'],
    'phone_country' => 'BD',
    'phone_local' => (string)$pre['phone_local'],
    'phone_e164' => (string)$pre['phone_e164'],
    'phone_hash' => $phoneHash,
    'phone_verified' => true,
    'email' => (string)$pre['email'],
    'email_hash' => $emailHash,
    'email_verified' => false,
    'dob' => (string)$pre['dob'],
    'address' => (string)$pre['address'],
    'status' => 'ACTIVE',
    'login_method' => 'BD_SMS_OTP',
    'created_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
    'last_login_at' => zb_now_iso($now),
    'created_ip' => client_ip(),
];

$ok = fb_put('Z_BUILDER_OWNERS/' . $ownerId, $owner)
    && fb_put('Z_BUILDER_OWNER_PHONES/' . $phoneHash, ['owner_id' => $ownerId, 'phone_e164' => $owner['phone_e164']])
    && fb_put('Z_BUILDER_OWNER_EMAILS/' . $emailHash, ['owner_id' => $ownerId, 'email' => $owner['email']]);

if (!$ok) { api_response(false, 'OWNER_CREATE_FAILED', 'Failed to create account', [], 500); }

fb_patch($otpPath, ['status' => 'USED', 'used_at' => zb_now_iso($now)]);
fb_patch($prePath, ['status' => 'COMPLETED', 'owner_id' => $ownerId, 'completed_at' => zb_now_iso($now)]);
$session = zb_create_session($ownerId);

api_response(true, 'SUCCESS', 'Account created', [
    'owner' => [
        'owner_id' => $ownerId,
        'name' => $owner['name'],
        'phone_local' => $owner['phone_local'],
        'email' => $owner['email'],
        'dob' => $owner['dob'],
        'address' => $owner['address'],
        'status' => $owner['status'],
        'phone_verified' => true,
    ],
    'session_token' => $session['session_token'],
    'session_expires_at' => $session['expires_at'],
]);
