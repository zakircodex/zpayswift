<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_view_cfg(string $name, int $default, int $min, int $max): int
{
    $value = defined($name) ? (int)constant($name) : $default;
    return max($min, min($max, $value));
}

function znews_view_min_read(): int { return znews_view_cfg('ZNEWS_VIEW_MIN_READ_SECONDS', 15, 5, 300); }
function znews_view_min_active(): int { return znews_view_cfg('ZNEWS_VIEW_MIN_ACTIVE_SECONDS', 10, 3, 300); }
function znews_view_min_heartbeats(): int { return znews_view_cfg('ZNEWS_VIEW_MIN_HEARTBEATS', 1, 1, 20); }
function znews_view_heartbeat_min(): int { return znews_view_cfg('ZNEWS_VIEW_HEARTBEAT_MIN_SECONDS', 3, 2, 30); }
function znews_view_heartbeat_max(): int { return znews_view_cfg('ZNEWS_VIEW_HEARTBEAT_MAX_SECONDS', 60, 15, 300); }
function znews_view_session_max(): int { return znews_view_cfg('ZNEWS_VIEW_SESSION_MAX_SECONDS', 1800, 60, 7200); }
function znews_view_dedup_seconds(): int { return znews_view_cfg('ZNEWS_VIEW_DEDUP_SECONDS', 21600, 300, 86400); }
function znews_view_risk_threshold(): int { return znews_view_cfg('ZNEWS_VIEW_RISK_THRESHOLD', 70, 20, 100); }
function znews_view_rate_minute(): int { return znews_view_cfg('ZNEWS_VIEW_RATE_PER_MINUTE', 20, 5, 300); }
function znews_view_rate_hour(): int { return znews_view_cfg('ZNEWS_VIEW_RATE_PER_HOUR', 120, 20, 3000); }

function znews_view_secret(): string
{
    if (function_exists('security_secret_for_hash')) {
        return security_secret_for_hash();
    }
    foreach (['SECURITY_HASH_SECRET', 'APP_KEY', 'FIREBASE_AUTH'] as $name) {
        if (defined($name) && trim((string)constant($name)) !== '') {
            return trim((string)constant($name));
        }
    }
    return 'znews-view-secret-must-be-configured';
}

function znews_view_hmac(string $value): string
{
    return hash_hmac('sha256', $value, znews_view_secret());
}

