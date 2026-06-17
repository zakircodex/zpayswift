<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$resetToken = trim((string)($body['reset_token'] ?? ''));
$password = (string)($body['password'] ?? '');
$confirm = (string)($body['confirm_password'] ?? $body['password_confirm'] ?? '');

if ($resetToken === '') { api_response(false, 'RESET_TOKEN_REQUIRED', 'Reset token required', [], 422); }
if (strlen($password) < 6 || strlen($password) > 72) { api_response(false, 'INVALID_PASSWORD', 'Password must be 6 to 72 characters', [], 422); }
if ($password !== $confirm) { api_response(false, 'PASSWORD_MISMATCH', 'Confirm password does not match', [], 422); }

$resetHash = zb_token_hash($resetToken);
$resetPath = 'Z_BUILDER_OWNER_PASSWORD_RESETS/' . $resetHash;
$reset = fb_get($resetPath);
if (!is_array($reset) || ($reset['status'] ?? '') !== 'PENDING_PASSWORD') { api_response(false, 'INVALID_RESET', 'Invalid reset session', [], 400); }
if (strtotime((string)($reset['expires_at'] ?? '')) < time()) {
    fb_patch($resetPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'RESET_EXPIRED', 'Reset session expired', [], 400);
}

$ownerId = (string)($reset['owner_id'] ?? '');
$owner = fb_get('Z_BUILDER_OWNERS/' . $ownerId);
if (!is_array($owner)) { api_response(false, 'OWNER_NOT_FOUND', 'Account not found', [], 404); }
if (($owner['status'] ?? '') === 'BLOCKED') { api_response(false, 'OWNER_BLOCKED', 'Account blocked', [], 403); }

$now = time();
fb_patch('Z_BUILDER_OWNERS/' . $ownerId, [
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'password_set_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
]);
fb_patch($resetPath, ['status' => 'COMPLETED', 'completed_at' => zb_now_iso($now)]);

api_response(true, 'SUCCESS', 'Password reset successful', []);
