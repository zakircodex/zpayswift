<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

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

function auth_otp_max_attempts(): int
{
    $max = defined('OTP_MAX_ATTEMPTS') ? (int)OTP_MAX_ATTEMPTS : 5;
    return max(1, min(20, $max));
}

function auth_otp_resend_limit(): int
{
    $limit = defined('OTP_RESEND_LIMIT') ? (int)OTP_RESEND_LIMIT : 5;
    return max(1, min(20, $limit));
}

function auth_otp_resend_cooldown_seconds(): int
{
    $seconds = defined('OTP_RESEND_COOLDOWN_SECONDS') ? (int)OTP_RESEND_COOLDOWN_SECONDS : 60;
    return max(0, min(3600, $seconds));
}

function auth_otp_send_limit_per_hour(): int
{
    $limit = defined('OTP_SEND_LIMIT_PER_HOUR') ? (int)OTP_SEND_LIMIT_PER_HOUR : 12;
    return max(1, min(120, $limit));
}

function auth_otp_lock_state(array $otpRow): array
{
    $max = (int)($otpRow['max_attempts'] ?? auth_otp_max_attempts());
    $max = max(1, min(20, $max));
    $attempts = max(0, (int)($otpRow['attempts'] ?? 0));
    $status = auth_status_value($otpRow['status'] ?? '');
    $locked = $status === 'LOCKED' || $attempts >= $max;

    return [
        'locked' => $locked,
        'attempts' => $attempts,
        'max_attempts' => $max,
        'attempts_left' => max(0, $max - $attempts),
    ];
}

function auth_otp_record_failed_attempt(string $otpRequestId, array $otpRow, ?int $now = null): array
{
    $now = $now ?? now_ts();
    $state = auth_otp_lock_state($otpRow);
    $attempts = (int)$state['attempts'] + 1;
    $max = (int)$state['max_attempts'];
    $locked = $attempts >= $max;

    $patch = [
        'attempts' => $attempts,
        'max_attempts' => $max,
        'failed_attempt_at' => $now,
        'updated_at' => $now,
    ];

    if ($locked) {
        $patch['status'] = 'LOCKED';
        $patch['locked_at'] = $now;
    }

    if ($otpRequestId !== '') {
        @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, $patch);
    }

    return [
        'locked' => $locked,
        'attempts' => $attempts,
        'max_attempts' => $max,
        'attempts_left' => max(0, $max - $attempts),
    ];
}

function auth_otp_resend_state(array $otpRow, ?int $now = null): array
{
    $now = $now ?? now_ts();
    $limit = auth_otp_resend_limit();
    $count = max(0, (int)($otpRow['resend_count'] ?? 0));

    if ($count >= $limit) {
        return [
            'ok' => false,
            'code' => 'RESEND_LIMIT_REACHED',
            'message' => 'OTP resend limit reached. Please start again.',
            'http_status' => 429,
            'resend_count' => $count,
            'resend_limit' => $limit,
        ];
    }

    $cooldown = auth_otp_resend_cooldown_seconds();
    $lastSentAt = (int)($otpRow['resent_at'] ?? $otpRow['created_at'] ?? 0);
    $wait = $cooldown > 0 && $lastSentAt > 0 ? ($lastSentAt + $cooldown - $now) : 0;

    if ($wait > 0) {
        return [
            'ok' => false,
            'code' => 'OTP_RESEND_COOLDOWN',
            'message' => 'Please wait before requesting another OTP.',
            'http_status' => 429,
            'retry_after_seconds' => $wait,
            'resend_count' => $count,
            'resend_limit' => $limit,
        ];
    }

    return [
        'ok' => true,
        'resend_count' => $count,
        'resend_limit' => $limit,
    ];
}

function auth_otp_send_rate_key(string $purpose, string $phone): string
{
    $purpose = strtoupper(trim($purpose));
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
    return hash('sha256', $purpose . '|' . $phone);
}

function auth_otp_send_rate_path(string $purpose, string $phone): string
{
    $purpose = strtoupper(trim($purpose));
    if ($purpose === '') {
        $purpose = 'GENERAL';
    }

    return 'AUTH_OTP_RATE_LIMIT/' . $purpose . '/' . auth_otp_send_rate_key($purpose, $phone);
}