function znews_view_visitor(): array
{
    $name = 'znews_visitor';
    $token = trim((string)($_COOKIE[$name] ?? ''));
    $isNew = preg_match('/^[A-Za-z0-9_-]{40,128}$/', $token) !== 1;
    if ($isNew) {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $secure = function_exists('app_is_https') ? app_is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie($name, $token, [
            'expires' => znews_now() + 31536000,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $token;
    }
    return ['token' => $token, 'is_new' => $isNew];
}

function znews_view_signed_session_hash(): string
{
    if (trim((string)($_COOKIE['zawtopup_user'] ?? '')) === '') {
        return '';
    }
    $started = false;
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('zawtopup_user');
            ini_set('session.use_strict_mode', '1');
            session_start(['read_and_close' => true]);
            $started = true;
        }
        $token = trim((string)($_SESSION['user_session_token'] ?? ''));
        return $token === '' ? '' : znews_view_hmac('session|' . $token);
    } catch (Throwable $e) {
        return '';
    } finally {
        if ($started && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}

function znews_view_is_bot(string $ua): bool
{
    return $ua !== '' && preg_match('/(?:bot|crawler|spider|slurp|headless|phantomjs|selenium|python-requests|curl\/|wget\/|httpclient|facebookexternalhit|telegrambot)/i', $ua) === 1;
}

function znews_view_context(): array
{
    $visitor = znews_view_visitor();
    $sessionHash = znews_view_signed_session_hash();
    $ip = function_exists('security_client_ip') ? security_client_ip() : client_ip();
    $ipHash = function_exists('security_ip_hash') ? security_ip_hash($ip) : znews_view_hmac('ip|' . $ip);
    $ua = function_exists('security_user_agent') ? security_user_agent() : substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
    $uaHash = znews_view_hmac('ua|' . strtolower($ua));
    $viewerType = $sessionHash === '' ? 'PUBLIC' : 'SIGNED_SESSION';
    $fingerprint = znews_view_hmac(implode('|', [$viewerType, $sessionHash, (string)$visitor['token'], $ipHash, $uaHash]));
    return [
        'fingerprint' => $fingerprint,
        'viewer_type' => $viewerType,
        'session_hash' => $sessionHash,
        'visitor_hash' => znews_view_hmac('visitor|' . (string)$visitor['token']),
        'ip_hash' => $ipHash,
        'ua_hash' => $uaHash,
        'new_cookie' => (bool)$visitor['is_new'],
        'ua_missing' => $ua === '',
        'bot' => znews_view_is_bot($ua),
    ];
}

function znews_view_path(string $viewId): string { return 'ZNEWS_VIEW_SESSIONS/' . znews_firebase_key($viewId, 'view_id'); }
function znews_view_post_path(string $postId, string $viewId): string { return 'ZNEWS_POST_VIEWS/' . znews_firebase_key($postId, 'post_id') . '/' . znews_firebase_key($viewId, 'view_id'); }
function znews_view_analytics_path(string $postId): string { return 'ZNEWS_ANALYTICS/' . znews_firebase_key($postId, 'post_id'); }
function znews_view_risk_path(string $viewId): string { return 'ZNEWS_VIEW_RISK/' . znews_firebase_key($viewId, 'view_id'); }
function znews_view_risk_queue_path(string $viewId): string { return 'ZNEWS_VIEW_RISK_QUEUE/' . znews_firebase_key($viewId, 'view_id'); }
function znews_view_unique_path(string $postId, string $fingerprint): string { return 'ZNEWS_UNIQUE_VIEWERS/' . znews_firebase_key($postId, 'post_id') . '/' . znews_firebase_key($fingerprint, 'fingerprint'); }

function znews_view_dedup_path(string $fingerprint, string $postId, int $now): string
{
    return 'ZNEWS_VIEW_DEDUP/' . znews_firebase_key($fingerprint, 'fingerprint') . '/' . znews_firebase_key($postId, 'post_id') . '/' . intdiv($now, znews_view_dedup_seconds());
}

function znews_view_rate_path(string $fingerprint, string $window, int $now): string
{
    $bucket = $window === 'MINUTE' ? gmdate('YmdHi', $now) : gmdate('YmdH', $now);
    return 'ZNEWS_VIEW_RATE/' . znews_firebase_key($fingerprint, 'fingerprint') . '/' . $window . '/' . $bucket;
}

function znews_view_require_public_post(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $post = fb_get(znews_path_post($postId));
    if (!is_array($post)
        || strtoupper(trim((string)($post['status'] ?? ''))) !== 'ACTIVE'
        || strtoupper(trim((string)($post['visibility'] ?? ''))) !== 'PUBLIC'
        || (int)($post['deleted_at'] ?? 0) > 0) {
        api_response(false, 'ZNEWS_POST_NOT_FOUND', 'Post not found.', [], 404);
    }
    return $post;
}

function znews_view_cas_counter(string $path, int $limit): array
{
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'allowed' => false, 'count' => 0];
        }
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $row['count'] = max(0, (int)($row['count'] ?? 0)) + 1;
        $row['updated_at'] = znews_now();
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) { usleep(50000); continue; }
        if (empty($write['ok'])) { return ['ok' => false, 'allowed' => false, 'count' => (int)$row['count']]; }
        return ['ok' => true, 'allowed' => (int)$row['count'] <= $limit, 'count' => (int)$row['count']];
    }
    return ['ok' => false, 'allowed' => false, 'count' => 0];
}

function znews_view_rate_check(string $fingerprint, int $now): array
{
    $minute = znews_view_cas_counter(znews_view_rate_path($fingerprint, 'MINUTE', $now), znews_view_rate_minute());
    $hour = znews_view_cas_counter(znews_view_rate_path($fingerprint, 'HOUR', $now), znews_view_rate_hour());
    return [
        'minute_allowed' => !empty($minute['ok']) && !empty($minute['allowed']),
        'hour_allowed' => !empty($hour['ok']) && !empty($hour['allowed']),
        'minute_count' => (int)($minute['count'] ?? 0),
        'hour_count' => (int)($hour['count'] ?? 0),
    ];
}

