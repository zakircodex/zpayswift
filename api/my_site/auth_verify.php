<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
}

$body = $method === 'POST' ? api_read_json_body() : [];
$token = trim((string)($body['token'] ?? $_GET['token'] ?? ''));

if ($token === '') {
    api_response(false, 'TOKEN_REQUIRED', 'Verification token required', [], 422);
}

$tokenHash = zb_token_hash($token);
$path = 'Z_BUILDER_VERIFY_TOKENS/' . $tokenHash;
$row = fb_get($path);

if (!is_array($row) || ($row['status'] ?? '') !== 'PENDING') {
    api_response(false, 'INVALID_TOKEN', 'Invalid verification token', [], 400);
}

$expiresTs = strtotime((string)($row['expires_at'] ?? ''));
if ($expiresTs !== false && $expiresTs < time()) {
    fb_patch($path, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'TOKEN_EXPIRED', 'Verification token expired', [], 400);
}

$ownerId = (string)($row['owner_id'] ?? '');
$owner = fb_get('Z_BUILDER_OWNERS/' . $ownerId);
if (!is_array($owner)) {
    api_response(false, 'OWNER_NOT_FOUND', 'Owner account not found', [], 404);
}

$now = time();
$ownerPatch = [
    'email_verified' => true,
    'status' => 'ACTIVE',
    'verified_at' => zb_now_iso($now),
    'last_login_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
];

fb_patch('Z_BUILDER_OWNERS/' . $ownerId, $ownerPatch);
fb_patch($path, ['status' => 'USED', 'used_at' => zb_now_iso($now)]);
$session = zb_create_session($ownerId);
$owner = array_merge($owner, $ownerPatch);

system_log('Z_BUILDER_OWNER_VERIFIED', $ownerId, 'Z Builder owner verified', []);

api_response(true, 'SUCCESS', 'Owner verified', [
    'owner' => zb_public_owner($owner),
    'session_token' => $session['session_token'],
    'session_expires_at' => $session['expires_at'],
]);
