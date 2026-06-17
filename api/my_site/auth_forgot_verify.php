<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$forgotToken = trim((string)($body['forgot_token'] ?? ''));
$otpId = trim((string)($body['otp_id'] ?? ''));
$otp = preg_replace('/\D+/', '', (string)($body['otp'] ?? '')) ?? '';
if ($forgotToken === '' || $otpId === '' || strlen($otp) !== 6) { api_response(false, 'INVALID_INPUT', 'Valid OTP input required', [], 422); }

$forgotHash = zb_token_hash($forgotToken);
$forgotPath = 'Z_BUILDER_OWNER_FORGOT/' . $forgotHash;
$forgot = fb_get($forgotPath);
if (!is_array($forgot) || ($forgot['status'] ?? '') !== 'OTP_PENDING') { api_response(false, 'INVALID_FORGOT', 'Invalid reset session', [], 400); }
if (strtotime((string)($forgot['expires_at'] ?? '')) < time()) {
    fb_patch($forgotPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'FORGOT_EXPIRED', 'Reset session expired', [], 400);
}
if ((string)($forgot['otp_id'] ?? '') !== $otpId) { api_response(false, 'OTP_MISMATCH', 'OTP mismatch', [], 400); }

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

$resetToken = 'ZBRESET_' . random_token(24);
$resetHash = zb_token_hash($resetToken);
$now = time();
fb_put('Z_BUILDER_OWNER_PASSWORD_RESETS/' . $resetHash, [
    'owner_id' => (string)$forgot['owner_id'],
    'forgot_hash' => $forgotHash,
    'status' => 'PENDING_PASSWORD',
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (10 * 60)),
    'ip' => client_ip(),
]);
fb_patch($otpPath, ['status' => 'USED', 'used_at' => zb_now_iso($now)]);
fb_patch($forgotPath, ['status' => 'OTP_VERIFIED', 'reset_hash' => $resetHash, 'updated_at' => zb_now_iso($now)]);

api_response(true, 'OTP_VERIFIED', 'OTP verified', [
    'reset_token' => $resetToken,
    'expires_in' => 600,
]);