function znews_view_dedup_claim(string $fingerprint, string $postId, string $viewId, int $now): array
{
    $path = znews_view_dedup_path($fingerprint, $postId, $now);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) { return ['ok' => false, 'duplicate' => true]; }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $old = trim((string)($existing['view_id'] ?? ''));
            return ['ok' => true, 'duplicate' => $old !== '' && !hash_equals($old, $viewId), 'existing_view_id' => $old];
        }
        if ($existing !== null) { return ['ok' => false, 'duplicate' => true]; }
        $write = fb_put_if_match($path, [
            'view_id' => $viewId,
            'post_id' => $postId,
            'created_at' => $now,
            'expires_at' => $now + znews_view_dedup_seconds(),
        ], (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) { usleep(50000); continue; }
        return ['ok' => !empty($write['ok']), 'duplicate' => empty($write['ok'])];
    }
    return ['ok' => false, 'duplicate' => true];
}

function znews_view_analytics_defaults(string $postId): array
{
    return [
        'post_id' => $postId,
        'total_opens' => 0,
        'valid_views' => 0,
        'invalid_views' => 0,
        'suspicious_views' => 0,
        'completed_reads' => 0,
        'unique_viewers' => 0,
        'total_read_seconds' => 0,
        'updated_at' => 0,
    ];
}

function znews_view_analytics_format(array $row): array
{
    $valid = max(0, (int)($row['valid_views'] ?? 0));
    $seconds = max(0, (int)($row['total_read_seconds'] ?? 0));
    return [
        'post_id' => trim((string)($row['post_id'] ?? '')),
        'total_opens' => max(0, (int)($row['total_opens'] ?? 0)),
        'valid_views' => $valid,
        'invalid_views' => max(0, (int)($row['invalid_views'] ?? 0)),
        'suspicious_views' => max(0, (int)($row['suspicious_views'] ?? 0)),
        'completed_reads' => max(0, (int)($row['completed_reads'] ?? 0)),
        'unique_viewers' => max(0, (int)($row['unique_viewers'] ?? 0)),
        'average_read_seconds' => $valid > 0 ? round($seconds / $valid, 2) : 0.0,
        'total_read_seconds' => $seconds,
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
    ];
}

function znews_view_analytics_apply(string $postId, array $deltas): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $allowed = ['total_opens', 'valid_views', 'invalid_views', 'suspicious_views', 'completed_reads', 'unique_viewers', 'total_read_seconds'];
    foreach ($deltas as $field => $delta) {
        if (!in_array((string)$field, $allowed, true) || !is_int($delta)) { return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_DELTA_INVALID']; }
    }
    $path = znews_view_analytics_path($postId);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) { return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_READ_FAILED']; }
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : znews_view_analytics_defaults($postId);
        foreach ($allowed as $field) { $row[$field] = max(0, (int)($row[$field] ?? 0)); }
        foreach ($deltas as $field => $delta) { $row[$field] = max(0, (int)$row[$field] + $delta); }
        $row['post_id'] = $postId;
        $row['updated_at'] = znews_now();
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) { usleep(60000); continue; }
        if (empty($write['ok'])) { return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_WRITE_FAILED']; }
        return ['ok' => true, 'analytics' => znews_view_analytics_format($row)];
    }
    return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_BUSY'];
}

function znews_view_analytics_get(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $row = fb_get(znews_view_analytics_path($postId));
    return znews_view_analytics_format(is_array($row) ? $row : znews_view_analytics_defaults($postId));
}

function znews_view_unique_claim(string $postId, string $fingerprint, string $viewId): array
{
    $path = znews_view_unique_path($postId, $fingerprint);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) { return ['ok' => false, 'claimed' => false]; }
        if (is_array($snapshot['value'] ?? null)) { return ['ok' => true, 'claimed' => false]; }
        if (($snapshot['value'] ?? null) !== null) { return ['ok' => false, 'claimed' => false]; }
        $write = fb_put_if_match($path, ['view_id' => $viewId, 'created_at' => znews_now()], (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) { usleep(50000); continue; }
        return ['ok' => !empty($write['ok']), 'claimed' => !empty($write['ok'])];
    }
    return ['ok' => false, 'claimed' => false];
}

