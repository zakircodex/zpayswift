<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';
require_once dirname(__DIR__) . '/lib/add_money.php';
require_once dirname(__DIR__) . '/lib/rates.php';
require_once dirname(__DIR__) . '/lib/currency_conversion.php';
require_once dirname(__DIR__) . '/lib/support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| Admin panel local session TTL
|--------------------------------------------------------------------------
| Config override করতে চাইলে private config.php তে দিতে পারো:
| define('ADMIN_PANEL_SESSION_TTL_SECONDS', 86400); // 1 day
| define('ADMIN_PANEL_SESSION_TTL_SECONDS', 18000); // 5 hours
| define('ADMIN_PANEL_SESSION_TTL_SECONDS', 7200);  // 2 hours
*/
$__ADMIN_PANEL_SESSION_TTL = 7200;

if (defined('ADMIN_PANEL_SESSION_TTL_SECONDS')) {
    $__ADMIN_PANEL_SESSION_TTL = (int)ADMIN_PANEL_SESSION_TTL_SECONDS;
} elseif (defined('ADMIN_SESSION_TTL_SECONDS')) {
    $__ADMIN_PANEL_SESSION_TTL = (int)ADMIN_SESSION_TTL_SECONDS;
} elseif (defined('SESSION_TTL_SECONDS')) {
    $__ADMIN_PANEL_SESSION_TTL = (int)SESSION_TTL_SECONDS;
}

if ($__ADMIN_PANEL_SESSION_TTL < 900) {
    $__ADMIN_PANEL_SESSION_TTL = 900;
}

if ($__ADMIN_PANEL_SESSION_TTL > 604800) {
    $__ADMIN_PANEL_SESSION_TTL = 604800;
}

