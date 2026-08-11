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

function auth_index_owner_uid($row): string
{
    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function auth_email_index_keys(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $safe = str_replace(
        ['.', '#', '$', '[', ']', '/'],
        [',', '_', '_', '(', ')', '_'],
        $email
    );

    return array_values(array_unique([md5($email), $safe]));
}

function auth_find_uid_by_email(string $email): string
{
    foreach (auth_email_index_keys($email) as $key) {
        $uid = auth_index_owner_uid(fb_get('USER_INDEX/EMAIL/' . $key));
        if ($uid !== '') {
            return $uid;
        }
    }

    return '';
}

function auth_index_claim(string $path, string $uid, $payload = null): array
{
    $path = trim($path, '/');
    $uid = auth_clean_string($uid);
    if ($path === '' || $uid === '') {
        return ['ok' => false, 'claimed' => false, 'conflict' => false, 'code' => 'INDEX_CLAIM_INVALID'];
    }

    $value = $payload;
    if ($value === null) {
        $value = $uid;
    } elseif (is_array($value)) {
        $value['uid'] = $uid;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'claimed' => false, 'conflict' => false, 'code' => 'INDEX_READ_FAILED'];
        }

        $current = $snapshot['value'] ?? null;
        $owner = auth_index_owner_uid($current);
        if ($owner !== '') {
            if (hash_equals($owner, $uid)) {
                return ['ok' => true, 'claimed' => false, 'conflict' => false, 'owner_uid' => $owner];
            }

            return ['ok' => false, 'claimed' => false, 'conflict' => true, 'code' => 'INDEX_ALREADY_CLAIMED', 'owner_uid' => $owner];
        }

        if ($current !== null) {
            return ['ok' => false, 'claimed' => false, 'conflict' => true, 'code' => 'INDEX_INVALID_EXISTING_VALUE'];
        }

        $write = fb_put_if_match($path, $value, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return ['ok' => true, 'claimed' => true, 'conflict' => false, 'owner_uid' => $uid];
        }

        if ((int)($write['status'] ?? 0) !== 412) {
            return ['ok' => false, 'claimed' => false, 'conflict' => false, 'code' => 'INDEX_WRITE_FAILED'];
        }
    }

    return ['ok' => false, 'claimed' => false, 'conflict' => true, 'code' => 'INDEX_CLAIM_CONFLICT'];
}

function auth_index_release(string $path, string $uid): bool
{
    $path = trim($path, '/');
    $uid = auth_clean_string($uid);
    if ($path === '' || $uid === '') {
        return false;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return false;
        }

        $current = $snapshot['value'] ?? null;
        if ($current === null) {
            return true;
        }

        $owner = auth_index_owner_uid($current);
        if ($owner === '' || !hash_equals($owner, $uid)) {
            return false;
        }

        $delete = fb_delete_if_match($path, (string)$snapshot['etag']);
        if (!empty($delete['ok'])) {
            return true;
        }

        if ((int)($delete['status'] ?? 0) !== 412) {
            return false;
        }
    }

    return false;
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

function auth_otp_verification_lease_seconds(): int
{
    $seconds = defined('OTP_VERIFICATION_LEASE_SECONDS')
        ? (int)OTP_VERIFICATION_LEASE_SECONDS
        : 45;

    return max(15, min(180, $seconds));
}

function auth_otp_claim_error(string $code, string $message, int $httpStatus, array $data = []): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'http_status' => $httpStatus,
        'data' => $data,
    ];
}