function znews_view_risk_store(array $session): bool
{
    $viewId = znews_firebase_key((string)($session['view_id'] ?? ''), 'view_id');
    $postId = znews_firebase_key((string)($session['post_id'] ?? ''), 'post_id');
    $now = znews_now();
    $risk = [
        'view_id' => $viewId,
        'post_id' => $postId,
        'viewer_type' => strtoupper(trim((string)($session['viewer_type'] ?? 'PUBLIC'))),
        'risk_score' => max(0, min(100, (int)($session['risk_score'] ?? 0))),
        'risk_reasons' => array_values(array_unique(array_map('strval', (array)($session['risk_reasons'] ?? [])))),
        'result' => strtoupper(trim((string)($session['result'] ?? 'PENDING'))),
        'duplicate' => !empty($session['duplicate']),
        'created_at' => (int)($session['created_at'] ?? $now),
        'updated_at' => $now,
    ];
    return fb_patch('', [
        znews_view_risk_path($viewId) => $risk,
        znews_view_risk_queue_path($viewId) => [
            'view_id' => $viewId,
            'post_id' => $postId,
            'risk_score' => $risk['risk_score'],
            'result' => $risk['result'],
            'created_at' => $risk['created_at'],
            'updated_at' => $now,
        ],
    ]);
}

function znews_view_token(string $viewId, string $fingerprint, int $createdAt): string
{
    return znews_view_hmac('token|' . $viewId . '|' . $fingerprint . '|' . $createdAt);
}