$GLOBALS['__ADMIN_PANEL_SESSION_TTL'] = $__ADMIN_PANEL_SESSION_TTL;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    @ini_set('session.gc_maxlifetime', (string)$__ADMIN_PANEL_SESSION_TTL);
    @ini_set('session.cookie_lifetime', (string)$__ADMIN_PANEL_SESSION_TTL);
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');

    session_name('zawtopup_admin_v3');
    session_set_cookie_params([
        'lifetime' => $__ADMIN_PANEL_SESSION_TTL,
        'path' => proxy_admin_cookie_path(),
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* =========================
   BASIC HELPERS
========================= */

function proxy_session_ttl(): int
{
    return (int)($GLOBALS['__ADMIN_PANEL_SESSION_TTL'] ?? 86400);
}

function proxy_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function proxy_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        proxy_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function proxy_read_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        proxy_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function proxy_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON', 'ENABLED', 'ACTIVE'], true);
}

function proxy_scheme(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
        if ($proto === 'https' || $proto === 'http') {
            return $proto;
        }
    }

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function proxy_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function proxy_api_base_url(): string
{
    if (function_exists('app_api_url')) {
        return app_api_url();
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/admin/proxy.php';
    $apiPath = dirname(dirname($script));
    return rtrim(proxy_scheme() . '://' . proxy_host() . $apiPath, '/');
}

function proxy_cookie_secure(): bool
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function proxy_unlock_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

function proxy_base_headers(string $sessionToken = ''): array
{
    $headers = [
        'X-APP-KEY' => APP_KEY,
    ];

    if (defined('ADMIN_KEY')) {
        $headers['X-ADMIN-KEY'] = ADMIN_KEY;
    } else {
        $headers['X-ADMIN-KEY'] = APP_KEY;
    }

    if ($sessionToken !== '') {
        $headers['X-SESSION-TOKEN'] = $sessionToken;
    }

    return $headers;
}

function proxy_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = proxy_api_base_url() . '/' . ltrim($relativePath, '/');

    $ch = curl_init();
    $finalHeaders = ['Accept: application/json'];

    foreach ($headers as $k => $v) {
        $finalHeaders[] = $k . ': ' . $v;
    }

    if ($body !== null) {
        $finalHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    if ($body !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 0,
            'json' => null,
            'error' => $err ?: 'Unknown cURL error',
            'raw' => '',
            'url' => $url,
        ];
    }

    $json = json_decode((string)$raw, true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'status' => $status,
            'json' => null,
            'error' => 'Invalid JSON response from internal API',
            'raw' => substr((string)$raw, 0, 800),
            'url' => $url,
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
        'error' => null,
        'raw' => substr((string)$raw, 0, 800),
        'url' => $url,
    ];
}

/* =========================
   COOKIE + SESSION TOKEN
========================= */

function proxy_admin_cookie_path(): string
{
    return function_exists('app_cookie_path')
        ? app_cookie_path('admin')
        : dirname($_SERVER['SCRIPT_NAME'] ?? '/api/admin/proxy.php');
}

function proxy_admin_token_cookie_name(): string
{
    return 'zawtopup_admin_token_v3';
}

function proxy_admin_token_exp_cookie_name(): string
{
    return 'zawtopup_admin_token_exp_v3';
}

function proxy_admin_trust_cookie_name(): string
{
    return 'zaw_admin_trust_v3';
}

function proxy_cookie_options(int $expires, string $path): array
{
    return [
        'expires' => $expires,
        'path' => $path,
        'domain' => '',
        'secure' => proxy_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function proxy_set_cookie_all_paths(string $name, string $value, int $expires): void
{
    $paths = [
        proxy_admin_cookie_path(),
        '/',
    ];

    foreach ($paths as $path) {
        setcookie($name, $value, proxy_cookie_options($expires, $path));
    }

    if ($expires > time()) {
        $_COOKIE[$name] = $value;
    } else {
        unset($_COOKIE[$name]);
    }
}

function proxy_set_admin_token_cookie(string $sessionToken, ?int $expiresAt = null): void
{
    $sessionToken = trim($sessionToken);

    if ($sessionToken === '') {
        return;
    }

    if ($expiresAt === null || $expiresAt <= time()) {
        $expiresAt = time() + proxy_session_ttl();
    }

    setcookie(proxy_admin_token_cookie_name(), $sessionToken, proxy_cookie_options($expiresAt, proxy_admin_cookie_path()));
    setcookie(proxy_admin_token_exp_cookie_name(), (string)$expiresAt, proxy_cookie_options($expiresAt, proxy_admin_cookie_path()));

    $_COOKIE[proxy_admin_token_cookie_name()] = $sessionToken;
    $_COOKIE[proxy_admin_token_exp_cookie_name()] = (string)$expiresAt;
}

function proxy_delete_admin_token_cookie(): void
{
    proxy_set_cookie_all_paths(proxy_admin_token_cookie_name(), '', time() - 3600);
    proxy_set_cookie_all_paths(proxy_admin_token_exp_cookie_name(), '', time() - 3600);
}

function proxy_delete_admin_trust_cookie(): void
{
    proxy_set_cookie_all_paths(proxy_admin_trust_cookie_name(), '', time() - 3600);
}

function proxy_get_admin_trust_cookie(): string
{
    return trim((string)($_COOKIE[proxy_admin_trust_cookie_name()] ?? ''));
}

function proxy_set_admin_trust_cookie(array $cookieData): void
{
    $selector = trim((string)($cookieData['selector'] ?? ''));
    $token = trim((string)($cookieData['token'] ?? ''));
    $expiresAt = (int)($cookieData['expires_at'] ?? 0);

    if ($selector === '' || $token === '' || $expiresAt <= time()) {
        return;
    }

    $value = $selector . ':' . $token;

    setcookie(proxy_admin_trust_cookie_name(), $value, proxy_cookie_options($expiresAt, proxy_admin_cookie_path()));
    $_COOKIE[proxy_admin_trust_cookie_name()] = $value;
}

function proxy_make_csrf(string $sessionToken): string
{
    $sessionToken = trim($sessionToken);

    if ($sessionToken === '') {
        return '';
    }

    return hash_hmac('sha256', 'admin_csrf|' . $sessionToken, APP_KEY);
}

function proxy_get_local_session_expires_at(): int
{
    $sessionExp = (int)($_SESSION['admin_session_expires_at'] ?? 0);

    if ($sessionExp > 0) {
        return $sessionExp;
    }

    $cookieExp = (int)($_COOKIE[proxy_admin_token_exp_cookie_name()] ?? 0);

    if ($cookieExp > 0) {
        $_SESSION['admin_session_expires_at'] = $cookieExp;
        return $cookieExp;
    }

    return 0;
}

function proxy_local_session_expired(): bool
{
    $expiresAt = proxy_get_local_session_expires_at();

    if ($expiresAt <= 0) {
        return true;
    }

    return $expiresAt <= time();
}

function proxy_session_remaining_seconds(): int
{
    $expiresAt = proxy_get_local_session_expires_at();

    if ($expiresAt <= 0) {
        return 0;
    }

    return max(0, $expiresAt - time());
}

function proxy_store_admin_session(string $sessionToken, array $user): void
{
    $sessionToken = trim($sessionToken);

    if ($sessionToken === '') {
        proxy_response(false, 'SERVER_ERROR', 'Session token missing', [], 500);
    }

    $expiresAt = time() + proxy_session_ttl();

    session_regenerate_id(true);

    $_SESSION['admin_session_token'] = $sessionToken;
    $_SESSION['admin_session_expires_at'] = $expiresAt;
    $_SESSION['admin_user'] = [
        'uid' => (string)($user['uid'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
    ];
    $_SESSION['admin_csrf'] = proxy_make_csrf($sessionToken);

    proxy_set_admin_token_cookie($sessionToken, $expiresAt);
}

function proxy_clear_admin_session(bool $clearTrustedDevice = false): void
{
    unset(
        $_SESSION['admin_session_token'],
        $_SESSION['admin_session_expires_at'],
        $_SESSION['admin_user'],
        $_SESSION['admin_csrf']
    );

    proxy_delete_admin_token_cookie();

    if ($clearTrustedDevice) {
        proxy_delete_admin_trust_cookie();
    }
}

function proxy_get_session_token(): string
{
    $token = trim((string)($_SESSION['admin_session_token'] ?? ''));

    if ($token !== '') {
        if (proxy_local_session_expired()) {
            proxy_clear_admin_session(false);
            return '';
        }

        return $token;
    }

    $cookieToken = trim((string)($_COOKIE[proxy_admin_token_cookie_name()] ?? ''));

    if ($cookieToken === '') {
        return '';
    }

    $cookieExp = (int)($_COOKIE[proxy_admin_token_exp_cookie_name()] ?? 0);

    if ($cookieExp <= time()) {
        proxy_clear_admin_session(false);
        return '';
    }

    $_SESSION['admin_session_token'] = $cookieToken;
    $_SESSION['admin_session_expires_at'] = $cookieExp;
    $_SESSION['admin_csrf'] = proxy_make_csrf($cookieToken);

    return $cookieToken;
}

function proxy_get_csrf(): string
{
    $csrf = trim((string)($_SESSION['admin_csrf'] ?? ''));

    if ($csrf !== '') {
        return $csrf;
    }

    $token = proxy_get_session_token();

    if ($token === '') {
        return '';
    }

    $csrf = proxy_make_csrf($token);
    $_SESSION['admin_csrf'] = $csrf;

    return $csrf;
}

function proxy_require_csrf(): void
{
    $incoming = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $stored = proxy_get_csrf();

    if ($stored === '' || $incoming === '' || !hash_equals($stored, $incoming)) {
        proxy_response(false, 'FORBIDDEN', 'Invalid CSRF token', [], 403);
    }
}

/* =========================
   ADMIN AUTH
========================= */

function proxy_finalize_admin_login_with_session_token(string $sessionToken): array
{
    $sessionToken = trim($sessionToken);

    if ($sessionToken === '') {
        proxy_response(false, 'SERVER_ERROR', 'Session token missing after login', [], 500);
    }

    $sessionRes = proxy_internal_api_request('GET', 'auth/session.php', null, proxy_base_headers($sessionToken));

    if (!$sessionRes['ok']) {
        $json = $sessionRes['json'] ?? [];

        proxy_response(
            false,
            (string)($json['code'] ?? 'SESSION_EXPIRED'),
            (string)($json['message'] ?? 'Failed to verify admin session'),
            (array)($json['data'] ?? []),
            $sessionRes['status'] > 0 ? $sessionRes['status'] : 401
        );
    }

    $user = (array)($sessionRes['json']['data'] ?? []);
    $role = strtoupper(trim((string)($user['role'] ?? '')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));

    if ($role !== 'ADMIN') {
        proxy_internal_api_request('POST', 'auth/logout.php', [], proxy_base_headers($sessionToken));
        proxy_response(false, 'FORBIDDEN', 'Admin access required', [], 403);
    }

    if ($status !== 'ACTIVE') {
        proxy_response(false, 'FORBIDDEN', 'Admin account is inactive', [], 403);
    }

    proxy_store_admin_session($sessionToken, $user);

    return $user;
}

function proxy_require_admin_login(bool $touch = true): array
{
    $token = proxy_get_session_token();

    if ($token === '') {
        proxy_response(false, 'SESSION_EXPIRED', 'Admin session not found or expired', [], 401);
    }

    if (proxy_local_session_expired()) {
        proxy_clear_admin_session(false);
        proxy_response(false, 'SESSION_EXPIRED', 'Admin session expired. Please login again.', [], 401);
    }

    $res = proxy_internal_api_request('GET', 'auth/session.php', null, proxy_base_headers($token));

    if (!$res['ok']) {
        proxy_clear_admin_session(false);

        $json = $res['json'] ?? [];

        proxy_response(
            false,
            (string)($json['code'] ?? 'SESSION_EXPIRED'),
            (string)($json['message'] ?? 'Session expired'),
            (array)($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 401
        );
    }

    $data = (array)($res['json']['data'] ?? []);
    $role = strtoupper(trim((string)($data['role'] ?? '')));
    $status = strtoupper(trim((string)($data['status'] ?? 'INACTIVE')));

    if ($role !== 'ADMIN') {
        proxy_internal_api_request('POST', 'auth/logout.php', [], proxy_base_headers($token));
        proxy_clear_admin_session(false);
        proxy_response(false, 'FORBIDDEN', 'Admin access required', [], 403);
    }

    if ($status !== 'ACTIVE') {
        proxy_clear_admin_session(false);
        proxy_response(false, 'FORBIDDEN', 'Admin account is inactive', [], 403);
    }

    if ($touch) {
        $expiresAt = proxy_get_local_session_expires_at();

        $_SESSION['admin_session_token'] = $token;
        $_SESSION['admin_session_expires_at'] = $expiresAt;
        $_SESSION['admin_user'] = [
            'uid' => (string)($data['uid'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'phone' => (string)($data['phone'] ?? ''),
            'email' => (string)($data['email'] ?? ''),
            'role' => (string)($data['role'] ?? ''),
            'status' => (string)($data['status'] ?? ''),
        ];
        $_SESSION['admin_csrf'] = proxy_make_csrf($token);

        if ($expiresAt > time()) {
            proxy_set_admin_token_cookie($token, $expiresAt);
        }
    }

    return $data;
}

function proxy_require_admin_token_only(): string
{
    $token = proxy_get_session_token();

    if ($token === '') {
        proxy_response(false, 'SESSION_EXPIRED', 'Admin session not found or expired', [], 401);
    }

    if (proxy_local_session_expired()) {
        proxy_clear_admin_session(false);
        proxy_response(false, 'SESSION_EXPIRED', 'Admin session expired. Please login again.', [], 401);
    }

    return $token;
}

/* =========================
   FORWARD HELPERS
========================= */

function proxy_forward_admin_get(string $relativeAdminPath, array $query = []): void
{
    $token = proxy_require_admin_token_only();

    unset($query['action']);

    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $path = 'admin/' . ltrim($relativeAdminPath, '/');

    if ($qs !== '') {
        $path .= '?' . $qs;
    }

    proxy_unlock_session();

    $res = proxy_internal_api_request('GET', $path, null, proxy_base_headers($token));

    if (!$res['ok']) {
        $json = $res['json'] ?? [];

        proxy_response(
            false,
            (string)($json['code'] ?? 'SERVER_ERROR'),
            (string)($json['message'] ?? 'Admin request failed'),
            (array)($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 500
        );
    }

    $json = $res['json'] ?? [];

    proxy_response(
        true,
        (string)($json['code'] ?? 'SUCCESS'),
        (string)($json['message'] ?? 'Success'),
        (array)($json['data'] ?? []),
        200
    );
}

function proxy_forward_admin_post(string $relativeAdminPath, array $body = []): void
{
    proxy_require_csrf();

    $token = proxy_require_admin_token_only();
    $path = 'admin/' . ltrim($relativeAdminPath, '/');

    proxy_unlock_session();

    $res = proxy_internal_api_request('POST', $path, $body, proxy_base_headers($token));

    if (!$res['ok']) {
        $json = $res['json'] ?? [];

        proxy_response(
            false,
            (string)($json['code'] ?? 'SERVER_ERROR'),
            (string)($json['message'] ?? 'Admin request failed'),
            (array)($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 500
        );
    }

    $json = $res['json'] ?? [];

    proxy_response(
        true,
        (string)($json['code'] ?? 'SUCCESS'),
        (string)($json['message'] ?? 'Success'),
        (array)($json['data'] ?? []),
        200
    );
}

function proxy_forward_api_post(string $relativePath, array $body = []): void
{
    proxy_require_csrf();

    $token = proxy_require_admin_token_only();
    $path = ltrim($relativePath, '/');

    proxy_unlock_session();

    $res = proxy_internal_api_request('POST', $path, $body, proxy_base_headers($token));
    $json = $res['json'] ?? [];

    if (!$res['ok']) {
        proxy_response(
            false,
            (string)($json['code'] ?? 'SERVER_ERROR'),
            (string)($json['message'] ?? $res['error'] ?? 'Admin request failed'),
            (array)($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 500
        );
    }

    proxy_response(
        true,
        (string)($json['code'] ?? 'SUCCESS'),
        (string)($json['message'] ?? 'Success'),
        (array)($json['data'] ?? []),
        200
    );
}

/* =========================
   COUNTS
========================= */

function proxy_count_items_from_data(array $data): int
{
    foreach (['total', 'total_count', 'count'] as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            return (int)$data[$key];
        }
    }

    if (isset($data['items']) && is_array($data['items'])) {
        return count($data['items']);
    }

    return 0;
}

function proxy_counts_from_existing_topup_lists(): void
{
    $token = proxy_require_admin_token_only();

    proxy_unlock_session();

    $fast = proxy_internal_api_request(
        'GET',
        'admin/topup/status_counts.php',
        null,
        proxy_base_headers($token)
    );

    if ($fast['ok']) {
        $data = (array)($fast['json']['data'] ?? []);

        proxy_response(true, 'SUCCESS', 'Counts loaded', [
            'pending' => (int)($data['pending'] ?? 0),
            'claimed' => (int)($data['claimed'] ?? 0),
            'processing' => (int)($data['processing'] ?? 0),
            'done' => (int)($data['done'] ?? 0),
            'warnings' => [],
        ]);
    }

    $paths = [
        'pending' => 'topup/pending.php',
        'claimed' => 'topup/claimed.php',
        'processing' => 'topup/processing.php',
        'done' => 'topup/done.php',
    ];

    $counts = [
        'pending' => 0,
        'claimed' => 0,
        'processing' => 0,
        'done' => 0,
    ];

    $warnings = [
        'status_counts' => [
            'code' => (string)(($fast['json']['code'] ?? '') ?: 'SERVER_ERROR'),
            'message' => (string)(($fast['json']['message'] ?? '') ?: ($fast['error'] ?? 'status_counts.php failed')),
        ],
    ];

    foreach ($paths as $bucket => $path) {
        $res = proxy_internal_api_request(
            'GET',
            'admin/' . $path . '?page=1&limit=200',
            null,
            proxy_base_headers($token)
        );

        if (!$res['ok']) {
            $json = $res['json'] ?? [];

            $warnings[$bucket] = [
                'code' => (string)($json['code'] ?? 'SERVER_ERROR'),
                'message' => (string)($json['message'] ?? $res['error'] ?? 'Failed to load ' . $bucket),
            ];

            continue;
        }

        $data = (array)($res['json']['data'] ?? []);
        $counts[$bucket] = proxy_count_items_from_data($data);
    }

    proxy_response(true, 'SUCCESS', 'Counts loaded', [
        'pending' => $counts['pending'],
        'claimed' => $counts['claimed'],
        'processing' => $counts['processing'],
        'done' => $counts['done'],
        'warnings' => $warnings,
    ]);
}

/* =========================
   MFS SETTINGS
========================= */

function proxy_mfs_float($value, float $default = 0.0): float
{
    if (is_string($value)) {
        $value = trim(str_replace(',', '', $value));
    }

    return is_numeric($value) ? round((float)$value, 2) : $default;
}

function proxy_mfs_role_fee(array $row, string $role, float $default): float
{
    $role = strtoupper(trim($role));
    $value = $row[$role] ?? null;

    if (is_array($value)) {
        $value = $value['fee_rm'] ?? $value['fixed'] ?? $value['amount'] ?? $value['rm'] ?? null;
    }

    return max(0.0, proxy_mfs_float($value, $default));
}

function proxy_mfs_fee_row(array $body, string $country, string $provider): array
{
    $country = strtoupper(trim($country));
    $provider = mfs_normalize_provider($provider);
    $key = strtolower($country . '_' . $provider);
    $row = is_array($body[$country][$provider] ?? null)
        ? (array)$body[$country][$provider]
        : (is_array($body[$key] ?? null) ? (array)$body[$key] : []);

    if ($country === 'MY') {
        $legacy = proxy_mfs_float($row['fixed'] ?? $row['fee_rm'] ?? $row['amount'] ?? -1.0, -1.0);
        $userFee = proxy_mfs_role_fee($row, 'USER', $legacy >= 0 ? $legacy : 5.00);
        $retailerFee = proxy_mfs_role_fee($row, 'RETAILER', 2.00);
        $subadminFee = proxy_mfs_role_fee($row, 'SUBADMIN', 2.00);
        $adminFee = proxy_mfs_role_fee($row, 'ADMIN', 0.00);

        return [
            'type' => 'fixed',
            'fixed' => $userFee,
            'fee_rm' => $userFee,
            'USER' => $userFee,
            'RETAILER' => $retailerFee,
            'SUBADMIN' => $subadminFee,
            'ADMIN' => $adminFee,
        ];
    }

    $type = strtolower(trim((string)($row['type'] ?? 'fixed')));
    if (!in_array($type, ['fixed', 'percent'], true)) {
        $type = 'fixed';
    }

    return [
        'type' => $type,
        'fixed' => max(0.0, proxy_mfs_float($row['fixed'] ?? $row['fixed_fee'] ?? 0.0)),
        'percent' => max(0.0, proxy_mfs_float($row['percent'] ?? $row['percent_fee'] ?? 0.0)),
        'min_fee' => max(0.0, proxy_mfs_float($row['min_fee'] ?? 0.0)),
        'max_fee' => max(0.0, proxy_mfs_float($row['max_fee'] ?? 0.0)),
    ];
}

function proxy_mfs_target_uid_from_body(array $body): string
{
    $target = trim((string)($body['uid'] ?? $body['target_uid'] ?? $body['phone'] ?? $body['target_phone'] ?? $body['number'] ?? ''));

    if ($target === '') {
        return '';
    }

    $row = fb_get('USERS/' . $target);
    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $target));
    }

    $phone = function_exists('normalize_login_phone')
        ? normalize_login_phone($target)
        : preg_replace('/\D+/', '', $target);
    $phone = trim((string)$phone);

    if ($phone !== '') {
        $indexedUid = fb_get('USER_INDEX/PHONE/' . $phone);
        if (is_string($indexedUid) && trim($indexedUid) !== '') {
            return trim($indexedUid);
        }
    }

    return '';
}

/* =========================
   ROUTES
========================= */

$action = trim((string)($_GET['action'] ?? ''));

switch ($action) {
    case 'login':
        proxy_require_method('POST');

        $body = proxy_read_json_body();

        $phone = trim((string)($body['phone'] ?? ''));
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $trustDevice = proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string)($body['device_id'] ?? 'ADMIN_WEB'));
        $deviceName = trim((string)($body['device_name'] ?? 'Admin Dashboard'));
        $trustedDeviceCookie = proxy_get_admin_trust_cookie();

        if ($phone === '' || $password === '') {
            proxy_response(false, 'VALIDATION_ERROR', 'Phone and password are required', [], 422);
        }

        $loginRes = proxy_internal_api_request('POST', 'auth/admin_login_start.php', [
            'phone' => $phone,
            'phone_country' => $phoneCountry,
            'password' => $password,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'trust_device' => $trustDevice,
            'trusted_device_cookie' => $trustedDeviceCookie,
            'client_ip' => security_client_ip(),
            'ip_country' => auth_request_ip_country(),
            'user_agent' => security_user_agent(),
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
        ], proxy_base_headers());

        if (!$loginRes['ok']) {
            $json = $loginRes['json'] ?? [];

            proxy_response(
                false,
                (string)($json['code'] ?? 'LOGIN_FAILED'),
                (string)($json['message'] ?? 'Login failed'),
                (array)($json['data'] ?? []),
                $loginRes['status'] > 0 ? $loginRes['status'] : 401
            );
        }

        $data = (array)($loginRes['json']['data'] ?? []);

        if (!empty($data['require_otp'])) {
            proxy_response(true, 'OTP_REQUIRED', (string)($loginRes['json']['message'] ?? 'OTP verification required'), [
                'require_otp' => true,
                'pre_auth_token' => (string)($data['pre_auth_token'] ?? ''),
                'otp_request_id' => (string)($data['otp_request_id'] ?? ''),
                'masked_phone' => (string)($data['masked_phone'] ?? ''),
                'expires_in_seconds' => (int)($data['expires_in_seconds'] ?? 300),
            ]);
        }

        $sessionToken = trim((string)($data['session_token'] ?? ''));
        proxy_finalize_admin_login_with_session_token($sessionToken);

        if (!empty($data['trusted_device_cookie']) && is_array($data['trusted_device_cookie'])) {
            proxy_set_admin_trust_cookie($data['trusted_device_cookie']);
        }

        proxy_response(true, 'SUCCESS', 'Admin login successful', [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
            'user' => (array)($_SESSION['admin_user'] ?? []),
            'csrf' => proxy_get_csrf(),
            'session_expires_at' => proxy_get_local_session_expires_at(),
            'session_remaining_seconds' => proxy_session_remaining_seconds(),
        ]);
        break;

    case 'login_verify_otp':
        proxy_require_method('POST');

        $body = proxy_read_json_body();

        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
        $otp = trim((string)($body['otp'] ?? ''));
        $trustDevice = proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string)($body['device_id'] ?? 'ADMIN_WEB'));
        $deviceName = trim((string)($body['device_name'] ?? 'Admin Dashboard'));

        if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
            proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
        }

        $verifyRes = proxy_internal_api_request('POST', 'auth/admin_login_verify_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
            'otp' => $otp,
            'trust_device' => $trustDevice,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
        ], proxy_base_headers());

        if (!$verifyRes['ok']) {
            $json = $verifyRes['json'] ?? [];

            proxy_response(
                false,
                (string)($json['code'] ?? 'OTP_VERIFY_FAILED'),
                (string)($json['message'] ?? 'OTP verification failed'),
                (array)($json['data'] ?? []),
                $verifyRes['status'] > 0 ? $verifyRes['status'] : 400
            );
        }

        $data = (array)($verifyRes['json']['data'] ?? []);
        $sessionToken = trim((string)($data['session_token'] ?? ''));

        proxy_finalize_admin_login_with_session_token($sessionToken);

        if (!empty($data['trusted_device_cookie']) && is_array($data['trusted_device_cookie'])) {
            proxy_set_admin_trust_cookie($data['trusted_device_cookie']);
        }

        proxy_response(true, 'SUCCESS', 'OTP verified successfully', [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
            'user' => (array)($_SESSION['admin_user'] ?? []),
            'csrf' => proxy_get_csrf(),
            'session_expires_at' => proxy_get_local_session_expires_at(),
            'session_remaining_seconds' => proxy_session_remaining_seconds(),
        ]);
        break;

    case 'login_resend_otp':
        proxy_require_method('POST');

        $body = proxy_read_json_body();

        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string)($body['otp_request_id'] ?? ''));

        if ($preAuthToken === '' || $otpRequestId === '') {
            proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
        }

        $resendRes = proxy_internal_api_request('POST', 'auth/admin_login_resend_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
        ], proxy_base_headers());

        if (!$resendRes['ok']) {
            $json = $resendRes['json'] ?? [];

            proxy_response(
                false,
                (string)($json['code'] ?? 'OTP_RESEND_FAILED'),
                (string)($json['message'] ?? 'Failed to resend OTP'),
                (array)($json['data'] ?? []),
                $resendRes['status'] > 0 ? $resendRes['status'] : 400
            );
        }

        $data = (array)($resendRes['json']['data'] ?? []);

        proxy_response(true, 'SUCCESS', 'OTP resent successfully', [
            'require_otp' => true,
            'pre_auth_token' => (string)($data['pre_auth_token'] ?? $preAuthToken),
            'otp_request_id' => (string)($data['otp_request_id'] ?? $otpRequestId),
            'masked_phone' => (string)($data['masked_phone'] ?? ''),
            'expires_in_seconds' => (int)($data['expires_in_seconds'] ?? 300),
        ]);
        break;

    case 'logout':
        proxy_require_method('POST');
        proxy_require_csrf();

        $token = proxy_get_session_token();

        if ($token !== '') {
            proxy_internal_api_request('POST', 'auth/logout.php', [], proxy_base_headers($token));
        }

        proxy_clear_admin_session(true);
        session_regenerate_id(true);

        proxy_response(true, 'SUCCESS', 'Logout successful', []);
        break;

    case 'me':
        proxy_require_method('GET');

        $user = proxy_require_admin_login(true);

        proxy_response(true, 'SUCCESS', 'Session valid', [
            'user' => $user,
            'csrf' => proxy_get_csrf(),
            'session_expires_at' => proxy_get_local_session_expires_at(),
            'session_remaining_seconds' => proxy_session_remaining_seconds(),
        ]);
        break;
        
        
        case 'security_check':
        proxy_require_method('GET');

        $user = proxy_require_admin_login(true);

        $ip = function_exists('security_client_ip') ? security_client_ip() : '';
        $risk = [];

        if (function_exists('security_detect_ip_risk')) {
            $risk = security_detect_ip_risk($ip);
        }

        proxy_response(true, 'SUCCESS', 'Admin security check passed', [
            'session_name' => session_name(),
            'session_active' => true,
            'session_expires_at' => proxy_get_local_session_expires_at(),
            'session_remaining_seconds' => proxy_session_remaining_seconds(),
            'user' => [
                'uid' => (string)($user['uid'] ?? ''),
                'name' => (string)($user['name'] ?? ''),
                'role' => (string)($user['role'] ?? ''),
                'status' => (string)($user['status'] ?? ''),
            ],
            'security' => [
                'ip_family' => function_exists('security_ip_family') && $ip !== '' ? security_ip_family($ip) : 'UNKNOWN',
                'support_code' => function_exists('security_ip_hash') && $ip !== '' ? substr(security_ip_hash($ip), 0, 12) : '',
                'verdict' => (string)($risk['verdict'] ?? 'UNKNOWN'),
                'blocked' => (bool)($risk['blocked'] ?? false),
                'risk_type' => (string)($risk['risk_type'] ?? 'UNKNOWN'),
                'risk_score' => (int)($risk['risk_score'] ?? 0),
                'source' => (string)($risk['source'] ?? ''),
                'reason' => (string)($risk['reason'] ?? ''),
            ],
        ]);
        break;



    case 'counts':
        proxy_require_method('GET');
        proxy_counts_from_existing_topup_lists();
        break;

    case 'topups':
        proxy_require_method('GET');

        $bucket = strtolower(trim((string)($_GET['bucket'] ?? 'pending')));

        $allowed = [
            'pending' => 'topup/pending.php',
            'claimed' => 'topup/claimed.php',
            'processing' => 'topup/processing.php',
            'done' => 'topup/done.php',
        ];

        if (!isset($allowed[$bucket])) {
            proxy_response(false, 'VALIDATION_ERROR', 'Invalid topup bucket', [], 422);
        }

        proxy_forward_admin_get($allowed[$bucket], [
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => (int)($_GET['limit'] ?? 50),
        ]);
        break;

    case 'topup_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('topup/get.php', [
            'request_id' => trim((string)($_GET['request_id'] ?? '')),
        ]);
        break;

    case 'topup_create':
        proxy_require_method('POST');
        proxy_forward_admin_post('topup/create.php', proxy_read_json_body());
        break;

    case 'topup_success':
        proxy_require_method('POST');
        proxy_forward_admin_post('topup/mark_success.php', proxy_read_json_body());
        break;

    case 'topup_failed':
        proxy_require_method('POST');
        proxy_forward_admin_post('topup/mark_failed.php', proxy_read_json_body());
        break;

    case 'bundles':
        proxy_require_method('GET');
        proxy_forward_admin_get('bundle/pending.php');
        break;

    case 'bundles_done':
        proxy_require_method('GET');
        proxy_forward_admin_get('bundle/done.php');
        break;

    case 'bundle_success':
        proxy_require_method('POST');
        proxy_forward_admin_post('bundle/mark_success.php', proxy_read_json_body());
        break;

    case 'bundle_failed':
        proxy_require_method('POST');
        proxy_forward_admin_post('bundle/mark_failed.php', proxy_read_json_body());
        break;
        
        
    case 'mfs_create':
        proxy_require_method('POST');
        proxy_forward_admin_post('mfs/create.php', proxy_read_json_body());
        break;

    case 'mfs_preview':
        proxy_require_method('POST');
        proxy_require_csrf();
        proxy_require_admin_login(true);

        if (!function_exists('mfs_preview_payload')) {
            proxy_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS preview helper missing', [], 500);
        }

        $body = proxy_read_json_body();
        $targetUid = proxy_mfs_target_uid_from_body($body);

        if ($targetUid === '') {
            proxy_response(false, 'USER_NOT_FOUND', 'Target user not found', [], 404);
        }

        $res = mfs_preview_payload($targetUid, $body);

        if (empty($res['ok'])) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');
            $httpStatus = 500;

            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE', 'SERVICE_NOT_ALLOWED', 'PROVIDER_DISABLED'], true)) {
                $httpStatus = 422;
            } elseif ($code === 'ACCOUNT_INACTIVE') {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            proxy_response(false, $code, (string)($res['message'] ?? 'Failed to preview MFS request'), (array)($res['data'] ?? []), $httpStatus);
        }

        proxy_response(true, 'SUCCESS', 'MFS preview ready', (array)($res['data'] ?? []));
        break;

    case 'get_mfs_settings':
    case 'get_mfs_fee_rate_settings':
    case 'mfs_settings_get':
        proxy_require_method('GET');
        proxy_require_admin_login(true);

        proxy_response(true, 'SUCCESS', 'MFS settings loaded', [
            'settings' => function_exists('mfs_public_settings') ? mfs_public_settings() : [],
            'raw' => is_array(fb_get('MFS_SETTINGS')) ? fb_get('MFS_SETTINGS') : [],
        ]);
        break;

    case 'save_mfs_settings':
    case 'save_mfs_fee_rate_settings':
    case 'mfs_settings_save':
        proxy_require_method('POST');
        proxy_require_csrf();
        $adminUser = proxy_require_admin_login(true);
        $body = proxy_read_json_body();

        $rate = proxy_mfs_float($body['rate_myr_bdt'] ?? $body['myr_to_bdt_rate'] ?? 31.00, 31.00);
        $rateValidation = zpay_validate_myr_to_bdt_rate($rate);
        if (empty($rateValidation['ok'])) {
            proxy_response(false, (string)$rateValidation['code'], (string)$rateValidation['message'], ['field' => 'rate_myr_bdt'], 422);
        }

        $feesBody = is_array($body['fees'] ?? null) ? (array)$body['fees'] : $body;
        $settings = [
            'rate_myr_bdt' => $rate,
            'myr_to_bdt_rate' => $rate,
            'fees' => [
                'MY' => [
                    'BKASH' => proxy_mfs_fee_row($feesBody, 'MY', 'BKASH'),
                    'NAGAD' => proxy_mfs_fee_row($feesBody, 'MY', 'NAGAD'),
                ],
                'BD' => [
                    'BKASH' => proxy_mfs_fee_row($feesBody, 'BD', 'BKASH'),
                    'NAGAD' => proxy_mfs_fee_row($feesBody, 'BD', 'NAGAD'),
                ],
            ],
            'updated_at' => time(),
            'updated_by_uid' => (string)($adminUser['uid'] ?? ''),
            'updated_by_role' => 'ADMIN',
        ];

        $rateSave = zpay_save_myr_to_bdt_rate($rate, (string)($adminUser['uid'] ?? ''), 'ADMIN_PANEL');
        if (empty($rateSave['ok'])) {
            proxy_response(false, (string)($rateSave['code'] ?? 'RATE_SAVE_FAILED'), (string)($rateSave['message'] ?? 'Failed to save Ringgit rate'), [], 500);
        }

        if (!fb_patch('MFS_SETTINGS', $settings)) {
            proxy_response(false, 'SERVER_ERROR', 'Failed to save MFS settings', [], 500);
        }

        if (function_exists('mfs_config')) {
            mfs_config(true);
        }

        proxy_response(true, 'SUCCESS', 'MFS settings saved', [
            'settings' => function_exists('mfs_public_settings') ? mfs_public_settings() : $settings,
            'raw' => $settings,
            'rate_notification' => (array)($rateSave['data']['notification'] ?? []),
        ]);
        break;

    case 'mfs_pending':
        proxy_require_method('GET');
        proxy_forward_admin_get('mfs/pending.php', [
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => (int)($_GET['limit'] ?? 50),
            'service' => trim((string)($_GET['service'] ?? '')),
            'service_type' => trim((string)($_GET['service_type'] ?? '')),
            'country' => trim((string)($_GET['country'] ?? '')),
            'uid' => trim((string)($_GET['uid'] ?? '')),
            'number' => trim((string)($_GET['number'] ?? '')),
        ]);
        break;

    case 'mfs_processing':
        proxy_require_method('GET');
        proxy_forward_admin_get('mfs/processing.php', [
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => (int)($_GET['limit'] ?? 50),
            'service' => trim((string)($_GET['service'] ?? '')),
            'service_type' => trim((string)($_GET['service_type'] ?? '')),
            'country' => trim((string)($_GET['country'] ?? '')),
            'uid' => trim((string)($_GET['uid'] ?? '')),
            'number' => trim((string)($_GET['number'] ?? '')),
        ]);
        break;

    case 'mfs_done':
        proxy_require_method('GET');
        proxy_forward_admin_get('mfs/done.php', [
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => (int)($_GET['limit'] ?? 50),
            'service' => trim((string)($_GET['service'] ?? '')),
            'service_type' => trim((string)($_GET['service_type'] ?? '')),
            'country' => trim((string)($_GET['country'] ?? '')),
            'uid' => trim((string)($_GET['uid'] ?? '')),
            'number' => trim((string)($_GET['number'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ]);
        break;

    case 'mfs_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('mfs/get.php', [
            'request_id' => trim((string)($_GET['request_id'] ?? '')),
        ]);
        break;

    case 'mfs_mark_processing':
        proxy_require_method('POST');
        proxy_forward_admin_post('mfs/mark_processing.php', proxy_read_json_body());
        break;

    case 'mfs_success':
        proxy_require_method('POST');
        proxy_forward_admin_post('mfs/mark_success.php', proxy_read_json_body());
        break;

    case 'mfs_failed':
        proxy_require_method('POST');
        proxy_forward_admin_post('mfs/mark_failed.php', proxy_read_json_body());
        break;
        
        

    case 'bundle_offers':
        proxy_require_method('GET');
        proxy_forward_admin_get('bundle/offers.php', [
            'include_inactive' => trim((string)($_GET['include_inactive'] ?? '1')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ]);
        break;

    case 'bundle_offer_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('bundle/offer_get.php', [
            'offer_id' => trim((string)($_GET['offer_id'] ?? '')),
        ]);
        break;

    case 'bundle_offer_save':
        proxy_require_method('POST');
        proxy_forward_admin_post('bundle/offer_save.php', proxy_read_json_body());
        break;

    case 'bundle_offer_delete':
        proxy_require_method('POST');
        proxy_forward_admin_post('bundle/offer_delete.php', proxy_read_json_body());
        break;

    case 'bundle_offer_expire':
        proxy_require_method('POST');
        proxy_forward_admin_post('bundle/offer_expire.php', proxy_read_json_body());
        break;

    case 'users':
        proxy_require_method('GET');
        proxy_forward_admin_get('users/list.php', [
            'page' => max(1, (int)($_GET['page'] ?? 1)),
            'limit' => max(1, min(100, (int)($_GET['limit'] ?? 50))),
            'search' => trim((string)($_GET['search'] ?? '')),
            'role' => trim((string)($_GET['role'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
        ]);
        break;

    case 'user_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('users/get.php', [
            'uid' => trim((string)($_GET['uid'] ?? '')),
        ]);
        break;

    case 'user_create':
        proxy_require_method('POST');
        proxy_forward_admin_post('users/create.php', proxy_read_json_body());
        break;

    case 'user_update':
        proxy_require_method('POST');
        proxy_forward_admin_post('users/update.php', proxy_read_json_body());
        break;

    case 'user_currency_preview':
        proxy_require_method('POST');
        proxy_require_csrf();
        proxy_require_admin_login(true);

        $body = proxy_read_json_body();
        $uid = trim((string)($body['uid'] ?? ''));
        $country = strtoupper(trim((string)(
            $body['pricing_country']
            ?? $body['market_country']
            ?? $body['service_country']
            ?? $body['country']
            ?? $body['country_code']
            ?? ''
        )));

        if ($uid === '' || !in_array($country, ['BD', 'MY'], true)) {
            proxy_response(false, 'VALIDATION_ERROR', 'uid and pricing_country are required', [], 422);
        }

        $preview = account_currency_preview_for_uid($uid, $country);
        if (empty($preview['ok'])) {
            proxy_response(false, (string)($preview['code'] ?? 'PREVIEW_FAILED'), (string)($preview['message'] ?? 'Failed to preview currency conversion'), [], 422);
        }

        proxy_response(true, 'SUCCESS', 'Currency conversion preview ready', [
            'preview' => $preview,
        ]);
        break;

    case 'user_approve':
        proxy_require_method('POST');
        proxy_forward_admin_post('users/approve.php', proxy_read_json_body());
        break;

    case 'user_reject':
        proxy_require_method('POST');
        proxy_forward_admin_post('users/reject.php', proxy_read_json_body());
        break;

    case 'wallet_add':
        proxy_require_method('POST');
        proxy_forward_api_post('wallet_add_balance.php', proxy_read_json_body());
        break;

    case 'wallet_deduct':
        proxy_require_method('POST');
        proxy_forward_api_post('wallet_deduct_send_otp.php', proxy_read_json_body());
        break;

    case 'wallet_deduct_send_otp':
        proxy_require_method('POST');
        proxy_forward_api_post('wallet_deduct_send_otp.php', proxy_read_json_body());
        break;

    case 'wallet_deduct_confirm':
        proxy_require_method('POST');
        proxy_forward_api_post('wallet_deduct_confirm.php', proxy_read_json_body());
        break;

    case 'wallet_ledger':
        proxy_require_method('GET');
        proxy_forward_admin_get('wallet/ledger.php', [
            'uid' => trim((string)($_GET['uid'] ?? '')),
            'month' => trim((string)($_GET['month'] ?? '')),
        ]);
        break;

    case 'wallet_history':
    case 'transfer_history':
        proxy_require_method('GET');
        proxy_forward_admin_get('wallet/history.php', [
            'month' => trim((string)($_GET['month'] ?? '')),
            'receiver' => trim((string)($_GET['receiver'] ?? '')),
            'sender_role' => trim((string)($_GET['sender_role'] ?? '')),
            'receiver_role' => trim((string)($_GET['receiver_role'] ?? '')),
            'type' => trim((string)($_GET['type'] ?? '')),
            'limit' => (int)($_GET['limit'] ?? 200),
        ]);
        break;

    case 'add_money_settings':
        proxy_require_method('GET');
        proxy_require_admin_login(true);

        proxy_response(true, 'SUCCESS', 'Add money settings loaded', [
            'settings' => add_money_settings(),
            'accounts' => add_money_list_payment_accounts(null, true),
        ]);
        break;

    case 'add_money_settings_save':
        proxy_require_method('POST');
        proxy_require_csrf();

        $adminUser = proxy_require_admin_login(true);
        $body = proxy_read_json_body();
        $res = add_money_save_settings($body, trim((string)($adminUser['uid'] ?? '')));

        if (empty($res['ok'])) {
            proxy_response(false, (string)($res['code'] ?? 'SAVE_FAILED'), (string)($res['message'] ?? 'Failed to save add money settings'), [], 500);
        }

        proxy_response(true, 'SUCCESS', 'Add money settings saved', [
            'settings' => (array)($res['data'] ?? add_money_settings()),
            'accounts' => add_money_list_payment_accounts(null, true),
        ]);
        break;

    case 'add_money_account_save':
        proxy_require_method('POST');
        proxy_require_csrf();

        $adminUser = proxy_require_admin_login(true);
        $body = proxy_read_json_body();
        $res = add_money_save_payment_account($body, trim((string)($adminUser['uid'] ?? '')));

        if (empty($res['ok'])) {
            proxy_response(false, (string)($res['code'] ?? 'SAVE_FAILED'), (string)($res['message'] ?? 'Failed to save payment account'), [], 422);
        }

        proxy_response(true, 'SUCCESS', 'Payment account saved', [
            'account' => (array)($res['data'] ?? []),
            'accounts' => add_money_list_payment_accounts(null, true),
        ]);
        break;

    case 'add_money_requests':
        proxy_require_method('GET');
        proxy_require_admin_login(true);

        $limit = max(1, min(300, (int)($_GET['limit'] ?? 150)));
        $filters = [
            'status' => trim((string)($_GET['status'] ?? '')),
            'country' => trim((string)($_GET['country'] ?? '')),
            'method' => trim((string)($_GET['method'] ?? '')),
        ];

        proxy_response(true, 'SUCCESS', 'Add money requests loaded', [
            'items' => add_money_list_admin($filters, $limit),
            'filters' => $filters,
            'limit' => $limit,
        ]);
        break;

    case 'add_money_approve':
    case 'add_money_reject':
        proxy_require_method('POST');
        proxy_require_csrf();

        $adminUser = proxy_require_admin_login(true);
        $body = proxy_read_json_body();
        $requestId = trim((string)($body['request_id'] ?? ''));
        $reason = trim((string)($body['reason'] ?? $body['reject_reason'] ?? ''));
        $approve = $action === 'add_money_approve';
        $res = add_money_process_request(
            $requestId,
            $approve ? 'APPROVE' : 'REJECT',
            trim((string)($adminUser['uid'] ?? '')),
            'ADMIN',
            $reason
        );

        if (empty($res['ok'])) {
            $code = (string)($res['code'] ?? 'PROCESS_FAILED');
            $httpStatus = 500;
            if ($code === 'NOT_FOUND') {
                $httpStatus = 404;
            } elseif ($code === 'ALREADY_PROCESSED') {
                $httpStatus = 409;
            } elseif ($code === 'VALIDATION_ERROR') {
                $httpStatus = 422;
            }

            proxy_response(false, $code, (string)($res['message'] ?? 'Failed to process add money request'), (array)($res['data'] ?? []), $httpStatus);
        }

        proxy_response(true, 'SUCCESS', $approve ? 'Add money request approved' : 'Add money request rejected', (array)($res['data'] ?? []));
        break;

    case 'support_list':
        proxy_require_method('GET');
        proxy_forward_admin_get('support/list.php', [
            'status' => trim((string)($_GET['status'] ?? '')),
            'query' => trim((string)($_GET['query'] ?? '')),
            'limit' => (int)($_GET['limit'] ?? 100),
        ]);
        break;

    case 'support_details':
        proxy_require_method('GET');
        proxy_forward_admin_get('support/details.php', [
            'ticket_id' => trim((string)($_GET['ticket_id'] ?? '')),
        ]);
        break;

    case 'support_reply':
        proxy_require_method('POST');
        proxy_forward_admin_post('support/reply.php', proxy_read_json_body());
        break;

    case 'support_reply_upload':
        proxy_require_method('POST');
        proxy_require_csrf();

        $adminUser = proxy_require_admin_login(true);
        $ticketId = support_clean_text($_POST['ticket_id'] ?? '', 40);
        $message = (string)($_POST['message'] ?? '');
        $idem = support_clean_text($_POST['idempotency_key'] ?? '', 120);
        if ($idem !== '') {
            $existing = fb_get('SUPPORT_ADMIN_REPLY_IDEMPOTENCY/' . $ticketId . '/' . hash('sha256', $idem));
            if (is_array($existing) && (string)($existing['message_id'] ?? '') !== '') {
                $ticket = support_read_ticket($ticketId);
                if ($ticket !== []) {
                    $payload = support_details_payload($ticket);
                    proxy_response(true, 'ADMIN_SUPPORT_REPLY_DUPLICATE', 'Support reply already sent.', [
                        'ticket' => $payload['ticket'],
                        'messages' => $payload['messages'],
                        'attachments' => $payload['attachments'],
                    ]);
                }
            }
        }
        $result = support_reply(['user' => $adminUser], $ticketId, $message, $_FILES, 'ADMIN', [
            'idempotency_key' => $idem,
            'source' => 'ADMIN_PANEL',
        ]);
        if (empty($result['ok'])) {
            proxy_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
        }
        if ($idem !== '') {
            $messages = (array)($result['messages'] ?? []);
            $last = end($messages);
            fb_put('SUPPORT_ADMIN_REPLY_IDEMPOTENCY/' . $ticketId . '/' . hash('sha256', $idem), [
                'message_id' => is_array($last) ? (string)($last['message_id'] ?? '') : '',
                'created_at' => support_now(),
            ]);
        }
        proxy_response(true, 'ADMIN_SUPPORT_REPLY_SENT', 'Support reply sent.', [
            'ticket' => $result['ticket'],
            'messages' => $result['messages'],
            'attachments' => $result['attachments'],
        ]);
        break;

    case 'support_status':
        proxy_require_method('POST');
        proxy_forward_admin_post('support/status.php', proxy_read_json_body());
        break;

    case 'support_config_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('support/config.php');
        break;

    case 'support_config_save':
        proxy_require_method('POST');
        proxy_forward_admin_post('support/config_save.php', proxy_read_json_body());
        break;

    case 'support_categories':
        proxy_require_method('GET');
        proxy_forward_admin_get('support/categories.php');
        break;

    case 'support_category_save':
        proxy_require_method('POST');
        proxy_forward_admin_post('support/category_save.php', proxy_read_json_body());
        break;

    case 'support_attachment':
        proxy_require_method('GET');
        proxy_require_admin_login(true);

        $ticketId = support_clean_text($_GET['ticket_id'] ?? '', 40);
        $attachmentId = support_clean_text($_GET['attachment_id'] ?? '', 40);
        $ticket = support_read_ticket($ticketId);
        if ($ticket === []) {
            proxy_response(false, 'SUPPORT_TICKET_NOT_FOUND', 'Support ticket was not found.', [], 404);
        }
        $row = fb_get('SUPPORT_ATTACHMENTS/' . $ticketId . '/' . $attachmentId);
        if (!is_array($row) || (string)($row['ticket_id'] ?? '') !== $ticketId) {
            proxy_response(false, 'SUPPORT_ATTACHMENT_NOT_FOUND', 'Attachment was not found.', [], 404);
        }
        $mime = (string)($row['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            proxy_response(false, 'SUPPORT_ATTACHMENT_TYPE_INVALID', 'Attachment type is not supported.', [], 415);
        }
        $path = support_attachment_absolute_path($row);
        if ($path === '' || !is_file($path)) {
            proxy_response(false, 'SUPPORT_ATTACHMENT_NOT_FOUND', 'Attachment was not found.', [], 404);
        }
        $fileName = support_clean_text($row['original_name'] ?? 'attachment', 120) ?: 'attachment';
        if (function_exists('header_remove')) {
            header_remove('Content-Type');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: inline; filename="' . addcslashes($fileName, "\"\\") . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile($path);
        exit;

    case 'operators':
        proxy_require_method('GET');
        proxy_forward_admin_get('operators/list.php');
        break;

    case 'operator_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('operators/get.php', [
            'operator' => trim((string)($_GET['operator'] ?? '')),
        ]);
        break;

    case 'operator_save':
        proxy_require_method('POST');
        proxy_forward_admin_post('operators/save.php', proxy_read_json_body());
        break;

    case 'topup_country_save':
        proxy_require_method('POST');
        proxy_forward_admin_post('operators/country_save.php', proxy_read_json_body());
        break;

    case 'app_config_get':
        proxy_require_method('GET');
        proxy_forward_admin_get('config/get.php');
        break;

    case 'app_config_save':
        proxy_require_method('POST');
        proxy_forward_admin_post('config/save.php', proxy_read_json_body());
        break;

    case 'workers_status':
        proxy_require_method('GET');
        proxy_forward_admin_get('workers/status.php', [
            'limit' => (int)($_GET['limit'] ?? 100),
        ]);
        break;

    case 'zsky24_impressions_queue':
        proxy_require_method('GET');
        proxy_forward_admin_get('znews/ads/impressions/queue.php', [
            'limit' => (int)($_GET['limit'] ?? 20),
            'cursor' => trim((string)($_GET['cursor'] ?? '')),
        ]);
        break;

    case 'zsky24_impression_details':
        proxy_require_method('GET');
        proxy_forward_admin_get('znews/ads/impressions/details.php', [
            'impression_id' => trim((string)($_GET['impression_id'] ?? '')),
        ]);
        break;

    case 'zsky24_impression_recheck':
        proxy_require_method('POST');
        proxy_forward_admin_post('znews/ads/impressions/recheck.php', proxy_read_json_body());
        break;

    case 'zsky24_settlements_queue':
        proxy_require_method('GET');
        proxy_forward_admin_get('znews/ads/settlements/queue.php', [
            'limit' => (int)($_GET['limit'] ?? 20),
            'cursor' => trim((string)($_GET['cursor'] ?? '')),
        ]);
        break;

    case 'zsky24_settlement_settle':
        proxy_require_method('POST');
        proxy_forward_admin_post('znews/ads/settlements/settle.php', proxy_read_json_body());
        break;

    case 'zsky24_transfers_queue':
        proxy_require_method('GET');
        proxy_forward_admin_get('znews/transfers/queue.php', [
            'limit' => (int)($_GET['limit'] ?? 20),
            'cursor' => trim((string)($_GET['cursor'] ?? '')),
        ]);
        break;

    case 'zsky24_transfer_details':
        proxy_require_method('GET');
        proxy_forward_admin_get('znews/transfers/details.php', [
            'request_id' => trim((string)($_GET['request_id'] ?? '')),
        ]);
        break;

    case 'zsky24_transfer_approve':
        proxy_require_method('POST');
        proxy_forward_admin_post('znews/transfers/approve.php', proxy_read_json_body());
        break;

    case 'zsky24_transfer_reject':
        proxy_require_method('POST');
        proxy_forward_admin_post('znews/transfers/reject.php', proxy_read_json_body());
        break;

    case 'subapi_create_key':
        proxy_require_method('POST');
        proxy_forward_admin_post('subadmin_api/create_key.php', proxy_read_json_body());
        break;

    case 'subapi_list_keys':
        proxy_require_method('GET');
        proxy_forward_admin_get('subadmin_api/list_keys.php', [
            'uid' => trim((string)($_GET['uid'] ?? '')),
        ]);
        break;

    case 'subapi_update_key_status':
        proxy_require_method('POST');
        proxy_forward_admin_post('subadmin_api/update_key_status.php', proxy_read_json_body());
        break;

    case 'subapi_request_logs':
        proxy_require_method('GET');
        proxy_forward_admin_get('subadmin_api/request_logs.php', [
            'uid' => trim((string)($_GET['uid'] ?? '')),
            'limit' => (int)($_GET['limit'] ?? 100),
        ]);
        break;

    default:
        proxy_response(false, 'NOT_FOUND', 'Unknown proxy action', [], 404);
}
