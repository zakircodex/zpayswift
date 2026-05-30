<?php
declare(strict_types=1);

function auth_clean_string($value): string
{
    $s = trim((string)$value);

    for ($i = 0; $i < 5; $i++) {
        $t = trim($s);
        $wrappedDouble = strlen($t) >= 2 && $t[0] === '"' && substr($t, -1) === '"';
        $wrappedSingle = strlen($t) >= 2 && $t[0] === "'" && substr($t, -1) === "'";

        if ($wrappedDouble || $wrappedSingle) {
            $s = trim(substr($t, 1, -1));
            continue;
        }

        break;
    }

    return $s;
}

function auth_status_value($value): string
{
    return strtoupper(auth_clean_string($value));
}

function session_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function auth_get_session_token_from_request(): string
{
    $token = '';

    if (function_exists('api_get_header')) {
        $token = trim((string)(api_get_header('X-SESSION-TOKEN') ?? ''));
    }

    if ($token === '' && function_exists('api_get_header')) {
        $auth = trim((string)(api_get_header('Authorization') ?? ''));

        if (stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
        }
    }

    return $token;
}

function get_session_by_token(string $token): ?array
{
    $token = trim($token);

    if ($token === '') {
        return null;
    }

    $hash = session_hash($token);
    $session = fb_get('USER_SESSIONS/' . $hash);

    if (!is_array($session)) {
        return null;
    }

    $session['_session_hash'] = $hash;

    return $session;
}

function auth_require_user(bool $touchSession = true): array
{
    $token = auth_get_session_token_from_request();

    if ($token === '') {
        api_response(false, 'SESSION_EXPIRED', 'Missing session token', [], 401);
    }

    $session = get_session_by_token($token);

    if (!$session) {
        api_response(false, 'SESSION_EXPIRED', 'Session not found', [], 401);
    }

    $sessionStatus = auth_status_value($session['status'] ?? '');

    if ($sessionStatus !== 'ACTIVE') {
        api_response(false, 'SESSION_EXPIRED', 'Session is inactive', [], 401);
    }

    $expiresAt = (int)($session['expires_at'] ?? 0);

    if ($expiresAt > 0 && $expiresAt < now_ts()) {
        fb_patch('USER_SESSIONS/' . $session['_session_hash'], [
            'status' => 'EXPIRED',
            'updated_at' => now_ts(),
        ]);

        api_response(false, 'SESSION_EXPIRED', 'Session expired', [], 401);
    }

    $uid = auth_clean_string($session['uid'] ?? '');

    if ($uid === '') {
        api_response(false, 'SESSION_EXPIRED', 'Session UID missing', [], 401);
    }

    $user = fb_get('USERS/' . $uid);

    if (!is_array($user)) {
        api_response(false, 'UNAUTHORIZED', 'User not found', [], 401);
    }

    $userStatus = auth_status_value($user['status'] ?? 'INACTIVE');

    if ($userStatus !== 'ACTIVE') {
        api_response(false, 'UNAUTHORIZED', 'User is not active', [], 401);
    }

    $user['uid'] = $uid;
    $user['status'] = $userStatus;
    $user['role'] = auth_status_value($user['role'] ?? '');

    if ($touchSession) {
        fb_patch('USER_SESSIONS/' . $session['_session_hash'], [
            'last_seen_at' => now_ts(),
            'updated_at' => now_ts(),
        ]);
    }

    return [
        'session_hash' => $session['_session_hash'],
        'session' => $session,
        'user' => $user,
    ];
}

function auth_require_role(string $requiredRole, bool $touchSession = true): array
{
    $auth = auth_require_user($touchSession);
    $user = $auth['user'];

    $role = auth_status_value($user['role'] ?? '');
    $requiredRole = auth_status_value($requiredRole);

    if ($requiredRole === '') {
        api_response(false, 'SERVER_ERROR', 'Required role is empty', [], 500);
    }

    if ($role !== $requiredRole) {
        api_response(false, 'FORBIDDEN', 'You do not have permission to access this resource', [], 403);
    }

    return $auth;
}

function auth_require_admin_session(bool $touchSession = true): array
{
    return auth_require_role('ADMIN', $touchSession);
}