function znews_view_public_session(array $row, string $token = ''): array
{
    return [
        'view_id' => trim((string)($row['view_id'] ?? '')),
        'post_id' => trim((string)($row['post_id'] ?? '')),
        'view_token' => $token,
        'status' => strtoupper(trim((string)($row['status'] ?? 'STARTED'))),
        'result' => strtoupper(trim((string)($row['result'] ?? 'PENDING'))),
        'duplicate' => !empty($row['duplicate']),
        'self_view' => !empty($row['self_view']),
        'eligible_candidate' => empty($row['self_view']) && empty($row['duplicate']) && empty($row['bot_detected']) && (int)($row['risk_score'] ?? 0) < znews_view_risk_threshold(),
        'heartbeat_after_seconds' => znews_view_heartbeat_min(),
        'minimum_read_seconds' => znews_view_min_read(),
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
}

function znews_view_start(string $postId, string $idempotencyKey): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    znews_view_require_public_post($postId);
    $idempotencyKey = znews_idempotency_key($idempotencyKey);
    $ctx = znews_view_context();
    $fingerprint = (string)$ctx['fingerprint'];
    $viewId = 'ZNV' . strtoupper(substr(hash('sha256', $fingerprint . '|' . $postId . '|' . $idempotencyKey), 0, 29));
    $path = znews_view_path($viewId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_START_READ_FAILED', 'message' => 'View session could not be started.', 'http_status' => 503]; }
    $existing = $snapshot['value'] ?? null;
    if (is_array($existing)) {
        $savedFingerprint = trim((string)($existing['fingerprint_hash'] ?? ''));
        if ($savedFingerprint === '' || !hash_equals($savedFingerprint, $fingerprint)) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_IDEMPOTENCY_CONFLICT', 'message' => 'View request conflicts with an existing session.', 'http_status' => 409]; }
        $token = znews_view_token($viewId, $fingerprint, (int)($existing['created_at'] ?? 0));
        $blocked = strtoupper(trim((string)($existing['status'] ?? ''))) === 'BLOCKED';
        return ['ok' => !$blocked, 'code' => $blocked ? 'ZNEWS_VIEW_BLOCKED' : 'ZNEWS_VIEW_ALREADY_STARTED', 'message' => $blocked ? 'View session was blocked.' : 'View session was already started.', 'http_status' => $blocked ? 429 : 200, 'idempotent_replay' => true, 'session' => znews_view_public_session($existing, $blocked ? '' : $token)];
    }
    if ($existing !== null) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_INVALID_RECORD', 'message' => 'View session could not be started.', 'http_status' => 409]; }

    $now = znews_now();
    $rate = znews_view_rate_check($fingerprint, $now);
    $dedup = znews_view_dedup_claim($fingerprint, $postId, $viewId, $now);
    $duplicate = empty($dedup['ok']) || !empty($dedup['duplicate']);
    $reasons = [];
    $risk = 0;
    if (!empty($ctx['bot'])) { $risk = 100; $reasons[] = 'BOT_USER_AGENT'; }
    if (!empty($ctx['ua_missing'])) { $risk += 20; $reasons[] = 'USER_AGENT_MISSING'; }
    if (!empty($ctx['new_cookie'])) { $risk += 5; $reasons[] = 'NEW_VISITOR_COOKIE'; }
    if ($duplicate) { $risk += 40; $reasons[] = 'DEDUPLICATE_WINDOW_MATCH'; }
    if (empty($dedup['ok'])) { $risk += 30; $reasons[] = 'DEDUP_CHECK_UNAVAILABLE'; }
    if (empty($rate['minute_allowed'])) { $risk += 80; $reasons[] = 'MINUTE_RATE_EXCEEDED'; }
    if (empty($rate['hour_allowed'])) { $risk += 80; $reasons[] = 'HOURLY_RATE_EXCEEDED'; }
    $risk = min(100, $risk);
    $blocked = !empty($ctx['bot']) || empty($rate['minute_allowed']) || empty($rate['hour_allowed']);
    $token = znews_view_token($viewId, $fingerprint, $now);
    $row = [
        'schema_version' => 1,
        'view_id' => $viewId,
        'post_id' => $postId,
        'status' => $blocked ? 'BLOCKED' : 'STARTED',
        'result' => $blocked ? 'INVALID' : 'PENDING',
        'viewer_type' => (string)$ctx['viewer_type'],
        'fingerprint_hash' => $fingerprint,
        'session_hash' => (string)$ctx['session_hash'],
        'visitor_hash' => (string)$ctx['visitor_hash'],
        'ip_hash' => (string)$ctx['ip_hash'],
        'ua_hash' => (string)$ctx['ua_hash'],
        'token_hash' => hash('sha256', $token),
        'duplicate' => $duplicate,
        'duplicate_of_view_id' => trim((string)($dedup['existing_view_id'] ?? '')),
        'bot_detected' => !empty($ctx['bot']),
        'risk_score' => $risk,
        'risk_reasons' => array_values(array_unique($reasons)),
        'heartbeat_count' => 0,
        'active_seconds' => 0,
        'started_at' => $now,
        'last_heartbeat_at' => $now,
        'completed_at' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now + znews_view_session_max(),
        'reconciliation_required' => false,
        'earning_eligible' => false,
        'source' => 'ZNEWS_WEB',
    ];
    $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_START_CONFLICT', 'message' => 'View session was started by another request.', 'http_status' => 409]; }
    if (empty($write['ok'])) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_START_FAILED', 'message' => 'View session could not be started.', 'http_status' => 503]; }
    $indexOk = fb_put(znews_view_post_path($postId, $viewId), ['view_id' => $viewId, 'post_id' => $postId, 'status' => $row['status'], 'result' => $row['result'], 'created_at' => $now, 'updated_at' => $now]);
    $analytics = znews_view_analytics_apply($postId, ['total_opens' => 1]);
    $reconcile = !$indexOk || empty($analytics['ok']);
    if ($reconcile) { @fb_patch($path, ['reconciliation_required' => true, 'reconciliation_code' => 'START_INDEX_SYNC', 'updated_at' => znews_now()]); }
    if ($blocked || $duplicate || $risk >= znews_view_risk_threshold()) { znews_view_risk_store($row); }
    if ($blocked) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_BLOCKED', 'message' => 'View session was blocked.', 'http_status' => 429, 'session' => znews_view_public_session($row)]; }
    return ['ok' => true, 'code' => 'ZNEWS_VIEW_STARTED', 'message' => 'View session started.', 'http_status' => 201, 'idempotent_replay' => false, 'session' => znews_view_public_session($row, $token), 'reconciliation_required' => $reconcile];
}

function znews_view_load(string $viewId): array
{
    $viewId = znews_firebase_key($viewId, 'view_id');
    $snapshot = fb_get_with_etag(znews_view_path($viewId));
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) { api_response(false, 'ZNEWS_VIEW_READ_FAILED', 'View session could not be loaded.', [], 503); }
    if (!is_array($snapshot['value'] ?? null)) { api_response(false, 'ZNEWS_VIEW_NOT_FOUND', 'View session not found.', [], 404); }
    return ['view_id' => $viewId, 'row' => (array)$snapshot['value'], 'etag' => (string)$snapshot['etag']];
}

