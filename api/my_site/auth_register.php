<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$name = trim((string)($body['name'] ?? ''));
$email = zb_owner_email((string)($body['email'] ?? ''));

if (strlen($name) < 2 || strlen($name) > 80) {
    api_response(false, 'INVALID_NAME', 'Name must be 2 to 80 characters', [], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_response(false, 'INVALID_EMAIL', 'Valid email is required', [], 422);
}

$emailHash = zb_email_hash($email);
$existingMap = fb_get('Z_BUILDER_OWNER_EMAILS/' . $emailHash);
$now = time();

if (is_array($existingMap) && !empty($existingMap['owner_id'])) {
    $ownerId = (string)$existingMap['owner_id'];
    $owner = fb_get('Z_BUILDER_OWNERS/' . $ownerId);
    if (is_array($owner) && !empty($owner['email_verified'])) {
        api_response(false, 'EMAIL_ALREADY_REGISTERED', 'Email already registered', ['owner' => zb_public_owner($owner)], 409);
    }
    if (is_array($owner)) {
        fb_patch('Z_BUILDER_OWNERS/' . $ownerId, ['name' => $name, 'updated_at' => zb_now_iso($now)]);
        $verify = zb_create_verify_row($ownerId, $email, 'VERIFY_EMAIL');
        $owner['name'] = $name;
        api_response(true, 'VERIFY_EMAIL_SENT', 'Verification created', ['owner' => zb_public_owner($owner), 'verify_url' => $verify['link'], 'expires_at' => $verify['expires_at']]);
    }
}

$ownerId = zb_make_owner_id();
$owner = [
    'owner_id' => $ownerId,
    'name' => $name,
    'email' => $email,
    'email_hash' => $emailHash,
    'email_verified' => false,
    'status' => 'PENDING_VERIFY',
    'login_method' => 'EMAIL_LINK',
    'created_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
    'created_ip' => client_ip(),
];

$ok = fb_put('Z_BUILDER_OWNERS/' . $ownerId, $owner)
    && fb_put('Z_BUILDER_OWNER_EMAILS/' . $emailHash, ['owner_id' => $ownerId, 'email' => $email, 'created_at' => zb_now_iso($now)]);

if (!$ok) {
    api_response(false, 'OWNER_CREATE_FAILED', 'Failed to create owner account', [], 500);
}

$verify = zb_create_verify_row($ownerId, $email, 'VERIFY_EMAIL');
system_log('Z_BUILDER_OWNER_REGISTER', $ownerId, 'Z Builder owner registered', ['email_hash' => $emailHash]);

api_response(true, 'VERIFY_EMAIL_SENT', 'Verification created', ['owner' => zb_public_owner($owner), 'verify_url' => $verify['link'], 'expires_at' => $verify['expires_at']]);
