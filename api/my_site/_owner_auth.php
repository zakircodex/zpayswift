<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function zb_now_iso(?int $ts = null): string
{
    return date('c', $ts ?? time());
}

function zb_owner_email(string $email): string
{
    return strtolower(trim($email));
}

function zb_email_hash(string $email): string
{
    return hash('sha256', zb_owner_email($email));
}

function zb_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function zb_make_owner_id(): string
{
    return 'ZBO_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function zb_public_owner(array $owner): array
{
    return [
        'owner_id' => (string)($owner['owner_id'] ?? ''),
        'name' => (string)($owner['name'] ?? ''),
        'email' => (string)($owner['email'] ?? ''),
        'email_verified' => (bool)($owner['email_verified'] ?? false),
        'status' => (string)($owner['status'] ?? 'PENDING_VERIFY'),
        'created_at' => $owner['created_at'] ?? null,
        'last_login_at' => $owner['last_login_at'] ?? null,
    ];
}

function zb_current_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'zpayswift.com'));
    return $scheme . '://' . $host;
}

function zb_secure_cookie(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function zb_set_owner_session_cookie(string $token, ?int $expiresTs = null): void
{
    if ($token === '') { return; }
    setcookie('z_builder_owner_session', $token, [
        'expires' => $expiresTs ?? (time() + 30 * 86400),
        'path' => '/',
        'secure' => zb_secure_cookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function zb_clear_owner_session_cookie(): void
{
    setcookie('z_builder_owner_session', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => zb_secure_cookie(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function zb_verify_link(string $token): string
{
    return zb_current_origin() . '/z-builder/auth/verify.html?token=' . rawurlencode($token);
}

function zb_create_verify_row(string $ownerId, string $email, string $purpose = 'VERIFY_EMAIL'): array
{
    $token = random_token(32);
    $now = time();
    $row = [
        'owner_id' => $ownerId,
        'email_hash' => zb_email_hash($email),
        'purpose' => $purpose,
        'status' => 'PENDING',
        'created_at' => zb_now_iso($now),
        'expires_at' => zb_now_iso($now + (30 * 60)),
        'used_at' => null,
        'ip' => client_ip(),
    ];
    fb_put('Z_BUILDER_VERIFY_TOKENS/' . zb_token_hash($token), $row);
    fb_put('Z_BUILDER_EMAIL_OUTBOX/' . date('Y-m-d') . '/' . make_log_id(), [
        'owner_id' => $ownerId,
        'email' => $email,
        'purpose' => $purpose,
        'verify_link' => zb_verify_link($token),
        'status' => 'PENDING_SEND',
        'created_at' => zb_now_iso($now),
    ]);

    return ['token' => $token, 'link' => zb_verify_link($token), 'expires_at' => $row['expires_at']];
}

function zb_create_session(string $ownerId): array
{
    $token = random_token(32);
    $now = time();
    $session = [
        'owner_id' => $ownerId,
        'status' => 'ACTIVE',
        'created_at' => zb_now_iso($now),
        'expires_at' => zb_now_iso($now + (30 * 86400)),
        'last_seen_at' => zb_now_iso($now),
        'ip' => client_ip(),
    ];
    fb_put('Z_BUILDER_OWNER_SESSIONS/' . zb_token_hash($token), $session);
    zb_set_owner_session_cookie($token, $now + (30 * 86400));
    return ['session_token' => $token, 'expires_at' => $session['expires_at']];
}

function zb_session_token_from_request(): string
{
    $header = api_get_header('X-ZBUILDER-SESSION') ?: api_get_header('X-Z-BUILDER-SESSION');
    if (!$header) {
        $auth = api_get_header('Authorization') ?: '';
        if (stripos($auth, 'Bearer ') === 0) {
            $header = trim(substr($auth, 7));
        }
    }
    if (!$header) {
        $header = trim((string)($_COOKIE['z_builder_owner_session'] ?? ''));
    }
    return trim((string)$header);
}

function zb_require_owner_session(): array
{
    $token = zb_session_token_from_request();
    if ($token === '') {
        api_response(false, 'SESSION_REQUIRED', 'Owner session required', [], 401);
    }

    $sessionPath = 'Z_BUILDER_OWNER_SESSIONS/' . zb_token_hash($token);
    $session = fb_get($sessionPath);
    if (!is_array($session) || ($session['status'] ?? '') !== 'ACTIVE') {
        api_response(false, 'INVALID_SESSION', 'Invalid owner session', [], 401);
    }

    $expiresTs = strtotime((string)($session['expires_at'] ?? ''));
    if ($expiresTs !== false && $expiresTs < time()) {
        fb_patch($sessionPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
        zb_clear_owner_session_cookie();
        api_response(false, 'SESSION_EXPIRED', 'Owner session expired', [], 401);
    }

    $ownerId = (string)($session['owner_id'] ?? '');
    $owner = fb_get('Z_BUILDER_OWNERS/' . $ownerId);
    if (!is_array($owner) || ($owner['status'] ?? '') === 'BLOCKED') {
        api_response(false, 'OWNER_NOT_ACTIVE', 'Owner account is not active', [], 403);
    }

    fb_patch($sessionPath, ['last_seen_at' => zb_now_iso()]);
    return ['owner' => $owner, 'session' => $session, 'session_path' => $sessionPath];
}