function znews_view_token_valid(array $row, string $token): bool
{
    $stored = trim((string)($row['token_hash'] ?? ''));
    return $token !== '' && $stored !== '' && hash_equals($stored, hash('sha256', trim($token)));
}

function znews_view_heartbeat(string $viewId, string $token): array
{
    $loaded = znews_view_load($viewId);
    $row = $loaded['row'];
    if (!znews_view_token_valid($row, $token)) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_TOKEN_INVALID', 'message' => 'Invalid view session token.', 'http_status' => 403]; }
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    if (in_array($status, ['COMPLETED', 'BLOCKED', 'EXPIRED'], true)) { return ['ok' => true, 'code' => 'ZNEWS_VIEW_HEARTBEAT_IGNORED', 'message' => 'View session is no longer active.', 'accepted' => false]; }
    $now = znews_now();
    $started = (int)($row['started_at'] ?? $row['created_at'] ?? 0);
    if ($started <= 0 || $now - $started > znews_view_session_max()) {
        $row['status'] = 'EXPIRED'; $row['result'] = 'INVALID'; $row['completed_at'] = $now; $row['updated_at'] = $now;
        $row['risk_score'] = max(70, (int)($row['risk_score'] ?? 0));
        $row['risk_reasons'] = array_values(array_unique(array_merge((array)($row['risk_reasons'] ?? []), ['SESSION_EXPIRED'])));
        @fb_put_if_match(znews_view_path($viewId), $row, (string)$loaded['etag']); znews_view_risk_store($row);
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_EXPIRED', 'message' => 'View session expired.', 'http_status' => 409];
    }
    $last = (int)($row['last_heartbeat_at'] ?? $started);
    $delta = max(0, $now - $last);
    if ($delta < znews_view_heartbeat_min()) { return ['ok' => true, 'code' => 'ZNEWS_VIEW_HEARTBEAT_TOO_SOON', 'message' => 'Heartbeat received too soon.', 'accepted' => false, 'retry_after_seconds' => znews_view_heartbeat_min() - $delta]; }
    $credit = $delta <= znews_view_heartbeat_max() ? min($delta, 30) : 0;
    if ($credit === 0) {
        $row['risk_score'] = min(100, (int)($row['risk_score'] ?? 0) + 20);
        $row['risk_reasons'] = array_values(array_unique(array_merge((array)($row['risk_reasons'] ?? []), ['HEARTBEAT_GAP_TOO_LARGE'])));
    }
    $row['status'] = 'ACTIVE';
    $row['heartbeat_count'] = max(0, (int)($row['heartbeat_count'] ?? 0)) + 1;
    $row['active_seconds'] = max(0, (int)($row['active_seconds'] ?? 0)) + $credit;
    $row['last_heartbeat_at'] = $now;
    $row['updated_at'] = $now;
    $write = fb_put_if_match(znews_view_path($viewId), $row, (string)$loaded['etag']);
    if ((int)($write['status'] ?? 0) === 412) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_HEARTBEAT_CONFLICT', 'message' => 'Another heartbeat updated this session.', 'http_status' => 409]; }
    if (empty($write['ok'])) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_HEARTBEAT_FAILED', 'message' => 'Heartbeat could not be saved.', 'http_status' => 503]; }
    if ((int)$row['risk_score'] >= znews_view_risk_threshold()) { znews_view_risk_store($row); }
    return ['ok' => true, 'code' => 'ZNEWS_VIEW_HEARTBEAT_ACCEPTED', 'message' => 'Heartbeat accepted.', 'accepted' => true, 'active_seconds' => (int)$row['active_seconds'], 'heartbeat_count' => (int)$row['heartbeat_count'], 'server_time' => $now];
}