function auth_otp_claim_verification(
    string $otpRequestId,
    string $purpose,
    string $uid,
    string $otp,
    ?int $now = null
): array {
    $otpRequestId = auth_clean_string($otpRequestId);
    $purpose = auth_status_value($purpose);
    $uid = auth_clean_string($uid);
    $otp = trim($otp);
    $now = $now ?? now_ts();

    if ($otpRequestId === '' || $purpose === '' || $uid === '' || $otp === '') {
        return auth_otp_claim_error('VALIDATION_ERROR', 'OTP verification data is incomplete', 422);
    }

    $path = 'AUTH_OTP_REQUESTS/' . $otpRequestId;
    $ownerToken = bin2hex(random_bytes(32));
    $ownerHash = hash('sha256', $ownerToken);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return auth_otp_claim_error('SERVER_ERROR', 'Failed to read OTP request', 500);
        }

        $row = $snapshot['value'] ?? null;
        if (!is_array($row)) {
            return auth_otp_claim_error('OTP_NOT_FOUND', 'OTP request not found', 404);
        }

        if (auth_clean_string($row['uid'] ?? '') !== $uid) {
            return auth_otp_claim_error('OTP_UID_MISMATCH', 'OTP does not match this account', 400);
        }

        if (auth_status_value($row['purpose'] ?? '') !== $purpose) {
            return auth_otp_claim_error('OTP_PURPOSE_MISMATCH', 'OTP purpose mismatch', 400);
        }

        $status = auth_status_value($row['status'] ?? '');
        if (!empty($row['used']) || $status === 'VERIFIED') {
            return auth_otp_claim_error('OTP_ALREADY_USED', 'OTP already used', 409);
        }

        if ((int)($row['expires_at'] ?? 0) <= $now) {
            $expired = $row;
            $expired['status'] = 'EXPIRED';
            $expired['updated_at'] = $now;
            $write = fb_put_if_match($path, $expired, (string)$snapshot['etag']);
            if (empty($write['ok']) && (int)($write['status'] ?? 0) === 412) {
                continue;
            }

            return auth_otp_claim_error('OTP_EXPIRED', 'OTP expired', 410);
        }

        $lockState = auth_otp_lock_state($row);
        if (!empty($lockState['locked'])) {
            return auth_otp_claim_error(
                'OTP_LOCKED',
                'Maximum OTP attempts exceeded. Please request a new OTP.',
                423,
                ['attempts_left' => 0]
            );
        }

        if ($status === 'VERIFYING' && (int)($row['verification_lease_expires_at'] ?? 0) > $now) {
            return auth_otp_claim_error(
                'OTP_VERIFY_IN_PROGRESS',
                'OTP verification is already in progress',
                409
            );
        }

        if (!in_array($status, ['SENT', 'RESENT', 'VERIFYING'], true)) {
            return auth_otp_claim_error('OTP_INVALID_STATUS', 'OTP is not active', 400);
        }

        $codeHash = auth_clean_string($row['code_hash'] ?? '');
        if ($codeHash === '' || !password_verify($otp, $codeHash)) {
            $attempts = (int)$lockState['attempts'] + 1;
            $maxAttempts = (int)$lockState['max_attempts'];
            $locked = $attempts >= $maxAttempts;
            $failed = $row;
            $failed['attempts'] = $attempts;
            $failed['max_attempts'] = $maxAttempts;
            $failed['failed_attempt_at'] = $now;
            $failed['updated_at'] = $now;
            if ($locked) {
                $failed['status'] = 'LOCKED';
                $failed['locked_at'] = $now;
            }

            $write = fb_put_if_match($path, $failed, (string)$snapshot['etag']);
            if (empty($write['ok']) && (int)($write['status'] ?? 0) === 412) {
                continue;
            }
            if (empty($write['ok'])) {
                return auth_otp_claim_error('SERVER_ERROR', 'Failed to record OTP attempt', 500);
            }

            return auth_otp_claim_error(
                $locked ? 'OTP_LOCKED' : 'OTP_INVALID',
                $locked
                    ? 'Maximum OTP attempts exceeded. Please request a new OTP.'
                    : 'Invalid OTP',
                $locked ? 423 : 400,
                ['attempts_left' => max(0, $maxAttempts - $attempts)]
            );
        }

        $claimed = $row;
        $claimed['status'] = 'VERIFYING';
        $claimed['verification_previous_status'] = $status === 'VERIFYING'
            ? auth_status_value($row['verification_previous_status'] ?? 'SENT')
            : $status;
        $claimed['verification_owner_hash'] = $ownerHash;
        $claimed['verification_claimed_at'] = $now;
        $claimed['verification_lease_expires_at'] = $now + auth_otp_verification_lease_seconds();
        $claimed['verification_attempt_count'] = max(0, (int)($row['verification_attempt_count'] ?? 0)) + 1;
        $claimed['updated_at'] = $now;

        $write = fb_put_if_match($path, $claimed, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return [
                'ok' => true,
                'code' => 'OTP_CLAIMED',
                'message' => 'OTP verification claimed',
                'http_status' => 200,
                'data' => [],
                'owner_token' => $ownerToken,
                'row' => $claimed,
            ];
        }

        if ((int)($write['status'] ?? 0) !== 412) {
            return auth_otp_claim_error('SERVER_ERROR', 'Failed to claim OTP verification', 500);
        }
    }

    return auth_otp_claim_error('OTP_VERIFY_CONFLICT', 'OTP verification conflict. Please retry.', 409);
}