function auth_otp_send_rate_state(string $purpose, string $phone, ?int $now = null): array
{
    $now = $now ?? now_ts();
    $path = auth_otp_send_rate_path($purpose, $phone);
    $row = fb_get($path);
    $row = is_array($row) ? $row : [];

    $cooldown = auth_otp_resend_cooldown_seconds();
    $lastSentAt = (int)($row['last_sent_at'] ?? 0);
    $wait = $cooldown > 0 && $lastSentAt > 0 ? ($lastSentAt + $cooldown - $now) : 0;

    if ($wait > 0) {
        return [
            'ok' => false,
            'code' => 'OTP_SEND_COOLDOWN',
            'message' => 'Please wait before requesting another OTP.',
            'http_status' => 429,
            'retry_after_seconds' => $wait,
        ];
    }

    $windowSeconds = 3600;
    $limit = auth_otp_send_limit_per_hour();
    $windowStartedAt = (int)($row['window_started_at'] ?? 0);
    $count = (int)($row['send_count'] ?? 0);

    if ($windowStartedAt <= 0 || ($now - $windowStartedAt) >= $windowSeconds) {
        $windowStartedAt = $now;
        $count = 0;
    }

    if ($count >= $limit) {
        return [
            'ok' => false,
            'code' => 'OTP_SEND_LIMIT_REACHED',
            'message' => 'OTP request limit reached. Please try again later.',
            'http_status' => 429,
            'retry_after_seconds' => max(1, $windowSeconds - ($now - $windowStartedAt)),
            'send_count' => $count,
            'send_limit' => $limit,
        ];
    }

    return [
        'ok' => true,
        'path' => $path,
        'window_started_at' => $windowStartedAt,
        'send_count' => $count,
        'send_limit' => $limit,
    ];
}

function auth_otp_record_send_rate(string $purpose, string $phone, array $state = [], ?int $now = null): void
{
    $now = $now ?? now_ts();
    $path = (string)($state['path'] ?? auth_otp_send_rate_path($purpose, $phone));
    if ($path === '') {
        return;
    }

    $windowStartedAt = (int)($state['window_started_at'] ?? $now);
    $count = max(0, (int)($state['send_count'] ?? 0)) + 1;

    @fb_put($path, [
        'purpose' => strtoupper(trim($purpose)),
        'phone_hash' => auth_otp_send_rate_key($purpose, $phone),
        'window_started_at' => $windowStartedAt,
        'send_count' => $count,
        'send_limit' => auth_otp_send_limit_per_hour(),
        'last_sent_at' => $now,
        'updated_at' => $now,
    ]);
}

function auth_otp_reset_attempts_patch(): array
{
    return [
        'attempts' => 0,
        'max_attempts' => auth_otp_max_attempts(),
        'locked_at' => 0,
    ];
}

function session_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function auth_session_epoch_from_user(array $user): string
{
    return auth_clean_string($user['auth_session_epoch'] ?? $user['session_epoch'] ?? '');
}