function znews_view_complete(string $viewId, string $token): array
{
    $loaded = znews_view_load($viewId);
    $row = $loaded['row'];
    if (!znews_view_token_valid($row, $token)) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_TOKEN_INVALID', 'message' => 'Invalid view session token.', 'http_status' => 403]; }
    if (strtoupper(trim((string)($row['status'] ?? ''))) === 'COMPLETED') { $result = strtoupper(trim((string)($row['result'] ?? 'INVALID'))); return ['ok' => true, 'code' => 'ZNEWS_VIEW_ALREADY_COMPLETED', 'message' => 'View session was already completed.', 'idempotent_replay' => true, 'result' => $result, 'valid_view' => $result === 'VALID', 'earning_eligible' => false]; }
    $now = znews_now();
    $elapsed = max(0, $now - (int)($row['started_at'] ?? $row['created_at'] ?? $now));
    $active = max(0, (int)($row['active_seconds'] ?? 0));
    $beats = max(0, (int)($row['heartbeat_count'] ?? 0));
    $risk = max(0, min(100, (int)($row['risk_score'] ?? 0)));
    $reasons = (array)($row['risk_reasons'] ?? []);
    if ($elapsed < znews_view_min_read()) { $risk += 20; $reasons[] = 'READ_TIME_TOO_SHORT'; }
    if ($active < znews_view_min_active()) { $risk += 20; $reasons[] = 'ACTIVE_TIME_TOO_SHORT'; }
    if ($beats < znews_view_min_heartbeats()) { $risk += 25; $reasons[] = 'HEARTBEAT_REQUIRED'; }
    if (!empty($row['duplicate'])) { $reasons[] = 'DUPLICATE_VIEW'; }
    if (!empty($row['bot_detected'])) { $risk = 100; $reasons[] = 'BOT_DETECTED'; }
    if (strtoupper(trim((string)($row['status'] ?? ''))) === 'BLOCKED') { $risk = max(90, $risk); $reasons[] = 'SESSION_BLOCKED'; }
    $risk = min(100, $risk);
    $valid = strtoupper(trim((string)($row['status'] ?? ''))) !== 'BLOCKED'
        && empty($row['duplicate']) && empty($row['bot_detected'])
        && $elapsed >= znews_view_min_read() && $active >= znews_view_min_active()
        && $beats >= znews_view_min_heartbeats() && $risk < znews_view_risk_threshold();
    $result = $valid ? 'VALID' : ($risk >= znews_view_risk_threshold() ? 'SUSPICIOUS' : 'INVALID');
    $row['status'] = 'COMPLETED'; $row['result'] = $result; $row['risk_score'] = $risk;
    $row['risk_reasons'] = array_values(array_unique(array_map('strval', $reasons)));
    $row['elapsed_seconds'] = $elapsed; $row['completed_at'] = $now; $row['updated_at'] = $now; $row['earning_eligible'] = false;
    $write = fb_put_if_match(znews_view_path($viewId), $row, (string)$loaded['etag']);
    if ((int)($write['status'] ?? 0) === 412) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_COMPLETE_CONFLICT', 'message' => 'View session changed. Retry completion.', 'http_status' => 409]; }
    if (empty($write['ok'])) { return ['ok' => false, 'code' => 'ZNEWS_VIEW_COMPLETE_FAILED', 'message' => 'View session could not be completed.', 'http_status' => 503]; }
    $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
    $deltas = $valid
        ? ['valid_views' => 1, 'completed_reads' => 1, 'total_read_seconds' => min($active, znews_view_session_max())]
        : ['invalid_views' => 1, 'suspicious_views' => $result === 'SUSPICIOUS' ? 1 : 0];
    $unique = ['ok' => true, 'claimed' => false];
    if ($valid) {
        $unique = znews_view_unique_claim($postId, znews_firebase_key((string)$row['fingerprint_hash'], 'fingerprint'), $viewId);
        if (!empty($unique['claimed'])) { $deltas['unique_viewers'] = 1; }
    }
    $analytics = znews_view_analytics_apply($postId, $deltas);
    $indexOk = fb_put(znews_view_post_path($postId, $viewId), ['view_id' => $viewId, 'post_id' => $postId, 'status' => 'COMPLETED', 'result' => $result, 'active_seconds' => $active, 'created_at' => (int)($row['created_at'] ?? $now), 'completed_at' => $now, 'updated_at' => $now]);
    $reconcile = empty($analytics['ok']) || empty($unique['ok']) || !$indexOk;
    if ($reconcile) { @fb_patch(znews_view_path($viewId), ['reconciliation_required' => true, 'reconciliation_code' => 'COMPLETE_INDEX_SYNC', 'updated_at' => znews_now()]); }
    if (!$valid) { znews_view_risk_store($row); }
    return ['ok' => !$reconcile, 'code' => $reconcile ? 'ZNEWS_VIEW_RECONCILIATION_REQUIRED' : 'ZNEWS_VIEW_COMPLETED', 'message' => $reconcile ? 'View was completed but analytics require reconciliation.' : 'View session completed.', 'http_status' => $reconcile ? 503 : 200, 'result' => $result, 'valid_view' => $valid, 'earning_eligible' => false, 'active_seconds' => $active, 'elapsed_seconds' => $elapsed, 'analytics' => is_array($analytics['analytics'] ?? null) ? $analytics['analytics'] : [], 'reconciliation_required' => $reconcile];
}