function auth_otp_complete_verification(
    string $otpRequestId,
    string $ownerToken,
    ?int $now = null
): bool {
    $otpRequestId = auth_clean_string($otpRequestId);
    $ownerToken = trim($ownerToken);
    $now = $now ?? now_ts();
    if ($otpRequestId === '' || $ownerToken === '') {
        return false;
    }

    $path = 'AUTH_OTP_REQUESTS/' . $otpRequestId;
    $ownerHash = hash('sha256', $ownerToken);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        $row = $snapshot['value'] ?? null;
        if (empty($snapshot['ok']) || !is_array($row) || !is_string($snapshot['etag'] ?? null)) {
            return false;
        }

        if (
            auth_status_value($row['status'] ?? '') !== 'VERIFYING'
            || !hash_equals(auth_clean_string($row['verification_owner_hash'] ?? ''), $ownerHash)
        ) {
            return false;
        }

        $row['used'] = true;
        $row['used_at'] = $now;
        $row['status'] = 'VERIFIED';
        $row['verified_at'] = $now;
        $row['updated_at'] = $now;
        unset(
            $row['verification_owner_hash'],
            $row['verification_lease_expires_at'],
            $row['verification_previous_status']
        );

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return true;
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            return false;
        }
    }

    return false;
}

function auth_otp_release_verification(
    string $otpRequestId,
    string $ownerToken,
    ?int $now = null
): bool {
    $otpRequestId = auth_clean_string($otpRequestId);
    $ownerToken = trim($ownerToken);
    $now = $now ?? now_ts();
    if ($otpRequestId === '' || $ownerToken === '') {
        return false;
    }

    $path = 'AUTH_OTP_REQUESTS/' . $otpRequestId;
    $ownerHash = hash('sha256', $ownerToken);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        $row = $snapshot['value'] ?? null;
        if (empty($snapshot['ok']) || !is_array($row) || !is_string($snapshot['etag'] ?? null)) {
            return false;
        }

        if (
            auth_status_value($row['status'] ?? '') !== 'VERIFYING'
            || !hash_equals(auth_clean_string($row['verification_owner_hash'] ?? ''), $ownerHash)
        ) {
            return false;
        }

        $previous = auth_status_value($row['verification_previous_status'] ?? 'SENT');
        $row['status'] = in_array($previous, ['SENT', 'RESENT'], true) ? $previous : 'SENT';
        $row['updated_at'] = $now;
        unset(
            $row['verification_owner_hash'],
            $row['verification_lease_expires_at'],
            $row['verification_previous_status']
        );

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (!empty($write['ok'])) {
            return true;
        }
        if ((int)($write['status'] ?? 0) !== 412) {
            return false;
        }
    }

    return false;
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

function auth_issue_website_user_session(
    array $user,
    string $uid,
    string $deviceId,
    string $deviceName,
    array $requestMeta = []
): array {
    $uid = auth_clean_string($uid);
    $deviceId = auth_clean_string($deviceId);
    $deviceName = auth_clean_string($deviceName);
    if ($uid === '' || $deviceId === '') {
        return ['ok' => false, 'code' => 'SESSION_INPUT_INVALID'];
    }

    $now = now_ts();
    $epoch = auth_new_session_epoch();
    $token = random_token(32);
    $hash = session_hash($token);

    $session = [
        'session_id' => make_session_id(),
        'uid' => $uid,
        'phone' => (string)($user['phone'] ?? ''),
        'token_last8' => substr($token, -8),
        'device_name' => $deviceName,
        'device_id' => $deviceId,
        'status' => 'ACTIVE',
        'activated_at' => $now,
        'ip' => client_ip(),
        'created_at' => $now,
        'expires_at' => $now + SESSION_TTL_SECONDS,
        'last_seen_at' => $now,
        'auth_session_epoch' => $epoch,
    ];

    if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
        return ['ok' => false, 'code' => 'SESSION_WRITE_FAILED'];
    }

    $userPatch = [
        'auth_session_epoch' => $epoch,
        'active_device_id' => $deviceId,
        'ACTIVE_DEVICE_ID' => $deviceId,
        'active_device_name' => $deviceName,
        'active_device_app_version' => (string)($requestMeta['app_version'] ?? ''),
        'active_device_updated_at' => $now,
        'last_login_at' => $now,
        'last_login_ip' => (string)($requestMeta['created_ip'] ?? $requestMeta['ip'] ?? ''),
        'last_login_ip_country' => (string)($requestMeta['ip_country'] ?? ''),
        'last_login_user_agent' => (string)($requestMeta['user_agent'] ?? ''),
        'browser_timezone' => (string)($requestMeta['browser_timezone'] ?? ($user['browser_timezone'] ?? '')),
        'updated_at' => $now,
    ];

    if (!fb_patch('USERS/' . $uid, $userPatch)) {
        @fb_delete('USER_SESSIONS/' . $hash);
        return ['ok' => false, 'code' => 'USER_SESSION_STATE_WRITE_FAILED'];
    }

    auth_mark_device_trusted(
        $uid,
        $deviceId,
        $deviceName,
        (string)($requestMeta['app_version'] ?? '')
    );
    auth_mark_other_devices_replaced($uid, $deviceId);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'session_token' => $token,
        'session_hash' => $hash,
        'auth_session_epoch' => $epoch,
    ];
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

