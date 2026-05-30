<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

$phone = normalize_login_phone($body['phone'] ?? '');
$password = (string)($body['password'] ?? '');
$deviceId = trim((string)($body['device_id'] ?? ''));
$deviceName = trim((string)($body['device_name'] ?? 'Android App'));

if ($phone === '' || $password === '') {
    api_response(false, 'VALIDATION_ERROR', 'Phone and password are required', [], 422);
}

$uid = fb_get('USER_INDEX/PHONE/' . $phone);
if (!is_string($uid) || $uid === '') {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

if (($user['status'] ?? '') !== 'ACTIVE') {
    api_response(false, 'UNAUTHORIZED', 'User account is not active', [], 403);
}

if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

$token = random_token(32);
$hash = session_hash($token);
$sessionId = make_session_id();
$now = now_ts();

$session = [
    'session_id' => $sessionId,
    'uid' => $uid,
    'phone' => (string)$user['phone'],
    'token_last8' => substr($token, -8),
    'device_name' => $deviceName,
    'device_id' => $deviceId,
    'status' => 'ACTIVE',
    'ip' => client_ip(),
    'created_at' => $now,
    'expires_at' => $now + SESSION_TTL_SECONDS,
    'last_seen_at' => $now,
];

if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
    api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
}

fb_patch('USERS/' . $uid, [
    'last_login_at' => $now,
    'updated_at' => $now,
]);

system_log('LOGIN', $uid, 'User login successful', [
    'uid' => $uid,
    'device_id' => $deviceId,
    'ip' => client_ip(),
]);

api_response(true, 'SUCCESS', 'Login successful', [
    'uid' => $uid,
    'name' => (string)$user['name'],
    'phone' => (string)$user['phone'],
    'session_token' => $token,
]);