function znews_view_cursor_encode(int $createdAt, string $viewId): string
{
    $json = json_encode(['created_at' => $createdAt, 'view_id' => $viewId], JSON_UNESCAPED_SLASHES);
    return is_string($json) ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=') : '';
}

function znews_view_cursor_decode($value): array
{
    $cursor = trim((string)$value);
    if ($cursor === '') { return []; }
    if (strlen($cursor) > 512 || preg_match('/[^A-Za-z0-9_-]/', $cursor) === 1) { api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422); }
    $cursor .= str_repeat('=', (4 - strlen($cursor) % 4) % 4);
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $row = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($row)) { api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422); }
    $createdAt = filter_var($row['created_at'] ?? null, FILTER_VALIDATE_INT);
    $viewId = trim((string)($row['view_id'] ?? ''));
    if ($createdAt === false || $createdAt < 0 || $viewId === '') { api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422); }
    return ['created_at' => (int)$createdAt, 'view_id' => znews_firebase_key($viewId, 'view_id')];
}

function znews_view_risk_queue(int $limit, array $cursor = []): array
{
    $rows = fb_get('ZNEWS_VIEW_RISK_QUEUE');
    $items = [];
    if (is_array($rows)) {
        foreach ($rows as $viewId => $row) {
            if (!is_array($row)) { continue; }
            $row['view_id'] = (string)($row['view_id'] ?? $viewId);
            $items[] = $row;
        }
    }
    usort($items, static function (array $a, array $b): int {
        $time = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        return $time !== 0 ? $time : strcmp((string)($b['view_id'] ?? ''), (string)($a['view_id'] ?? ''));
    });
    $result = [];
    foreach ($items as $item) {
        $time = (int)($item['created_at'] ?? 0); $id = (string)($item['view_id'] ?? '');
        if ($cursor) {
            $after = $time < (int)$cursor['created_at'] || ($time === (int)$cursor['created_at'] && strcmp($id, (string)$cursor['view_id']) < 0);
            if (!$after) { continue; }
        }
        $result[] = $item;
        if (count($result) > $limit) { break; }
    }
    $hasMore = count($result) > $limit;
    if ($hasMore) { array_pop($result); }
    $next = '';
    if ($hasMore && $result) { $last = $result[count($result) - 1]; $next = znews_view_cursor_encode((int)($last['created_at'] ?? 0), (string)($last['view_id'] ?? '')); }
    return ['items' => array_values($result), 'next_cursor' => $next, 'has_more' => $hasMore];
}

function znews_view_admin_details(string $viewId): array
{
    $viewId = znews_firebase_key($viewId, 'view_id');
    $row = fb_get(znews_view_path($viewId));
    if (!is_array($row)) { api_response(false, 'ZNEWS_VIEW_NOT_FOUND', 'View session not found.', [], 404); }
    unset($row['token_hash'], $row['session_hash'], $row['visitor_hash']);
    $risk = fb_get(znews_view_risk_path($viewId));
    return ['session' => $row, 'risk' => is_array($risk) ? $risk : []];
}
