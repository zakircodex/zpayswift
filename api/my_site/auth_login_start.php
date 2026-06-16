<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$email = zb_owner_email((string)($body['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_response(false, 'INVALID_EMAIL', 'Valid email is required', [], 422);
}

$mapped = fb_get('Z_BUILDER_OWNER_EMAILS/' . zb_email_hash($email));
$ownerId = is_array($mapped) ? (string)($mapped['owner_id'] ?? '') : '';
$owner = $ownerId !== '' ? fb_get('Z_BUILDER_OWNERS/' . $ownerId) : null;

if (!is_array($owner)) {
    api_response(false, 'OWNER_NOT_FOUND', 'Owner account not found', [], 404);
}

if (($owner['status'] ?? '') === 'BLOCKED') {
    api_response(false, 'OWNER_BLOCKED', 'Owner account is blocked', [], 403);
}

$verify = zb_create_verify_row($ownerId, $email, !empty($owner['email_verified']) ? 'LOGIN_LINK' : 'VERIFY_EMAIL');

api_response(true, 'LOGIN_LINK_CREATED', 'Login link created', [
    'owner' => zb_public_owner($owner),
    'verify_url' => $verify['link'],
    'expires_at' => $verify['expires_at'],
]);