function auth_new_session_epoch(): string
{
    return 'SE' . strtoupper(bin2hex(random_bytes(16)));
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

    $userSessionEpoch = auth_session_epoch_from_user($user);
    $sessionEpoch = auth_clean_string($session['auth_session_epoch'] ?? $session['session_epoch'] ?? '');
    if ($userSessionEpoch !== '' && $sessionEpoch !== $userSessionEpoch) {
        fb_patch('USER_SESSIONS/' . $session['_session_hash'], [
            'status' => 'RESET_REVOKED',
            'updated_at' => now_ts(),
        ]);

        api_response(false, 'SESSION_EXPIRED', 'Session expired. Please sign in again.', [], 401);
    }

    $userStatus = auth_status_value($user['status'] ?? 'INACTIVE');
    $accountStatus = auth_status_value($user['account_status'] ?? $userStatus);

    if ($accountStatus === 'REVIEW') {
        api_response(false, 'ACCOUNT_REVIEW_REQUIRED', 'Account is pending admin review', [], 403);
    }

    if ($accountStatus === 'BLOCKED') {
        api_response(false, 'ACCOUNT_BLOCKED', 'Account is blocked', [], 403);
    }

    if ($userStatus !== 'ACTIVE') {
        api_response(false, 'UNAUTHORIZED', 'User is not active', [], 401);
    }

    $user['uid'] = $uid;
    $user['status'] = $userStatus;
    $user['account_status'] = $accountStatus;
    $user['role'] = auth_status_value($user['role'] ?? '');

    auth_enforce_active_device_for_user($user, $session);

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

function auth_role_requires_active_device(string $role): bool
{
    return in_array(auth_status_value($role), ['USER', 'RETAILER'], true);
}

function auth_device_trust_row(string $uid, string $deviceId): array
{
    $uid = auth_clean_string($uid);
    $deviceId = auth_clean_string($deviceId);
    if ($uid === '' || $deviceId === '') {
        return [];
    }

    $row = fb_get('AUTH_DEVICE_TRUST/' . $uid . '/' . rawurlencode($deviceId));
    return is_array($row) ? $row : [];
}

function auth_device_is_trusted(string $uid, string $deviceId): bool
{
    $row = auth_device_trust_row($uid, $deviceId);
    if (!$row) {
        return false;
    }

    $status = auth_status_value($row['status'] ?? '');
    return !empty($row['trusted'])
        && !empty($row['otp_verified'])
        && empty($row['manual_logout'])
        && empty($row['revoked'])
        && in_array($status, ['', 'ACTIVE', 'TRUSTED'], true);
}

function auth_mark_device_trusted(string $uid, string $deviceId, string $deviceName = '', string $appVersion = ''): bool
{
    $uid = auth_clean_string($uid);
    $deviceId = auth_clean_string($deviceId);
    if ($uid === '' || $deviceId === '') {
        return false;
    }

    $now = now_ts();
    return fb_patch('AUTH_DEVICE_TRUST/' . $uid . '/' . rawurlencode($deviceId), [
        'uid' => $uid,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'app_version' => $appVersion,
        'trusted' => true,
        'otp_verified' => true,
        'manual_logout' => false,
        'revoked' => false,
        'status' => 'ACTIVE',
        'trusted_at' => $now,
        'last_login_at' => $now,
        'updated_at' => $now,
    ]);
}

function auth_mark_other_devices_replaced(string $uid, string $activeDeviceId): void
{
    $uid = auth_clean_string($uid);
    $activeDeviceId = auth_clean_string($activeDeviceId);
    if ($uid === '' || $activeDeviceId === '') {
        return;
    }

    $devices = fb_get('AUTH_DEVICE_TRUST/' . $uid);
    if (is_array($devices)) {
        foreach ($devices as $deviceKey => $row) {
            $rowDeviceId = (string)($row['device_id'] ?? rawurldecode((string)$deviceKey));
            if ($rowDeviceId === '' || $rowDeviceId === $activeDeviceId) {
                continue;
            }

            @fb_patch('AUTH_DEVICE_TRUST/' . $uid . '/' . rawurlencode($rowDeviceId), [
                'revoked' => true,
                'status' => 'DEVICE_REPLACED',
                'replaced_by_device_id' => $activeDeviceId,
                'updated_at' => now_ts(),
            ]);
        }
    }
}

function auth_revoke_other_user_sessions(string $uid, string $activeDeviceId, string $exceptSessionHash = ''): void
{
    $uid = auth_clean_string($uid);
    $activeDeviceId = auth_clean_string($activeDeviceId);
    if ($uid === '') {
        return;
    }

    $sessions = fb_get('USER_SESSIONS');
    if (!is_array($sessions)) {
        return;
    }

    foreach ($sessions as $hash => $session) {
        if (!is_array($session)) {
            continue;
        }
        if ((string)($session['uid'] ?? '') !== $uid) {
            continue;
        }
        if ($exceptSessionHash !== '' && (string)$hash === $exceptSessionHash) {
            continue;
        }
        if ($activeDeviceId !== '' && (string)($session['device_id'] ?? '') === $activeDeviceId) {
            continue;
        }

        @fb_patch('USER_SESSIONS/' . $hash, [
            'status' => 'DEVICE_REPLACED',
            'replaced_by_device_id' => $activeDeviceId,
            'updated_at' => now_ts(),
        ]);
    }
}

function auth_activate_user_device(string $uid, string $deviceId, string $deviceName = '', string $appVersion = '', string $sessionHash = ''): void
{
    $uid = auth_clean_string($uid);
    $deviceId = auth_clean_string($deviceId);
    if ($uid === '' || $deviceId === '') {
        return;
    }

    auth_mark_device_trusted($uid, $deviceId, $deviceName, $appVersion);
    auth_mark_other_devices_replaced($uid, $deviceId);
    auth_revoke_other_user_sessions($uid, $deviceId, $sessionHash);

    @fb_patch('USERS/' . $uid, [
        'active_device_id' => $deviceId,
        'ACTIVE_DEVICE_ID' => $deviceId,
        'active_device_name' => $deviceName,
        'active_device_app_version' => $appVersion,
        'active_device_updated_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);
}

function auth_enforce_active_device_for_user(array $user, array $session): void
{
    $role = auth_status_value($user['role'] ?? '');
    if (!auth_role_requires_active_device($role)) {
        return;
    }

    $activeDeviceId = auth_clean_string($user['active_device_id'] ?? $user['ACTIVE_DEVICE_ID'] ?? '');
    $sessionDeviceId = auth_clean_string($session['device_id'] ?? '');

    if ($activeDeviceId === '' || $sessionDeviceId === '' || $activeDeviceId === $sessionDeviceId) {
        return;
    }

    if (!empty($session['_session_hash'])) {
        @fb_patch('USER_SESSIONS/' . $session['_session_hash'], [
            'status' => 'DEVICE_REPLACED',
            'updated_at' => now_ts(),
        ]);
    }

    api_response(false, 'DEVICE_REPLACED', 'This account has been logged in on another device.', [], 401);
}

function auth_mark_manual_logout(string $uid, string $deviceId): void
{
    $uid = auth_clean_string($uid);
    $deviceId = auth_clean_string($deviceId);
    if ($uid === '' || $deviceId === '') {
        return;
    }

    @fb_patch('AUTH_DEVICE_TRUST/' . $uid . '/' . rawurlencode($deviceId), [
        'manual_logout' => true,
        'trusted' => false,
        'revoked' => true,
        'status' => 'MANUAL_LOGOUT',
        'logged_out_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);

    $trustedDevices = fb_get('AUTH_TRUSTED_DEVICES/' . $uid);
    if (is_array($trustedDevices)) {
        foreach ($trustedDevices as $selector => $row) {
            if (!is_array($row) || (string)($row['device_id'] ?? '') !== $deviceId) {
                continue;
            }

            @fb_patch('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, [
                'manual_logout' => true,
                'status' => 'REVOKED',
                'revoked' => true,
                'logged_out_at' => now_ts(),
                'updated_at' => now_ts(),
            ]);
        }
    }
}