function auth_trusted_browser_cookie_parts(string $cookieValue): array
{
    $cookieValue = trim($cookieValue);
    if ($cookieValue === '') {
        return [];
    }

    $parts = explode(':', $cookieValue);
    if (count($parts) === 2) {
        [$selector, $token] = $parts;
        $uidHint = '';
    } elseif (count($parts) === 3) {
        [$uidHint, $selector, $token] = $parts;
    } else {
        return [];
    }

    $uidHint = auth_clean_string($uidHint);
    $selector = trim($selector);
    $token = trim($token);
    if (
        ($uidHint !== '' && !preg_match('/^[A-Za-z0-9_-]{2,128}$/', $uidHint))
        || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $selector)
        || strlen($token) < 32
        || strlen($token) > 256
    ) {
        return [];
    }

    return [
        'uid_hint' => $uidHint,
        'selector' => $selector,
        'token' => $token,
    ];
}

function auth_trusted_browser_cookie_uid_hint(string $cookieValue): string
{
    $parts = auth_trusted_browser_cookie_parts($cookieValue);
    return auth_clean_string($parts['uid_hint'] ?? '');
}

function auth_trusted_browser_cookie_context(
    string $uid,
    string $cookieValue,
    string $expectedDeviceId = '',
    array $user = [],
    bool $touch = true
): array {
    $uid = auth_clean_string($uid);
    $cookieValue = trim($cookieValue);
    $expectedDeviceId = auth_clean_string($expectedDeviceId);

    if ($uid === '' || $cookieValue === '') {
        return ['ok' => false, 'code' => 'TRUSTED_DEVICE_MISSING'];
    }

    $parts = auth_trusted_browser_cookie_parts($cookieValue);
    $selector = trim((string)($parts['selector'] ?? ''));
    $token = trim((string)($parts['token'] ?? ''));
    $uidHint = auth_clean_string($parts['uid_hint'] ?? '');
    if (!$parts || ($uidHint !== '' && !hash_equals($uid, $uidHint))) {
        return ['ok' => false, 'code' => 'TRUSTED_DEVICE_INVALID'];
    }

    $row = fb_get('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector);
    if (!is_array($row)) {
        return ['ok' => false, 'code' => 'TRUSTED_DEVICE_NOT_FOUND'];
    }

    $storedHash = trim((string)($row['token_hash'] ?? ''));
    $status = auth_status_value($row['status'] ?? '');
    $expiresAt = (int)($row['expires_at'] ?? 0);
    $rowDeviceId = auth_clean_string($row['device_id'] ?? '');
    if (
        $storedHash === ''
        || !hash_equals($storedHash, hash('sha256', $token))
        || auth_clean_string($row['uid'] ?? $uid) !== $uid
        || empty($row['trusted'])
        || empty($row['otp_verified'])
        || !empty($row['manual_logout'])
        || !empty($row['revoked'])
        || !in_array($status, ['', 'ACTIVE', 'TRUSTED'], true)
        || $expiresAt <= now_ts()
        || ($expectedDeviceId !== '' && $rowDeviceId !== $expectedDeviceId)
    ) {
        return ['ok' => false, 'code' => 'TRUSTED_DEVICE_INVALID'];
    }

    if (!$user) {
        $loadedUser = fb_get('USERS/' . $uid);
        $user = is_array($loadedUser) ? $loadedUser : [];
    }
    if (!$user) {
        return ['ok' => false, 'code' => 'ACCOUNT_NOT_FOUND'];
    }

    $activeDeviceId = auth_clean_string($user['active_device_id'] ?? $user['ACTIVE_DEVICE_ID'] ?? '');
    $userEpoch = auth_session_epoch_from_user($user);
    $trustedEpoch = auth_clean_string($row['auth_session_epoch'] ?? '');
    if (
        $expectedDeviceId === ''
        || $activeDeviceId !== $expectedDeviceId
        || !auth_device_is_trusted($uid, $expectedDeviceId)
        || $userEpoch === ''
        || $trustedEpoch === ''
        || !hash_equals($userEpoch, $trustedEpoch)
    ) {
        return ['ok' => false, 'code' => 'TRUSTED_DEVICE_EXPIRED'];
    }

    if ($touch) {
        @fb_patch('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, [
            'last_used_at' => now_ts(),
            'updated_at' => now_ts(),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'TRUSTED_DEVICE_VALID',
        'selector' => $selector,
        'selector_hash' => hash('sha256', $selector),
        'expires_at' => $expiresAt,
    ];
}

function auth_web_logout_preserves_trusted_device(
    string $uid,
    string $deviceId,
    string $cookieValue,
    array $user = []
): bool {
    if (auth_clean_string($deviceId) !== 'USER_WEB') {
        return false;
    }

    return !empty(auth_trusted_browser_cookie_context(
        $uid,
        $cookieValue,
        $deviceId,
        $user,
        false
    )['ok']);
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
