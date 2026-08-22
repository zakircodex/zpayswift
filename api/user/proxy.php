<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/roles.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/bundle.php';
require_once dirname(__DIR__) . '/lib/mfs.php';
require_once dirname(__DIR__) . '/lib/add_money.php';
require_once dirname(__DIR__) . '/lib/favorites.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    session_name('zawtopup_user');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* =========================================================
   Basic Response / Request Helpers
========================================================= */

function user_proxy_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function user_proxy_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        user_proxy_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function user_proxy_read_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        user_proxy_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function user_proxy_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_proxy_month_key(?int $ts = null): string
{
    if ($ts === null && function_exists('month_key')) {
        return (string)month_key();
    }

    return date('Y-m', $ts ?? user_proxy_now());
}

function user_proxy_valid_month_key($month = null): string
{
    $month = trim((string)($month ?? ''));

    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        return $month;
    }

    return user_proxy_month_key();
}

function user_proxy_round_money($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
    }

    return round((float)$value, 2);
}

function user_proxy_make_id(string $prefix = 'UR'): string
{
    if ($prefix === 'BR' && function_exists('bundle_make_request_id')) {
        return (string)bundle_make_request_id();
    }

    if ($prefix === 'TR' && function_exists('make_topup_request_id')) {
        return (string)make_topup_request_id();
    }
    
    
    if ($prefix === 'MFS') {
    return 'MFS' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
    

    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function user_proxy_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true);
}

function user_proxy_session_check_interval(): int
{
    if (defined('USER_PROXY_SESSION_CHECK_INTERVAL_SECONDS')) {
        return max(5, (int)USER_PROXY_SESSION_CHECK_INTERVAL_SECONDS);
    }

    return 60;
}

/* =========================================================
   Internal API Helpers
========================================================= */

function user_proxy_scheme(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
        if ($proto === 'https' || $proto === 'http') {
            return $proto;
        }
    }

    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function user_proxy_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function user_proxy_api_base_url(): string
{
    if (function_exists('app_api_url')) {
        return app_api_url();
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/user/proxy.php';
    $apiPath = dirname(dirname($script));
    return rtrim(user_proxy_scheme() . '://' . user_proxy_host() . $apiPath, '/');
}

function user_proxy_is_loopback_host(string $host): bool
{
    $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function user_proxy_internal_api_attempts(string $url): array
{
    $attempts = [];
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
    $host = strtolower((string)($parts['host'] ?? ''));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

    if ($host !== '' && !user_proxy_is_loopback_host($host)) {
        $hostHeader = $host;
        if (isset($parts['port']) && $parts['port'] !== 80 && $parts['port'] !== 443) {
            $hostHeader .= ':' . (int)$parts['port'];
        }

        $attempts[] = [
            'url' => $url,
            'headers' => [],
            'options' => [
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':127.0.0.1'],
            ],
        ];

        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        if ($path !== '') {
            $attempts[] = [
                'url' => 'http://127.0.0.1' . $path . $query,
                'headers' => ['Host: ' . $hostHeader, 'X-Forwarded-Proto: ' . ($scheme === 'https' ? 'https' : 'http')],
                'options' => [
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 10,
                ],
            ];
        }
    }

    $attempts[] = [
        'url' => $url,
        'headers' => [],
        'options' => [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ],
    ];

    return $attempts;
}

function user_proxy_internal_api_request(
    string $method,
    string $relativePath,
    ?array $body = null,
    array $headers = [],
    array $requestPolicy = []
): array
{
    $url = user_proxy_api_base_url() . '/' . ltrim($relativePath, '/');
    $lastResult = null;
    $attempts = user_proxy_internal_api_attempts($url);
    if (!empty($requestPolicy['canonical_only']) && $attempts) {
        $canonicalAttempt = end($attempts);
        $attempts = is_array($canonicalAttempt) ? [$canonicalAttempt] : [];
    }
    $maxAttempts = max(1, (int)($requestPolicy['max_attempts'] ?? count($attempts)));
    $timeout = max(0, (int)($requestPolicy['timeout'] ?? 0));
    $connectTimeout = max(0, (int)($requestPolicy['connect_timeout'] ?? 0));

    foreach (array_slice($attempts, 0, $maxAttempts) as $attempt) {
        $ch = curl_init();
        $finalHeaders = ['Accept: application/json'];

        foreach ($headers as $k => $v) {
            $finalHeaders[] = $k . ': ' . $v;
        }

        foreach ((array)($attempt['headers'] ?? []) as $extraHeader) {
            $finalHeaders[] = (string)$extraHeader;
        }

        if ($body !== null) {
            $finalHeaders[] = 'Content-Type: application/json';
        }

        $curlOptions = [
            CURLOPT_URL => (string)$attempt['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $finalHeaders,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];

        foreach ((array)($attempt['options'] ?? []) as $option => $value) {
            $curlOptions[(int)$option] = $value;
        }
        if ($timeout > 0) {
            $curlOptions[CURLOPT_TIMEOUT] = $timeout;
        }
        if ($connectTimeout > 0) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
        }

        curl_setopt_array($ch, $curlOptions);

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
            $lastResult = [
                'ok' => false,
                'status' => 0,
                'json' => null,
                'error' => $err ?: 'Unknown cURL error',
                'raw' => '',
            ];
            continue;
        }

        $json = json_decode((string)$raw, true);

        if (!is_array($json)) {
            $lastResult = [
                'ok' => false,
                'status' => $status,
                'json' => null,
                'error' => 'Invalid JSON response from internal API',
                'raw' => substr((string)$raw, 0, 800),
            ];
            continue;
        }

        return [
            'ok' => $status >= 200 && $status < 300 && (bool)($json['ok'] ?? $json['success'] ?? false),
            'status' => $status,
            'json' => $json,
            'error' => null,
            'raw' => substr((string)$raw, 0, 800),
        ];
    }

    return $lastResult ?: [
        'ok' => false,
        'status' => 0,
        'json' => null,
        'error' => 'Internal API request failed',
        'raw' => '',
    ];
}

/* =========================================================
   Session / Auth Helpers
========================================================= */

function user_proxy_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function user_proxy_store_session(string $sessionToken, array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_session_token'] = $sessionToken;
    $_SESSION['user_user'] = [
        'uid' => (string)($user['uid'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => (string)($user['role'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
    ];
    $_SESSION['user_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['user_verified_at'] = user_proxy_now();
}

function user_proxy_clear_session(): void
{
    unset(
        $_SESSION['user_session_token'],
        $_SESSION['user_user'],
        $_SESSION['user_csrf'],
        $_SESSION['user_verified_at']
    );
}

function user_proxy_get_session_token(): string
{
    return trim((string)($_SESSION['user_session_token'] ?? ''));
}

function user_proxy_get_csrf(): string
{
    return trim((string)($_SESSION['user_csrf'] ?? ''));
}

function user_proxy_require_csrf(): void
{
    $incoming = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $stored = user_proxy_get_csrf();

    if ($stored === '' || $incoming === '' || !hash_equals($stored, $incoming)) {
        user_proxy_response(false, 'FORBIDDEN', 'Invalid CSRF token', [], 403);
    }
}

function user_proxy_trust_cookie_name(): string
{
    return 'zaw_user_trust';
}

function user_proxy_get_trust_cookie(): string
{
    return trim((string)($_COOKIE[user_proxy_trust_cookie_name()] ?? ''));
}

function user_proxy_set_trust_cookie(array $cookieData): void
{
    $uid = trim((string)($cookieData['uid'] ?? ''));
    $selector = trim((string)($cookieData['selector'] ?? ''));
    $token = trim((string)($cookieData['token'] ?? ''));
    $expiresAt = (int)($cookieData['expires_at'] ?? 0);

    if ($selector === '' || $token === '' || $expiresAt <= time()) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    $cookieValue = $uid !== ''
        ? $uid . ':' . $selector . ':' . $token
        : $selector . ':' . $token;

    setcookie(user_proxy_trust_cookie_name(), $cookieValue, [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[user_proxy_trust_cookie_name()] = $cookieValue;
}

function user_proxy_session_user_if_fresh(): array
{
    $token = user_proxy_get_session_token();

    if ($token === '') {
        return [];
    }

    $user = $_SESSION['user_user'] ?? [];
    if (!is_array($user) || empty($user['uid'])) {
        return [];
    }

    $role = strtoupper(trim((string)($user['role'] ?? '')));
    $status = strtoupper(trim((string)($user['status'] ?? '')));

    if (!user_proxy_allowed_role($role) || $status !== 'ACTIVE') {
        return [];
    }

    $verifiedAt = (int)($_SESSION['user_verified_at'] ?? 0);
    $age = user_proxy_now() - $verifiedAt;

    if ($verifiedAt > 0 && $age >= 0 && $age <= user_proxy_session_check_interval()) {
        return $user;
    }

    return [];
}

function user_proxy_finalize_login_with_session_token(string $sessionToken): array
{
    if ($sessionToken === '') {
        user_proxy_response(false, 'SERVER_ERROR', 'Session token missing after login', [], 500);
    }

    $sessionRes = user_proxy_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$sessionRes['ok']) {
        $json = is_array($sessionRes['json'] ?? null) ? $sessionRes['json'] : [];
        if (strtoupper((string)($json['code'] ?? '')) === 'MAINTENANCE') {
            user_proxy_response(
                false,
                'MAINTENANCE',
                system_maintenance_message(),
                [],
                503
            );
        }

        user_proxy_response(false, 'SESSION_EXPIRED', 'Failed to verify session', [], 401);
    }

    $user = (array)($sessionRes['json']['data'] ?? []);
    $role = strtoupper(trim((string)($user['role'] ?? '')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));

    if (!user_proxy_allowed_role($role)) {
        user_proxy_internal_api_request('POST', 'auth/logout.php', [], [
            'X-APP-KEY' => APP_KEY,
            'X-SESSION-TOKEN' => $sessionToken,
        ]);

        user_proxy_response(false, 'FORBIDDEN', 'Only USER or RETAILER can access this dashboard', [], 403);
    }

    if ($status !== 'ACTIVE') {
        user_proxy_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    system_require_user_service_available($user);

    user_proxy_store_session($sessionToken, $user);

    return $user;
}

function user_proxy_require_login(bool $touch = true, bool $forceVerify = false): array
{
    $token = user_proxy_get_session_token();

    if ($token === '') {
        user_proxy_response(false, 'SESSION_EXPIRED', 'User session not found', [], 401);
    }

    if (!$forceVerify) {
        $freshUser = user_proxy_session_user_if_fresh();

        if ($freshUser) {
            system_require_user_service_available($freshUser);

            if ($touch && user_proxy_get_csrf() === '') {
                $_SESSION['user_csrf'] = bin2hex(random_bytes(32));
            }

            return $freshUser;
        }
    }

    $res = user_proxy_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $token,
    ]);

    if (!$res['ok']) {
        $json = is_array($res['json'] ?? null) ? $res['json'] : [];
        if (strtoupper((string)($json['code'] ?? '')) === 'MAINTENANCE') {
            user_proxy_response(
                false,
                'MAINTENANCE',
                system_maintenance_message(),
                [],
                503
            );
        }

        user_proxy_clear_session();

        $msg = $json['message'] ?? 'Session expired';
        user_proxy_response(false, 'SESSION_EXPIRED', (string)$msg, [], 401);
    }

    $data = (array)($res['json']['data'] ?? []);
    $role = strtoupper(trim((string)($data['role'] ?? '')));
    $status = strtoupper(trim((string)($data['status'] ?? 'INACTIVE')));

    if (!user_proxy_allowed_role($role)) {
        user_proxy_clear_session();
        user_proxy_response(false, 'FORBIDDEN', 'User dashboard access required', [], 403);
    }

    if ($status !== 'ACTIVE') {
        user_proxy_clear_session();
        user_proxy_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    system_require_user_service_available($data);

    if ($touch) {
        $_SESSION['user_user'] = [
            'uid' => (string)($data['uid'] ?? ''),
            'name' => (string)($data['name'] ?? ''),
            'phone' => (string)($data['phone'] ?? ''),
            'email' => (string)($data['email'] ?? ''),
            'role' => (string)($data['role'] ?? ''),
            'status' => (string)($data['status'] ?? ''),
        ];

        $_SESSION['user_verified_at'] = user_proxy_now();

        if (user_proxy_get_csrf() === '') {
            $_SESSION['user_csrf'] = bin2hex(random_bytes(32));
        }
    }

    return $data;
}

/* =========================================================
   Data Loaders
========================================================= */

function user_proxy_load_user(string $uid): array
{
    $uid = trim($uid);

    if ($uid === '') {
        return [];
    }

    $row = fb_get('USERS/' . $uid);
    return is_array($row) ? $row : [];
}

function user_proxy_validate_transaction_pin(string $uid, string $pin): array
{
    $uid = trim($uid);
    $pin = trim($pin);

    if ($uid === '') {
        return ['ok' => false, 'code' => 'USER_NOT_FOUND', 'message' => 'User not found', 'data' => []];
    }

    if ($pin === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'PIN is required', 'data' => []];
    }

    $user = user_proxy_load_user($uid);

    if (!$user) {
        return ['ok' => false, 'code' => 'USER_NOT_FOUND', 'message' => 'User not found', 'data' => []];
    }

    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));

    if ($status !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is inactive', 'data' => []];
    }

    $pinHash = (string)($user['pin_hash'] ?? '');

    if ($pinHash === '' || !password_verify($pin, $pinHash)) {
        return ['ok' => false, 'code' => 'INVALID_PIN', 'message' => 'Invalid transaction PIN', 'data' => []];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'PIN verified',
        'data' => [
            'pin_verified' => true,
            'verified_at' => user_proxy_now(),
        ],
    ];
}

function user_proxy_load_wallet(string $uid): array
{
    $uid = trim($uid);

    if ($uid === '') {
        return [];
    }

    $row = fb_get('USER_WALLETS/' . $uid);
    return is_array($row) ? $row : [];
}

function user_proxy_default_role_settings(string $role = 'USER'): array
{
    if (function_exists('role_default_settings')) {
        $row = role_default_settings($role);

        if (is_array($row)) {
            return $row;
        }
    }

    return [
        'commission_per_1000' => 0,
        'api_enabled' => false,
        'topup_enabled' => true,
        'bundle_enabled' => false,
        'min_amount' => 20,
        'max_amount' => 500,
        'updated_at' => user_proxy_now(),
    ];
}

function user_proxy_load_role_settings(string $uid, ?string $role = null): array
{
    $uid = trim($uid);

    if ($uid === '') {
        return user_proxy_default_role_settings($role ?: 'USER');
    }

    $row = fb_get('USER_ROLE_SETTINGS/' . $uid);

    if (is_array($row)) {
        return function_exists('role_settings_with_defaults')
            ? role_settings_with_defaults($row, $role ?: 'USER')
            : array_replace(user_proxy_default_role_settings($role ?: 'USER'), $row);
    }

    return user_proxy_default_role_settings($role ?: 'USER');
}

function user_proxy_wallet_summary_payload(string $uid, array $sessionUser = []): array
{
    $uid = trim($uid);

    $userRow = user_proxy_load_user($uid);

    if (!$userRow && $sessionUser) {
        $userRow = $sessionUser;
    }

    $walletRow = user_proxy_load_wallet($uid);
    $roleSettingsRow = user_proxy_load_role_settings($uid, (string)($userRow['role'] ?? 'USER'));
    $walletDisplay = function_exists('mfs_wallet_display_payload')
        ? mfs_wallet_display_payload(is_array($userRow) ? $userRow : [], is_array($walletRow) ? $walletRow : [])
        : [];

    return [
        'uid' => (string)($userRow['uid'] ?? $uid),
        'name' => (string)($userRow['name'] ?? ''),
        'phone' => (string)($userRow['phone'] ?? ''),
        'email' => (string)($userRow['email'] ?? ''),
        'status' => (string)($userRow['status'] ?? ''),
        'role' => (string)($userRow['role'] ?? ''),
        'created_at' => (int)($userRow['created_at'] ?? 0),
        'last_login_at' => (int)($userRow['last_login_at'] ?? 0),
        'wallet' => [
            'available_balance' => (float)($walletRow['available_balance'] ?? 0),
            'hold_balance' => (float)($walletRow['hold_balance'] ?? 0),
            'currency' => (string)($walletDisplay['currency'] ?? $walletRow['currency'] ?? $walletRow['wallet_currency'] ?? 'BDT'),
            'wallet_currency' => (string)($walletDisplay['wallet_currency'] ?? $walletRow['wallet_currency'] ?? $walletRow['currency'] ?? 'BDT'),
            'display_currency' => (string)($walletDisplay['display_currency'] ?? $walletRow['currency'] ?? 'BDT'),
            'display_available_balance' => (float)($walletDisplay['display_available_balance'] ?? $walletRow['available_balance'] ?? 0),
            'display_hold_balance' => (float)($walletDisplay['display_hold_balance'] ?? $walletRow['hold_balance'] ?? 0),
            'available_balance_bdt' => (float)($walletDisplay['available_balance_bdt'] ?? $walletRow['available_balance'] ?? 0),
            'hold_balance_bdt' => (float)($walletDisplay['hold_balance_bdt'] ?? $walletRow['hold_balance'] ?? 0),
            'available_balance_myr' => (float)($walletDisplay['available_balance_myr'] ?? 0),
            'hold_balance_myr' => (float)($walletDisplay['hold_balance_myr'] ?? 0),
            'rate_myr_bdt' => (float)($walletDisplay['rate_myr_bdt'] ?? 0),
            'conversion_note' => (string)($walletDisplay['conversion_note'] ?? ''),
            'total_topup_spent' => (float)($walletRow['total_topup_spent'] ?? 0),
            'total_bundle_spent' => (float)($walletRow['total_bundle_spent'] ?? 0),
            'total_refund' => (float)($walletRow['total_refund'] ?? 0),
            'updated_at' => (int)($walletRow['updated_at'] ?? 0),
        ],
        'role_settings' => [
            'commission_per_1000' => (float)($roleSettingsRow['commission_per_1000'] ?? 0),
            'api_enabled' => (bool)($roleSettingsRow['api_enabled'] ?? false),
            'topup_enabled' => (bool)($roleSettingsRow['topup_enabled'] ?? false),
            'bundle_enabled' => (bool)($roleSettingsRow['bundle_enabled'] ?? false),
            'min_amount' => (float)($roleSettingsRow['min_amount'] ?? 0),
            'max_amount' => (float)($roleSettingsRow['max_amount'] ?? 0),
            'updated_at' => (int)($roleSettingsRow['updated_at'] ?? 0),
        ],
    ];
}

/* =========================================================
   Normalize / Display Helpers
========================================================= */

function user_proxy_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function user_proxy_transfer_favorite_path(string $uid): string
{
    return 'USER_TRANSFER_FAVORITES/' . trim($uid);
}

function user_proxy_transfer_favorite_id(string $phone): string
{
    return hash('sha256', user_proxy_normalize_phone($phone));
}

function user_proxy_transfer_mask_phone(string $phone): string
{
    $phone = trim($phone);
    if (function_exists('zpay_dash_mask_phone')) {
        return zpay_dash_mask_phone($phone);
    }

    $digits = user_proxy_normalize_phone($phone);
    if (strlen($digits) < 7) {
        return $phone !== '' ? $phone : '-';
    }

    return substr($digits, 0, 4) . '***' . substr($digits, -3);
}

function user_proxy_public_transfer_favorite(array $row): array
{
    $phone = trim((string)($row['phone'] ?? $row['receiver_phone'] ?? ''));

    return [
        'favorite_id' => (string)($row['favorite_id'] ?? user_proxy_transfer_favorite_id($phone)),
        'name' => (string)($row['name'] ?? $row['receiver_name'] ?? 'Z-Pay User'),
        'phone' => $phone,
        'phone_masked' => (string)($row['phone_masked'] ?? user_proxy_transfer_mask_phone($phone)),
        'wallet_currency' => strtoupper(trim((string)($row['wallet_currency'] ?? ''))),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function user_proxy_load_transfer_favorites(string $uid, int $limit = 10): array
{
    $rows = fb_get(user_proxy_transfer_favorite_path($uid));
    if (!is_array($rows)) {
        return [];
    }

    $items = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['favorite_id'] = (string)($row['favorite_id'] ?? $id);
        $phone = user_proxy_normalize_phone((string)($row['phone'] ?? $row['receiver_phone'] ?? ''));
        if ($phone === '') {
            continue;
        }
        $items[] = user_proxy_public_transfer_favorite($row);
    }

    usort($items, static function (array $a, array $b): int {
        return ((int)($b['updated_at'] ?? 0)) <=> ((int)($a['updated_at'] ?? 0));
    });

    return array_slice($items, 0, max(1, min(20, $limit)));
}

function user_proxy_operator_code(string $operator): string
{
    if (function_exists('normalize_operator')) {
        $code = (string)normalize_operator($operator);
        if ($code !== '') {
            return $code;
        }
    }

    $operator = strtoupper(trim($operator));

    $map = [
        'GP' => 'GP',
        'GRAMEENPHONE' => 'GP',
        'GRAMEEN PHONE' => 'GP',
        'ROBI' => 'ROBI',
        'AIRTEL' => 'AIRTEL',
        'BL' => 'BL',
        'BANGLALINK' => 'BL',
        'BANGLA LINK' => 'BL',
        'TT' => 'TT',
        'TELETALK' => 'TT',
        'TELE TALK' => 'TT',
    ];

    return $map[$operator] ?? '';
}

function user_proxy_operator_name(string $operator): string
{
    $code = user_proxy_operator_code($operator);

    $map = [
        'GP' => 'Grameenphone',
        'ROBI' => 'Robi',
        'AIRTEL' => 'Airtel',
        'BL' => 'Banglalink',
        'TT' => 'Teletalk',
    ];

    return $map[$code] ?? trim($operator);
}

function user_proxy_pick_float(array $row, array $keys, float $default = 0.0): float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));

            if ($value === '') {
                continue;
            }
        }

        if (is_numeric($value)) {
            return user_proxy_round_money($value);
        }
    }

    return $default;
}

function user_proxy_validate_amount(float $amount, array $roleSettings): array
{
    $minAmount = (float)($roleSettings['min_amount'] ?? 0);
    $maxAmount = (float)($roleSettings['max_amount'] ?? 0);

    if ($amount <= 0) {
        return [false, 'Amount must be greater than 0'];
    }

    if ($minAmount > 0 && $amount < $minAmount) {
        return [false, 'Minimum amount is ' . user_proxy_round_money($minAmount)];
    }

    if ($maxAmount > 0 && $amount > $maxAmount) {
        return [false, 'Maximum amount is ' . user_proxy_round_money($maxAmount)];
    }

    return [true, 'OK'];
}

/* =========================================================
   Bundle Offer Helpers
   Important: Public response never exposes admin/subadmin commission.
========================================================= */

function user_proxy_find_owner_subadmin_uid(array $user): string
{
    $candidates = [
        (string)($user['parent_subadmin_uid'] ?? ''),
        (string)($user['subadmin_uid'] ?? ''),
        (string)($user['owner_subadmin_uid'] ?? ''),
        (string)($user['assigned_subadmin_uid'] ?? ''),
    ];

    $createdByRole = strtoupper(trim((string)($user['created_by_role'] ?? '')));

    if ($createdByRole === 'SUBADMIN') {
        $candidates[] = (string)($user['created_by_uid'] ?? '');
    }

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);

        if ($candidate === '') {
            continue;
        }

        $row = user_proxy_load_user($candidate);
        $role = strtoupper(trim((string)($row['role'] ?? '')));

        if ($role === 'SUBADMIN') {
            return $candidate;
        }
    }

    return '';
}

function user_proxy_custom_commission_active(array $row): bool
{
    if (array_key_exists('deleted', $row) && user_proxy_bool_value($row['deleted'])) {
        return false;
    }

    if (array_key_exists('active', $row) && !user_proxy_bool_value($row['active'])) {
        return false;
    }

    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));

    if (in_array($status, ['DELETED', 'DISABLED', 'INACTIVE', 'RESET', 'CANCELLED'], true)) {
        return false;
    }

    return true;
}

function user_proxy_load_bundle_custom_commission_map(string $subadminUid): array
{
    $subadminUid = trim($subadminUid);

    if ($subadminUid === '') {
        return [];
    }

    $items = fb_get('SUBADMIN_BUNDLE_OFFERS/' . $subadminUid);

    if (!is_array($items)) {
        return [];
    }

    $map = [];

    foreach ($items as $key => $row) {
        if (!is_array($row) || !$row) {
            continue;
        }

        if (!user_proxy_custom_commission_active($row)) {
            continue;
        }

        $offerId = trim((string)($row['offer_id'] ?? $key));

        if ($offerId === '') {
            continue;
        }

        $map[$offerId] = $row;
    }

    return $map;
}

function user_proxy_load_bundle_custom_commission(string $subadminUid, string $offerId): array
{
    $subadminUid = trim($subadminUid);
    $offerId = trim($offerId);

    if ($subadminUid === '' || $offerId === '') {
        return [];
    }

    $paths = [
        'SUBADMIN_BUNDLE_OFFERS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_OFFER_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_CUSTOM_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_SUBADMIN_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'SUBADMIN_BUNDLE_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'USER_BUNDLE_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_OFFER_CUSTOM_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_COMMISSIONS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_CUSTOM_PRICING/' . $subadminUid . '/' . $offerId,
        'BUNDLE_OFFER_CUSTOMS/' . $subadminUid . '/' . $offerId,
        'BUNDLE_OFFERS/' . $offerId . '/custom_commissions/' . $subadminUid,
        'BUNDLE_OFFERS/' . $offerId . '/subadmin_commissions/' . $subadminUid,
        'BUNDLE_OFFERS/' . $offerId . '/custom_pricing/' . $subadminUid,
    ];

    foreach ($paths as $path) {
        $row = fb_get($path);

        if (!is_array($row) || !$row) {
            continue;
        }

        if (!user_proxy_custom_commission_active($row)) {
            continue;
        }

        return $row;
    }

    return [];
}

function user_proxy_offer_is_active(array $row, int $now): bool
{
    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
    $active = array_key_exists('active', $row) ? user_proxy_bool_value($row['active']) : true;
    $deleted = array_key_exists('deleted', $row) ? user_proxy_bool_value($row['deleted']) : false;
    $expiresAt = (int)($row['expires_at'] ?? 0);

    if ($deleted || $status === 'DELETED') {
        return false;
    }

    if (!$active) {
        return false;
    }

    if ($status !== 'ACTIVE') {
        return false;
    }

    if ($expiresAt > 0 && $expiresAt <= $now) {
        return false;
    }

    return true;
}

function user_proxy_load_bundle_offer_direct(string $offerId): array
{
    $offerId = trim($offerId);

    if ($offerId === '') {
        return [];
    }

    if (function_exists('bundle_load_offer')) {
        $row = bundle_load_offer($offerId);

        if (is_array($row) && $row) {
            $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
            return $row;
        }
    }

    $row = fb_get('BUNDLE_OFFERS/' . $offerId);

    if (is_array($row) && $row) {
        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
        return $row;
    }

    return [];
}

function user_proxy_list_base_bundle_offers(): array
{
    $raw = fb_get('BUNDLE_OFFERS');

    if (!is_array($raw)) {
        return [];
    }

    $items = [];

    foreach ($raw as $offerId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
        $items[] = $row;
    }

    return $items;
}

function user_proxy_build_bundle_offer_internal(array $row, array $user, string $subadminUid = '', array $customMap = []): array
{
    $uid = trim((string)($user['uid'] ?? ''));
    $offerId = trim((string)($row['offer_id'] ?? ''));

    if ($offerId === '') {
        return [];
    }

    $priceAmount = user_proxy_pick_float($row, ['price_amount', 'offer_price', 'price', 'amount'], 0);
    $adminCommission = user_proxy_pick_float($row, ['admin_commission', 'commission', 'max_commission'], 0);

    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($priceAmount > 0 && $adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    $userCommission = $adminCommission;
    $customApplied = false;

    $customRow = [];

    if ($subadminUid !== '' && $offerId !== '' && isset($customMap[$offerId]) && is_array($customMap[$offerId])) {
        $customRow = $customMap[$offerId];
    } elseif ($subadminUid !== '') {
        $customRow = user_proxy_load_bundle_custom_commission($subadminUid, $offerId);
    }

    if ($customRow) {
        $customUserCommission = user_proxy_pick_float($customRow, [
            'user_commission',
            'commission',
            'customer_commission',
            'retailer_commission',
            'user_commission_amount',
            'value',
        ], $userCommission);

        $userCommission = $customUserCommission;
        $customApplied = true;
    }

    if ($userCommission < 0) {
        $userCommission = 0.0;
    }

    if ($userCommission > $adminCommission) {
        $userCommission = $adminCommission;
    }

    $subadminProfit = user_proxy_round_money(max(0, $adminCommission - $userCommission));
    $payableAmount = user_proxy_round_money(max(0, $priceAmount - $userCommission));

    $operatorCode = user_proxy_operator_code((string)($row['operator'] ?? ''));

    $expiresAt = (int)($row['expires_at'] ?? 0);
    $now = function_exists('bundle_now') ? (int)bundle_now() : user_proxy_now();
    $validityValue = (float)($row['validity_value'] ?? 0);
    if ($validityValue <= 0) {
        $validityValue = (float)($row['package_validity_value'] ?? 0);
    }
    if ($validityValue <= 0) {
        $validityValue = (float)($row['bundle_validity_value'] ?? 0);
    }
    $validityUnit = trim((string)($row['validity_unit'] ?? ''));
    if ($validityUnit === '') {
        $validityUnit = trim((string)($row['package_validity_unit'] ?? ''));
    }
    if ($validityUnit === '') {
        $validityUnit = (string)($row['bundle_validity_unit'] ?? '');
    }
    $validitySeconds = (int)($row['validity_seconds'] ?? 0);
    if ($validitySeconds <= 0) {
        $validitySeconds = (int)($row['package_validity_seconds'] ?? 0);
    }
    if ($validitySeconds <= 0) {
        $validitySeconds = (int)($row['bundle_validity_seconds'] ?? 0);
    }
    $validityText = trim((string)($row['validity_text'] ?? ''));
    foreach (['package_validity', 'bundle_validity', 'validity', 'duration_text'] as $validityKey) {
        if ($validityText !== '') {
            break;
        }
        $validityText = trim((string)($row[$validityKey] ?? ''));
    }

    return [
        'offer_id' => $offerId,
        'operator' => $operatorCode ?: strtoupper(trim((string)($row['operator'] ?? ''))),
        'operator_name' => user_proxy_operator_name($operatorCode ?: (string)($row['operator'] ?? '')),
        'bundle_name' => (string)($row['bundle_name'] ?? $row['name'] ?? ''),
        'name' => (string)($row['name'] ?? $row['bundle_name'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'internet' => (string)($row['internet'] ?? $row['data'] ?? $row['data_text'] ?? $row['internet_text'] ?? ''),
        'data' => (string)($row['data'] ?? ''),
        'data_text' => (string)($row['data_text'] ?? ''),
        'internet_text' => (string)($row['internet_text'] ?? ''),
        'minutes' => (string)($row['minutes'] ?? $row['minute'] ?? ''),
        'minute' => (string)($row['minute'] ?? ''),
        'sms' => (string)($row['sms'] ?? ''),
        'category' => (string)($row['category'] ?? $row['type'] ?? $row['bundle_type'] ?? $row['offer_type'] ?? ''),
        'type' => (string)($row['type'] ?? ''),
        'bundle_type' => (string)($row['bundle_type'] ?? ''),
        'offer_type' => (string)($row['offer_type'] ?? ''),

        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,

        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,

        'net_cost_after_commission' => $payableAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'wallet_hold_amount' => $payableAmount,

        'duration_value' => (float)($row['duration_value'] ?? 0),
        'duration_unit' => (string)($row['duration_unit'] ?? ''),
        'duration_seconds' => (int)($row['duration_seconds'] ?? 0),
        'validity_value' => $validityValue,
        'validity_unit' => $validityUnit,
        'validity_seconds' => $validitySeconds,
        'validity_text' => $validityText,
        'validity' => (string)($row['validity'] ?? ''),
        'duration_text' => (string)($row['duration_text'] ?? ''),
        'expires_at' => $expiresAt,
        'expired' => $expiresAt > 0 && $expiresAt <= $now,
        'status' => strtoupper(trim((string)($row['status'] ?? 'ACTIVE'))),
        'active' => true,
        'customized_by_subadmin' => $customApplied,
        'subadmin_uid' => $subadminUid,
        'target_uid' => $uid,
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function user_proxy_public_bundle_offer(array $item): array
{
    return [
        'offer_id' => (string)($item['offer_id'] ?? ''),
        'operator' => (string)($item['operator'] ?? ''),
        'operator_name' => (string)($item['operator_name'] ?? user_proxy_operator_name((string)($item['operator'] ?? ''))),
        'bundle_name' => (string)($item['bundle_name'] ?? $item['name'] ?? ''),
        'name' => (string)($item['name'] ?? $item['bundle_name'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'internet' => (string)($item['internet'] ?? ''),
        'data' => (string)($item['data'] ?? ''),
        'data_text' => (string)($item['data_text'] ?? ''),
        'internet_text' => (string)($item['internet_text'] ?? ''),
        'minutes' => (string)($item['minutes'] ?? ''),
        'minute' => (string)($item['minute'] ?? ''),
        'sms' => (string)($item['sms'] ?? ''),
        'category' => (string)($item['category'] ?? ''),
        'type' => (string)($item['type'] ?? ''),
        'bundle_type' => (string)($item['bundle_type'] ?? ''),
        'offer_type' => (string)($item['offer_type'] ?? ''),

        'amount' => (float)($item['amount'] ?? 0),
        'price_amount' => (float)($item['price_amount'] ?? $item['amount'] ?? 0),
        'offer_price' => (float)($item['offer_price'] ?? $item['amount'] ?? 0),

        'user_commission' => (float)($item['user_commission'] ?? 0),

        'net_cost_after_commission' => (float)($item['net_cost_after_commission'] ?? $item['payable_amount'] ?? 0),
        'you_pay' => (float)($item['you_pay'] ?? $item['payable_amount'] ?? 0),
        'payable_amount' => (float)($item['payable_amount'] ?? $item['you_pay'] ?? 0),
        'wallet_hold_amount' => (float)($item['wallet_hold_amount'] ?? $item['payable_amount'] ?? 0),

        'duration_value' => (float)($item['duration_value'] ?? 0),
        'duration_unit' => (string)($item['duration_unit'] ?? ''),
        'duration_seconds' => (int)($item['duration_seconds'] ?? 0),
        'validity_value' => (float)($item['validity_value'] ?? 0),
        'validity_unit' => (string)($item['validity_unit'] ?? ''),
        'validity_seconds' => (int)($item['validity_seconds'] ?? 0),
        'validity_text' => (string)($item['validity_text'] ?? ''),
        'validity' => (string)($item['validity'] ?? ''),
        'duration_text' => (string)($item['duration_text'] ?? ''),
        'expires_at' => (int)($item['expires_at'] ?? 0),
        'expired' => (bool)($item['expired'] ?? false),
        'status' => (string)($item['status'] ?? 'ACTIVE'),
        'active' => (bool)($item['active'] ?? true),
        'customized_by_subadmin' => (bool)($item['customized_by_subadmin'] ?? false),
        'created_at' => (int)($item['created_at'] ?? 0),
        'updated_at' => (int)($item['updated_at'] ?? 0),
    ];
}

function user_proxy_bundle_offers_for_user(string $uid, string $operatorFilter = ''): array
{
    $uid = trim($uid);
    $operatorFilter = user_proxy_operator_code($operatorFilter);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    $user = user_proxy_load_user($uid);

    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $user['uid'] = $uid;

    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $roleSettings = user_proxy_load_role_settings($uid, $role);
    $wallet = user_proxy_load_wallet($uid);

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!(bool)($roleSettings['bundle_enabled'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'BUNDLE_DISABLED',
            'message' => 'Bundle is disabled for this account',
            'data' => [
                'bundle_enabled' => false,
            ],
        ];
    }

    $subadminUid = user_proxy_find_owner_subadmin_uid($user);
    $customMap = user_proxy_load_bundle_custom_commission_map($subadminUid);
    $items = user_proxy_list_base_bundle_offers();

    $out = [];
    $now = function_exists('bundle_now') ? (int)bundle_now() : user_proxy_now();

    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }

        $offerId = trim((string)($row['offer_id'] ?? ''));

        if ($offerId === '') {
            continue;
        }

        if (!user_proxy_offer_is_active($row, $now)) {
            continue;
        }

        $internal = user_proxy_build_bundle_offer_internal($row, $user, $subadminUid, $customMap);

        if (!$internal) {
            continue;
        }

        if ((float)($internal['amount'] ?? 0) <= 0) {
            continue;
        }

        if ($operatorFilter !== '' && user_proxy_operator_code((string)($internal['operator'] ?? '')) !== $operatorFilter) {
            continue;
        }

        $out[] = user_proxy_public_bundle_offer($internal);
    }

    usort($out, static function (array $a, array $b): int {
        $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['created_at'] ?? 0));
        $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['created_at'] ?? 0));
        return $bTime <=> $aTime;
    });

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle offers loaded successfully',
        'data' => [
            'uid' => $uid,
            'operator' => $operatorFilter,
            'total' => count($out),
            'items' => array_values($out),
            'wallet' => [
                'available_balance' => (float)($wallet['available_balance'] ?? 0),
                'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
            ],
            'role_settings' => [
                'bundle_enabled' => (bool)($roleSettings['bundle_enabled'] ?? false),
            ],
        ],
    ];
}

/* =========================================================
   Wallet Hold Helpers
========================================================= */

function user_proxy_hold_balance(string $uid, float $amount, string $requestId, string $type, string $note, array $options = []): array
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $amount = user_proxy_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid wallet hold data',
            'data' => [],
        ];
    }

    if (function_exists('wallet_hold_amount')) {
        $options['ledger_extra'] = array_merge([
            'request_id' => $requestId,
            'ref_id' => $requestId,
            'note' => $note,
            'created_by_uid' => $uid,
            'created_by_role' => 'USER',
        ], is_array($options['ledger_extra'] ?? null) ? $options['ledger_extra'] : []);

        $holdResult = wallet_hold_amount($uid, $amount, $requestId, $type, $options);
        if (!empty($holdResult['ok'])) {
            return [
                'ok' => true,
                'code' => 'SUCCESS',
                'message' => 'Balance held successfully',
                'ledger_id' => (string)($holdResult['ledger_id'] ?? ''),
                'available_balance' => (float)($holdResult['available_balance'] ?? $holdResult['after_available'] ?? 0),
                'hold_balance' => (float)($holdResult['hold_balance'] ?? $holdResult['after_hold'] ?? 0),
                'before_available' => (float)($holdResult['before_available'] ?? 0),
                'after_available' => (float)($holdResult['after_available'] ?? $holdResult['available_balance'] ?? 0),
                'before_hold' => (float)($holdResult['before_hold'] ?? 0),
                'after_hold' => (float)($holdResult['after_hold'] ?? $holdResult['hold_balance'] ?? 0),
                'currency' => (string)($holdResult['currency'] ?? ''),
            ];
        }

        return [
            'ok' => false,
            'code' => (string)($holdResult['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($holdResult['message'] ?? 'Failed to hold balance'),
            'data' => (array)($holdResult['data'] ?? []),
        ];
    }

    return [
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'message' => 'Wallet hold helper is unavailable',
        'data' => [],
    ];
}

function user_proxy_internal_multipart_request(string $relativePath, array $fields, array $files, array $headers = []): array
{
    $url = user_proxy_api_base_url() . '/' . ltrim($relativePath, '/');
    $payload = [];

    foreach ($fields as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $payload[(string)$key] = (string)($value ?? '');
        }
    }

    foreach ($files as $field => $file) {
        if (!is_array($file)) {
            continue;
        }

        $names = $file['name'] ?? '';
        $tmpNames = $file['tmp_name'] ?? '';
        $types = $file['type'] ?? '';
        $errors = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if (is_array($names)) {
            foreach ($names as $index => $name) {
                $tmp = (string)($tmpNames[$index] ?? '');
                $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
                if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
                }
                $payload[(string)$field . '[' . $index . ']'] = new CURLFile(
                    $tmp,
                    (string)($types[$index] ?? 'application/octet-stream'),
                    basename((string)$name)
                );
            }
            continue;
        }

        $tmp = (string)$tmpNames;
        if ((int)$errors !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $payload[(string)$field] = new CURLFile(
            $tmp,
            (string)($types ?: 'application/octet-stream'),
            basename((string)$names)
        );
    }

    $ch = curl_init();
    $finalHeaders = ['Accept: application/json'];
    foreach ($headers as $key => $value) {
        $finalHeaders[] = $key . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'error' => $error ?: 'Internal upload failed'];
    }

    $json = json_decode((string)$raw, true);
    return [
        'ok' => $status >= 200 && $status < 300 && is_array($json) && !empty($json['ok']),
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'error' => is_array($json) ? null : 'Invalid JSON response from internal API',
    ];
}

function user_proxy_internal_binary_request(string $relativePath, array $headers = []): array
{
    $url = user_proxy_api_base_url() . '/' . ltrim($relativePath, '/');
    $ch = curl_init();
    $finalHeaders = ['Accept: image/jpeg,image/png,image/webp,application/json'];
    foreach ($headers as $key => $value) {
        $finalHeaders[] = $key . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    curl_close($ch);

    return [
        'ok' => $body !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'content_type' => strtolower(trim(explode(';', $contentType)[0] ?? '')),
        'body' => $body === false ? '' : (string)$body,
        'error' => $body === false ? ($error ?: 'Internal attachment request failed') : '',
    ];
}

function user_proxy_authenticated_headers(): array
{
    return [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => user_proxy_get_session_token(),
        'X-ZPAY-CLIENT' => 'USER_WEB',
    ];
}

function user_proxy_forward_authenticated_json(
    string $method,
    string $relativePath,
    ?array $body,
    string $fallbackCode,
    string $fallbackMessage,
    array $requestPolicy = []
): void {
    $res = user_proxy_internal_api_request(
        $method,
        $relativePath,
        $body,
        user_proxy_authenticated_headers(),
        $requestPolicy
    );
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];

    user_proxy_response(
        !empty($res['ok']),
        (string)($json['code'] ?? $fallbackCode),
        (string)($json['message'] ?? $fallbackMessage),
        (array)($json['data'] ?? []),
        (int)(($res['status'] ?? 0) > 0 ? $res['status'] : 502)
    );
}

function user_proxy_forward_authenticated_multipart(
    string $relativePath,
    array $fields,
    array $files,
    string $fallbackCode,
    string $fallbackMessage
): void {
    $res = user_proxy_internal_multipart_request(
        $relativePath,
        $fields,
        $files,
        user_proxy_authenticated_headers()
    );
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];

    user_proxy_response(
        !empty($res['ok']),
        (string)($json['code'] ?? $fallbackCode),
        (string)($json['message'] ?? $fallbackMessage),
        (array)($json['data'] ?? []),
        (int)(($res['status'] ?? 0) > 0 ? $res['status'] : 502)
    );
}

function user_proxy_release_hold_rollback(string $uid, float $amount, string $requestId, string $type, string $note): void
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $amount = user_proxy_round_money($amount);
    $type = trim($type);

    if ($uid === '' || $requestId === '' || $amount <= 0) {
        return;
    }

    if (function_exists('wallet_refund_hold')) {
        wallet_refund_hold($uid, $amount, $requestId, $type !== '' ? $type : 'USER_WEB_HOLD_ROLLBACK', [
            'ledger_extra' => [
                'request_id' => $requestId,
                'ref_id' => $requestId,
                'note' => $note,
                'created_by_uid' => $uid,
                'created_by_role' => 'SYSTEM',
            ],
        ]);
    }
}

/* =========================================================
   Request Status / Logs
========================================================= */

function user_proxy_create_request_status(string $requestId, string $uid, string $status, string $message, string $type = 'TOPUP'): void
{
    if (function_exists('create_request_status')) {
        create_request_status($requestId, $type, $uid, $status, $message);
        return;
    }

    fb_put('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'type' => $type,
        'request_type' => $type,
        'uid' => $uid,
        'status' => $status,
        'message' => $message,
        'updated_at' => user_proxy_now(),
    ]);
}


function user_proxy_public_request_log(array $row, string $requestId = ''): array
{
    $requestId = trim((string)($row['request_id'] ?? $row['id'] ?? $requestId));
    $type = strtoupper(trim((string)($row['request_type'] ?? $row['type'] ?? $row['action'] ?? 'TOPUP')));

    if (!in_array($type, ['TOPUP', 'BUNDLE', 'MFS'], true)) {
        if (isset($row['provider']) || isset($row['receiver_number']) || isset($row['service_type'])) {
            $type = 'MFS';
        } elseif (isset($row['bundle_name']) || isset($row['bundle_number']) || isset($row['offer_id'])) {
            $type = 'BUNDLE';
        } else {
            $type = 'TOPUP';
        }
    }

    if ($type === 'MFS') {
        $provider = function_exists('mfs_normalize_provider')
            ? mfs_normalize_provider((string)($row['provider'] ?? ''))
            : strtoupper(trim((string)($row['provider'] ?? '')));

        $providerName = function_exists('mfs_provider_name')
            ? mfs_provider_name($provider)
            : $provider;

        $serviceType = function_exists('mfs_normalize_service_type')
            ? mfs_normalize_service_type((string)($row['service_type'] ?? 'SEND_MONEY'))
            : strtoupper(trim((string)($row['service_type'] ?? 'SEND_MONEY')));

        $serviceName = function_exists('mfs_service_name')
            ? mfs_service_name($serviceType)
            : $serviceType;

        return [
            'request_id' => $requestId,
            'key_id' => (string)($row['key_id'] ?? $row['source_key_id'] ?? 'PANEL'),
            'action' => 'MFS',
            'request_type' => 'MFS',
            'source' => (string)($row['source'] ?? $row['request_source'] ?? 'USER_PANEL'),
            'request_source' => (string)($row['request_source'] ?? $row['source'] ?? 'USER_PANEL'),

            'status' => (string)($row['status'] ?? 'PENDING'),

            'provider' => $provider,
            'provider_name' => $providerName,
            'service_type' => $serviceType,
            'service_name' => $serviceName,
            'account_type' => (string)($row['account_type'] ?? 'PERSONAL'),

            'receiver_number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),
            'number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),

            'country_code' => (string)($row['country_code'] ?? ''),
            'service_mode' => (string)($row['service_mode'] ?? ''),
            'wallet_currency' => (string)($row['wallet_currency'] ?? ''),

            'amount' => (float)($row['total_debit'] ?? $row['amount'] ?? 0),
            'amount_bdt' => (float)($row['amount_bdt'] ?? 0),
            'amount_rm' => (float)($row['amount_rm'] ?? 0),

            'fee_bdt' => (float)($row['fee_bdt'] ?? 0),
            'fee_rm' => (float)($row['fee_rm'] ?? 0),

            'total_debit' => (float)($row['total_debit'] ?? 0),
            'total_debit_bdt' => (float)($row['total_debit_bdt'] ?? 0),
            'total_debit_rm' => (float)($row['total_debit_rm'] ?? 0),

            'exchange_rate' => (float)($row['exchange_rate'] ?? 0),

            'reference' => (string)($row['reference'] ?? ''),
            'trxid' => (string)($row['trxid'] ?? ''),
            'receipt_id' => (string)($row['receipt_id'] ?? ''),
            'receipt_url' => (string)($row['receipt_url'] ?? $row['tracking_url'] ?? ''),
            'tracking_url' => (string)($row['tracking_url'] ?? $row['receipt_url'] ?? ''),
            'receipt_created_at' => (int)($row['receipt_created_at'] ?? 0),
            'message' => (string)($row['final_message'] ?? $row['message'] ?? $row['note'] ?? ''),

            'created_at' => (int)($row['created_at'] ?? 0),
            'updated_at' => (int)($row['updated_at'] ?? 0),
            'completed_at' => (int)($row['completed_at'] ?? 0),
        ];
    }

    $operator = (string)($row['operator'] ?? '');

    if ($type === 'BUNDLE') {
        $bundleNumber = (string)($row['bundle_number'] ?? $row['topup_number'] ?? $row['number'] ?? '');
        $priceAmount = (float)($row['price_amount'] ?? $row['offer_price'] ?? $row['price'] ?? $row['amount'] ?? 0);
        $payableAmount = (float)(
            $row['payable_amount']
            ?? $row['you_pay']
            ?? $row['wallet_hold_amount']
            ?? $row['held_amount']
            ?? $row['amount']
            ?? 0
        );

        return [
            'request_id' => $requestId,
            'key_id' => (string)($row['key_id'] ?? 'PANEL'),
            'action' => 'BUNDLE',
            'request_type' => 'BUNDLE',
            'source' => (string)($row['source'] ?? $row['request_source'] ?? 'USER_PANEL'),
            'request_source' => (string)($row['request_source'] ?? $row['source'] ?? 'USER_PANEL'),
            'status' => (string)($row['status'] ?? 'WAITING_ADMIN'),
            'operator' => user_proxy_operator_code($operator) ?: $operator,
            'operator_name' => user_proxy_operator_name($operator),
            'topup_number' => $bundleNumber,
            'bundle_number' => $bundleNumber,
            'number' => $bundleNumber,
            'offer_id' => (string)($row['offer_id'] ?? ''),
            'bundle_name' => (string)($row['bundle_name'] ?? ''),
            'amount' => (float)$payableAmount,
            'price_amount' => (float)$priceAmount,
            'offer_price' => (float)$priceAmount,
            'user_commission' => (float)($row['user_commission'] ?? $row['customer_commission'] ?? $row['user_discount'] ?? 0),
            'you_pay' => (float)$payableAmount,
            'payable_amount' => (float)$payableAmount,
            'message' => (string)($row['final_message'] ?? $row['message'] ?? $row['note'] ?? ''),
            'created_at' => (int)($row['created_at'] ?? 0),
            'updated_at' => (int)($row['updated_at'] ?? 0),
            'completed_at' => (int)($row['completed_at'] ?? 0),
        ];
    }

    $topupNumber = (string)($row['topup_number'] ?? $row['number'] ?? '');

    return [
        'request_id' => $requestId,
        'key_id' => (string)($row['key_id'] ?? 'PANEL'),
        'action' => 'TOPUP',
        'request_type' => 'TOPUP',
        'source' => (string)($row['source'] ?? $row['request_source'] ?? 'USER_PANEL'),
        'request_source' => (string)($row['request_source'] ?? $row['source'] ?? 'USER_PANEL'),
        'status' => (string)($row['status'] ?? 'PENDING'),
        'operator' => user_proxy_operator_code($operator) ?: $operator,
        'operator_name' => user_proxy_operator_name($operator),
        'topup_number' => $topupNumber,
        'bundle_number' => '',
        'number' => $topupNumber,
        'offer_id' => '',
        'bundle_name' => '',
        'amount' => (float)($row['amount'] ?? $row['amount_bdt'] ?? 0),
        'price_amount' => 0,
        'offer_price' => 0,
        'user_commission' => 0,
        'you_pay' => 0,
        'payable_amount' => 0,
        'message' => (string)($row['final_message'] ?? $row['message'] ?? $row['note'] ?? ''),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'completed_at' => (int)($row['completed_at'] ?? 0),
    ];
}


function user_proxy_apply_request_status_row(array $publicRow): array
{
    $requestId = trim((string)($publicRow['request_id'] ?? ''));

    if ($requestId === '') {
        return $publicRow;
    }

    $statusRow = fb_get('REQUEST_STATUS/' . $requestId);

    if (is_array($statusRow)) {
        $publicRow['status'] = (string)($statusRow['status'] ?? $publicRow['status'] ?? '');
        $publicRow['message'] = (string)($statusRow['message'] ?? $publicRow['message'] ?? '');
        $publicRow['updated_at'] = (int)($statusRow['updated_at'] ?? $publicRow['updated_at'] ?? 0);

        if (!empty($statusRow['completed_at'])) {
            $publicRow['completed_at'] = (int)$statusRow['completed_at'];
        }
    }

    return $publicRow;
}

function user_proxy_write_user_request_log(string $uid, string $requestId, array $row, string $type, string $status, string $message): void
{
    $uid = trim($uid);
    $requestId = trim($requestId);

    if ($uid === '' || $requestId === '') {
        return;
    }

    $now = user_proxy_now();

    $row['uid'] = $uid;
    $row['request_id'] = $requestId;
    $row['request_type'] = strtoupper($type);
    $row['type'] = strtoupper($type);
    $row['action'] = strtoupper($type);
    $row['status'] = strtoupper($status);
    $row['message'] = $message;
    $row['updated_at'] = (int)($row['updated_at'] ?? $now);

    if (empty($row['created_at'])) {
        $row['created_at'] = $now;
    }

    $public = user_proxy_public_request_log($row, $requestId);

    fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, $public);
}

function user_proxy_request_log_month(array $row, string $requestId = ''): string
{
    foreach (['created_at', 'updated_at', 'completed_at', 'receipt_created_at'] as $key) {
        $ts = (int)($row[$key] ?? 0);

        if ($ts <= 0) {
            continue;
        }

        if ($ts > 9999999999) {
            $ts = (int)floor($ts / 1000);
        }

        return user_proxy_month_key($ts);
    }

    foreach (['month', 'month_key', 'history_month'] as $key) {
        $month = trim((string)($row[$key] ?? ''));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $month;
        }
    }

    if (preg_match('/(20\d{2})[-_]?([01]\d)/', $requestId, $m)) {
        $candidate = $m[1] . '-' . $m[2];

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function user_proxy_request_log_matches_month(array $row, string $requestId, string $month): bool
{
    $rowMonth = user_proxy_request_log_month($row, $requestId);

    return $rowMonth !== '' && $rowMonth === $month;
}

function user_proxy_collect_fast_request_logs(string $uid, int $limit = 100, ?string $month = null): array
{
    $uid = trim($uid);
    $month = user_proxy_valid_month_key($month);

    if ($uid === '') {
        return [];
    }

    $map = [];

    $apiRows = fb_get('USER_API_REQUESTS/' . $uid);

    if (is_array($apiRows)) {
        foreach ($apiRows as $requestId => $row) {
            if (!is_array($row)) {
                continue;
            }

            $public = user_proxy_public_request_log($row, (string)$requestId);
            $public = user_proxy_apply_request_status_row($public);

            $rid = (string)($public['request_id'] ?? $requestId);

            if ($rid !== '' && user_proxy_request_log_matches_month($public, $rid, $month)) {
                $map[$rid] = $public;
            }
        }
    }

    $monthKeys = [$month];

    foreach (array_unique($monthKeys) as $month) {
        $histRows = fb_get('BUNDLE_HISTORY/' . $uid . '/' . $month);

        if (!is_array($histRows)) {
            continue;
        }

        foreach ($histRows as $requestId => $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['request_type'] = 'BUNDLE';

            $public = user_proxy_public_request_log($row, (string)$requestId);
            $public = user_proxy_apply_request_status_row($public);

            $rid = (string)($public['request_id'] ?? $requestId);

            $rowMonth = user_proxy_request_log_month($public, $rid);

            if ($rid !== '' && ($rowMonth === '' || $rowMonth === $month)) {
                $map[$rid] = array_merge($map[$rid] ?? [], $public);
            }
        }
    }

    $rows = array_values($map);

    usort($rows, static function (array $a, array $b): int {
        $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['completed_at'] ?? 0) ?: ($a['created_at'] ?? 0));
        $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['completed_at'] ?? 0) ?: ($b['created_at'] ?? 0));
        return $bTime <=> $aTime;
    });

    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    return array_values($rows);
}

function user_proxy_collect_legacy_request_logs(string $uid, int $limit = 100, ?string $month = null): array
{
    $uid = trim($uid);
    $month = user_proxy_valid_month_key($month);
    $rows = [];

    if ($uid === '') {
        return [];
    }

    $topupBuckets = [
        'PENDING' => 'TOPUP_REQUESTS/PENDING',
        'CLAIMED' => 'TOPUP_REQUESTS/CLAIMED',
        'PROCESSING' => 'TOPUP_REQUESTS/PROCESSING',
        'DONE' => 'TOPUP_REQUESTS/DONE',
    ];

    foreach ($topupBuckets as $bucket => $path) {
        $items = fb_get($path);

        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $requestId => $row) {
            if (!is_array($row) || (string)($row['uid'] ?? '') !== $uid) {
                continue;
            }

            if (empty($row['status'])) {
                $row['status'] = $bucket;
            }

            $row['request_type'] = 'TOPUP';
            $public = user_proxy_public_request_log($row, (string)$requestId);
            $public = user_proxy_apply_request_status_row($public);
            if (user_proxy_request_log_matches_month($public, (string)$requestId, $month)) {
                $rows[$public['request_id']] = $public;
            }
        }
    }

    $bundleBuckets = [
        'PENDING' => 'BUNDLE_REQUESTS/PENDING',
        'CLAIMED' => 'BUNDLE_REQUESTS/CLAIMED',
        'PROCESSING' => 'BUNDLE_REQUESTS/PROCESSING',
        'DONE' => 'BUNDLE_REQUESTS/DONE',
    ];

    foreach ($bundleBuckets as $bucket => $path) {
        $items = fb_get($path);

        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $requestId => $row) {
            if (!is_array($row) || (string)($row['uid'] ?? '') !== $uid) {
                continue;
            }

            if (empty($row['status'])) {
                $row['status'] = $bucket;
            }

            $row['request_type'] = 'BUNDLE';
            $public = user_proxy_public_request_log($row, (string)$requestId);
            $public = user_proxy_apply_request_status_row($public);
            if (user_proxy_request_log_matches_month($public, (string)$requestId, $month)) {
                $rows[$public['request_id']] = $public;
            }
        }
    }

    $out = array_values($rows);

    usort($out, static function (array $a, array $b): int {
        $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['completed_at'] ?? 0) ?: ($a['created_at'] ?? 0));
        $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['completed_at'] ?? 0) ?: ($b['created_at'] ?? 0));
        return $bTime <=> $aTime;
    });

    if ($limit > 0 && count($out) > $limit) {
        $out = array_slice($out, 0, $limit);
    }

    return array_values($out);
}

function user_proxy_collect_request_logs(string $uid, int $limit = 100, bool $legacyFallback = false, ?string $month = null): array
{
    $fast = user_proxy_collect_fast_request_logs($uid, $limit, $month);

    if (!$legacyFallback || count($fast) > 0) {
        return $fast;
    }

    return user_proxy_collect_legacy_request_logs($uid, $limit, $month);
}

function user_proxy_collect_wallet_received(string $uid, string $month, int $limit = 100): array
{
    return array_values(array_filter(
        wallet_list_user_history($uid, $month, $limit),
        static fn(array $row): bool => strtoupper((string)($row['direction'] ?? '')) === 'CREDIT'
    ));
}

/* =========================================================
   Create Topup
========================================================= */

function user_proxy_create_topup_request(string $uid, array $body): array
{
    $uid = trim($uid);

    $userRow = user_proxy_load_user($uid);

    if (!$userRow) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $role = strtoupper(trim((string)($userRow['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));
    $roleSettings = user_proxy_load_role_settings($uid, $role);

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!(bool)($roleSettings['topup_enabled'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'TOPUP_DISABLED',
            'message' => 'Topup is disabled for this account',
            'data' => [],
        ];
    }

    $countryCode = function_exists('topup_country_code') ? topup_country_code($body['country_code'] ?? $body['country'] ?? 'BD') : 'BD';
    $topupNumber = function_exists('topup_normalize_number_for_country')
        ? topup_normalize_number_for_country($countryCode, (string)($body['topup_number'] ?? $body['number'] ?? ''))
        : user_proxy_normalize_phone((string)($body['topup_number'] ?? ''));
    $operator = user_proxy_operator_code((string)($body['operator'] ?? ''));
    $amount = user_proxy_round_money((float)($body['amount'] ?? 0));
    $pin = trim((string)($body['pin'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));

    if ((function_exists('topup_is_valid_number_for_country') && !topup_is_valid_number_for_country($countryCode, $topupNumber))
        || (!function_exists('topup_is_valid_number_for_country') && ($topupNumber === '' || strlen($topupNumber) < 10))
    ) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid topup number is required',
            'data' => [],
        ];
    }

    if ($operator === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid operator is required',
            'data' => [],
        ];
    }

    $topupRoleLimits = $roleSettings;
    if (function_exists('topup_validate_request')) {
        $topupValidation = topup_validate_request($countryCode, $operator, $amount, true, true);
        if (empty($topupValidation['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($topupValidation['code'] ?? 'VALIDATION_ERROR'),
                'message' => (string)($topupValidation['message'] ?? 'Invalid top-up request'),
                'data' => (array)($topupValidation['data'] ?? []),
            ];
        }
        $topupRoleLimits['min_amount'] = (float)($topupValidation['min_amount'] ?? topup_effective_min_amount($countryCode, 20.0));
    }

    [$amountOk, $amountMsg] = user_proxy_validate_amount($amount, $topupRoleLimits);

    if (!$amountOk) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => $amountMsg,
            'data' => [],
        ];
    }

    if ($pin === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'PIN is required',
            'data' => [],
        ];
    }

    $pinHash = (string)($userRow['pin_hash'] ?? '');

    if ($pinHash === '' || !password_verify($pin, $pinHash)) {
        return [
            'ok' => false,
            'code' => 'INVALID_PIN',
            'message' => 'Invalid transaction PIN',
            'data' => [],
        ];
    }

    $walletRow = user_proxy_load_wallet($uid);
    $financials = topup_calculate_payment_context($uid, $amount, $userRow, $walletRow, $roleSettings, $countryCode);
    if (empty($financials['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($financials['code'] ?? 'TOPUP_CALCULATION_FAILED'),
            'message' => (string)($financials['message'] ?? 'Top-up calculation failed'),
            'data' => [],
        ];
    }

    $requestId = user_proxy_make_id('TR');
    $now = user_proxy_now();
    $walletDebit = (float)$financials['wallet_debit_amount'];
    $operationSeed = hash('sha256', implode('|', [
        'USER_WEB_TOPUP_CREATE',
        $uid,
        $countryCode,
        $operator,
        $topupNumber,
        number_format($amount, 2, '.', ''),
        number_format($walletDebit, 2, '.', ''),
        (string)floor($now / 120),
    ]));
    $operationRef = 'USER_WEB_TOPUP_CREATE:' . hash('sha256', $operationSeed);
    $operation = wallet_financial_operation_begin($operationRef, 'USER_WEB_TOPUP_CREATE_HOLD', 'REQUEST_CREATE', $uid, $walletDebit, (string)$financials['wallet_debit_currency'], [
        'request_id' => $requestId,
        'operator' => $operator,
        'topup_number_hash' => hash('sha256', $topupNumber),
    ]);
    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Topup request created successfully',
            'data' => $resultData,
        ];
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'),
            'message' => (string)($operation['message'] ?? 'Wallet operation is unavailable'),
            'data' => [],
        ];
    }
    $financialClaim = (array)$operation['claim'];
    $requestId = trim((string)($financialClaim['meta']['request_id'] ?? $requestId));

    $hold = user_proxy_hold_balance(
        $uid,
        $walletDebit,
        $requestId,
        'USER_WEB_TOPUP_HOLD',
        'Balance held for web topup request',
        [
            'financial_operation' => $financialClaim,
            'ledger_extra' => [
                'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'USER_WEB_TOPUP_CREATE_HOLD'),
                'request_id' => $requestId,
                'ref_id' => $requestId,
                'account_country' => (string)($financials['account_country'] ?? ''),
                'topup_amount_bdt' => $amount,
                'commission_per_1000' => $financials['commission_per_1000'],
                'commission_bdt' => $financials['commission_bdt'],
                'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
                'wallet_debit_amount' => $walletDebit,
                'wallet_debit_currency' => $financials['wallet_debit_currency'],
                'wallet_currency' => $financials['wallet_currency'],
                'rate_used' => $financials['rate_used'],
            ],
        ]
    );

    if (!($hold['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($hold['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($hold['message'] ?? 'Failed to hold balance'),
            'data' => (array)($hold['data'] ?? []),
        ];
    }

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => (string)($userRow['phone'] ?? ''),
        'topup_number' => $topupNumber,
        'operator' => $operator,
        'operator_name' => user_proxy_operator_name($operator),
        'country_code' => $countryCode,
        'execution_mode' => function_exists('topup_operator_execution_mode') ? topup_operator_execution_mode($countryCode, $operator) : 'WORKER_USSD',
        'worker_claimable' => function_exists('topup_operator_worker_claimable') ? topup_operator_worker_claimable($countryCode, $operator) : true,
        'WORKER_CLAIMABLE' => function_exists('topup_operator_worker_claimable') ? topup_operator_worker_claimable($countryCode, $operator) : true,
        'manual_telegram_required' => function_exists('topup_operator_worker_claimable') ? !topup_operator_worker_claimable($countryCode, $operator) : false,
        'amount' => $amount,
        'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
        'topup_currency' => (string)($financials['topup_currency'] ?? ($countryCode === 'MY' ? 'MYR' : 'BDT')),
        'amount_bdt' => (float)($financials['amount_bdt'] ?? 0),
        'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
        'amount_myr' => (float)($financials['amount_myr'] ?? 0),
        'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
        'account_country' => (string)($financials['account_country'] ?? ''),
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
        'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
        'commission_amount' => (float)($financials['commission_amount'] ?? $financials['commission_bdt'] ?? 0),
        'commission_credit' => (float)($financials['commission_credit'] ?? 0),
        'fee_amount' => (float)($financials['fee_amount'] ?? 0),
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'wallet_currency' => $financials['wallet_currency'],
        'display_currency' => (string)($financials['display_currency'] ?? $financials['wallet_currency']),
        'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
        'rate_snapshot' => $financials['rate_snapshot'] ?? null,
        'rate_used' => $financials['rate_used'],
        'balance_before' => (float)($financials['balance_before'] ?? 0),
        'balance_after' => (float)($financials['balance_after'] ?? 0),
        'calculation_version' => (string)($financials['calculation_version'] ?? ''),
        'total_debit_bdt' => $financials['total_debit_bdt'],
        'total_debit' => $walletDebit,
        'charged_amount' => $walletDebit,
        'note' => $note,
        'request_pin_verified' => true,
        'wallet_hold_amount' => $walletDebit,
        'held_amount' => $walletDebit,
        'status' => 'PENDING',
        'assigned_device_id' => '',
        'assigned_slot' => '',
        'source' => 'USER_PANEL',
        'request_source' => 'USER_PANEL',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $ok = fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $row);

    if (!$ok) {
        wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_CREATE_FAILED', 'User web topup request could not be saved after wallet hold', [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_id' => $requestId,
            'request_row' => $row,
            'request_finalized' => false,
        ]);

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create topup request',
            'data' => [],
        ];
    }

    user_proxy_create_request_status(
        $requestId,
        $uid,
        'PENDING',
        'Topup request created from web dashboard',
        'TOPUP'
    );

    user_proxy_write_user_request_log(
        $uid,
        $requestId,
        $row,
        'TOPUP',
        'PENDING',
        'Topup request created from web dashboard'
    );

    if (function_exists('system_log')) {
        system_log('USER_WEB_TOPUP_CREATE', $requestId, 'User created topup request from dashboard', [
            'uid' => $uid,
            'operator' => $operator,
            'topup_number' => $topupNumber,
            'amount' => $amount,
            'commission_per_1000' => $financials['commission_per_1000'],
            'commission_bdt' => $financials['commission_bdt'],
            'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
            'wallet_debit_amount' => $walletDebit,
            'wallet_debit_currency' => $financials['wallet_debit_currency'],
            'rate_used' => $financials['rate_used'],
        ]);
    }

    topup_notify_telegram_request($row);

    $responseData = [
        'request_id' => $requestId,
        'uid' => $uid,
        'status' => 'PENDING',
        'operator' => $operator,
        'operator_name' => user_proxy_operator_name($operator),
        'topup_number' => $topupNumber,
        'amount' => $amount,
        'amount_bdt' => $amount,
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'rate_used' => $financials['rate_used'],
        'total_debit' => $walletDebit,
        'created_at' => $now,
        'wallet' => [
            'available_balance' => (float)($hold['after_available'] ?? $hold['available_balance'] ?? 0),
            'hold_balance' => (float)($hold['after_hold'] ?? $hold['hold_balance'] ?? 0),
        ],
    ];

    wallet_financial_operation_mark_completed($financialClaim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_finalized' => true,
        'history_written' => true,
        'notification_written' => true,
        'request_id' => $requestId,
        'ledger_id' => (string)($hold['ledger_id'] ?? ''),
        'result_data' => $responseData,
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Topup request created successfully',
        'data' => $responseData,
    ];
}

/* =========================================================
   Create Bundle
========================================================= */

function user_proxy_create_bundle_request(string $uid, string $offerId, string $bundleNumber, string $pin, string $note = ''): array
{
    $uid = trim($uid);
    $offerId = trim($offerId);
    $bundleNumber = user_proxy_normalize_phone($bundleNumber);
    $pin = trim($pin);
    $note = trim($note);

    if ($uid === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'uid is required', 'data' => []];
    }

    if ($offerId === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'offer_id is required', 'data' => []];
    }

    if ($bundleNumber === '' || strlen($bundleNumber) < 10) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Valid bundle number is required', 'data' => []];
    }

    if ($pin === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'PIN is required', 'data' => []];
    }

    $user = user_proxy_load_user($uid);

    if (!$user) {
        return ['ok' => false, 'code' => 'USER_NOT_FOUND', 'message' => 'User not found', 'data' => []];
    }

    $user['uid'] = $uid;

    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $roleSettings = user_proxy_load_role_settings($uid, $role);

    if ($status !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is inactive', 'data' => []];
    }

    if (!(bool)($roleSettings['bundle_enabled'] ?? false)) {
        return ['ok' => false, 'code' => 'BUNDLE_DISABLED', 'message' => 'Bundle is disabled for this account', 'data' => []];
    }

    $pinHash = (string)($user['pin_hash'] ?? '');

    if ($pinHash === '' || !password_verify($pin, $pinHash)) {
        return ['ok' => false, 'code' => 'INVALID_PIN', 'message' => 'Invalid transaction PIN', 'data' => []];
    }

    $baseOffer = user_proxy_load_bundle_offer_direct($offerId);

    if (!$baseOffer) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => ['offer_id' => $offerId],
        ];
    }

    $now = function_exists('bundle_now') ? (int)bundle_now() : user_proxy_now();

    if (!user_proxy_offer_is_active($baseOffer, $now)) {
        return [
            'ok' => false,
            'code' => 'OFFER_INACTIVE',
            'message' => 'Bundle offer is inactive or expired',
            'data' => ['offer_id' => $offerId],
        ];
    }

    $subadminUid = user_proxy_find_owner_subadmin_uid($user);
    $customMap = user_proxy_load_bundle_custom_commission_map($subadminUid);
    $offer = user_proxy_build_bundle_offer_internal($baseOffer, $user, $subadminUid, $customMap);

    if (!$offer) {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Bundle offer data is invalid',
            'data' => ['offer_id' => $offerId],
        ];
    }

    $operator = user_proxy_operator_code((string)($offer['operator'] ?? ''));
    $bundleName = trim((string)($offer['bundle_name'] ?? $offer['name'] ?? ''));
    $priceAmount = user_proxy_round_money($offer['price_amount'] ?? $offer['amount'] ?? 0);
    $adminCommission = user_proxy_round_money($offer['admin_commission'] ?? 0);
    $userCommission = user_proxy_round_money($offer['user_commission'] ?? 0);
    $subadminProfit = user_proxy_round_money($offer['subadmin_profit'] ?? 0);
    $payableAmount = user_proxy_round_money($offer['payable_amount'] ?? $offer['you_pay'] ?? 0);
    $customizedBySubadmin = (bool)($offer['customized_by_subadmin'] ?? false);

    if ($operator === '' || $bundleName === '' || $priceAmount <= 0 || $payableAmount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Bundle offer data is invalid',
            'data' => ['offer_id' => $offerId],
        ];
    }

    $requestId = user_proxy_make_id('BR');
    $userPhone = trim((string)($user['phone'] ?? ''));
    $wallet = user_proxy_load_wallet($uid);
    $bundleFinancials = bundle_wallet_breakdown($uid, $payableAmount, $user, $wallet);
    $walletHoldAmount = (float)$bundleFinancials['wallet_hold_amount'];
    $operationSeed = hash('sha256', implode('|', [
        'USER_WEB_BUNDLE_CREATE',
        $uid,
        $offerId,
        $operator,
        $bundleNumber,
        number_format($walletHoldAmount, 2, '.', ''),
        (string)floor(user_proxy_now() / 120),
    ]));
    $operationRef = 'USER_WEB_BUNDLE_CREATE:' . hash('sha256', $operationSeed);
    $operation = wallet_financial_operation_begin($operationRef, 'USER_WEB_BUNDLE_CREATE_HOLD', 'REQUEST_CREATE', $uid, $walletHoldAmount, (string)$bundleFinancials['wallet_currency'], [
        'request_id' => $requestId,
        'offer_id' => $offerId,
        'bundle_number_hash' => hash('sha256', $bundleNumber),
    ]);
    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Bundle request created successfully',
            'data' => $resultData,
        ];
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'),
            'message' => (string)($operation['message'] ?? 'Wallet operation is unavailable'),
            'data' => [],
        ];
    }
    $financialClaim = (array)$operation['claim'];
    $requestId = trim((string)($financialClaim['meta']['request_id'] ?? $requestId));

    $hold = user_proxy_hold_balance(
        $uid,
        $walletHoldAmount,
        $requestId,
        'USER_WEB_BUNDLE_HOLD',
        'Balance held for web bundle request',
        [
            'financial_operation' => $financialClaim,
            'ledger_extra' => [
                'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'USER_WEB_BUNDLE_CREATE_HOLD'),
                'request_id' => $requestId,
                'ref_id' => $requestId,
                'offer_id' => $offerId,
                'price_amount' => $priceAmount,
                'you_pay' => $payableAmount,
                'payable_amount' => $payableAmount,
                'payable_amount_bdt' => $payableAmount,
                'admin_commission' => $adminCommission,
                'user_commission' => $userCommission,
                'subadmin_profit' => $subadminProfit,
            ],
        ]
    );

    if (!($hold['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($hold['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($hold['message'] ?? 'Failed to hold balance'),
            'data' => (array)($hold['data'] ?? []),
        ];
    }

    $extra = [
        'offer_id' => $offerId,
        'offer_source' => 'USER_PANEL',
        'source' => 'USER_PANEL',
        'request_source' => 'USER_PANEL',
        'created_from_user_panel' => true,
        'created_from_api' => false,
        'key_id' => 'PANEL',
        'source_key_id' => 'PANEL',

        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,

        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'customer_commission' => $userCommission,
        'user_discount' => $userCommission,

        'subadmin_profit' => $subadminProfit,
        'subadmin_uid' => $subadminUid,
        'customized_by_subadmin' => $customizedBySubadmin,

        'net_cost_after_commission' => $payableAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'payable_amount_bdt' => $payableAmount,
        'wallet_hold_amount' => $walletHoldAmount,
        'held_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'wallet_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],
        'hold_settled_at' => 0,
        'hold_settlement_status' => 'PENDING',
    ];

    $requestSaved = false;

    if (function_exists('create_bundle_pending_request')) {
        $requestSaved = create_bundle_pending_request(
            $requestId,
            $uid,
            $userPhone,
            $bundleNumber,
            $operator,
            $bundleName,
            $priceAmount,
            $note !== '' ? $note : 'Bundle request created from user panel',
            false,
            '',
            $extra
        );
    } else {
        $requestSaved = fb_put('BUNDLE_REQUESTS/PENDING/' . $requestId, array_merge([
            'request_id' => $requestId,
            'uid' => $uid,
            'user_phone' => $userPhone,
            'bundle_number' => $bundleNumber,
            'operator' => $operator,
            'operator_name' => user_proxy_operator_name($operator),
            'bundle_name' => $bundleName,
            'note' => $note !== '' ? $note : 'Bundle request created from user panel',
            'status' => 'WAITING_ADMIN',
            'telegram_sent' => false,
            'telegram_queue_id' => '',
            'commission_status' => 'PENDING',
            'commission_credited_at' => 0,
            'user_commission_credited' => false,
            'subadmin_profit_credited' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $extra));
    }

    if (!$requestSaved) {
        wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_CREATE_FAILED', 'User web bundle request could not be saved after wallet hold', [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_id' => $requestId,
            'request_extra' => $extra,
            'request_finalized' => false,
        ]);

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create bundle request',
            'data' => [],
        ];
    }

    user_proxy_create_request_status(
        $requestId,
        $uid,
        'WAITING_ADMIN',
        'Bundle request created from user panel',
        'BUNDLE'
    );

    $logRow = array_merge([
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => $userPhone,
        'bundle_number' => $bundleNumber,
        'operator' => $operator,
        'operator_name' => user_proxy_operator_name($operator),
        'bundle_name' => $bundleName,
        'note' => $note !== '' ? $note : 'Bundle request created from user panel',
        'status' => 'WAITING_ADMIN',
        'created_at' => $now,
        'updated_at' => $now,
    ], $extra);

    user_proxy_write_user_request_log(
        $uid,
        $requestId,
        $logRow,
        'BUNDLE',
        'WAITING_ADMIN',
        'Bundle request created from user panel'
    );

    if (function_exists('system_log')) {
        system_log('USER_WEB_BUNDLE_CREATE', $requestId, 'User created bundle request from dashboard', [
            'uid' => $uid,
            'offer_id' => $offerId,
            'operator' => $operator,
            'bundle_number' => $bundleNumber,
            'bundle_name' => $bundleName,
            'price_amount' => $priceAmount,
            'user_commission' => $userCommission,
            'payable_amount' => $payableAmount,
        ]);
    }

    $responseData = [
        'request_id' => $requestId,
        'uid' => $uid,
        'status' => 'WAITING_ADMIN',
        'offer_id' => $offerId,
        'operator' => $operator,
        'operator_name' => user_proxy_operator_name($operator),
        'bundle_number' => $bundleNumber,
        'bundle_name' => $bundleName,

        'amount' => $payableAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,
        'user_commission' => $userCommission,
        'net_cost_after_commission' => $payableAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'wallet_hold_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],

        'created_at' => $now,
        'wallet' => [
            'available_balance' => (float)($hold['after_available'] ?? $hold['available_balance'] ?? 0),
            'hold_balance' => (float)($hold['after_hold'] ?? $hold['hold_balance'] ?? 0),
        ],
    ];

    wallet_financial_operation_mark_completed($financialClaim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_finalized' => true,
        'history_written' => true,
        'notification_written' => true,
        'request_id' => $requestId,
        'ledger_id' => (string)($hold['ledger_id'] ?? ''),
        'result_data' => $responseData,
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle request created successfully',
        'data' => $responseData,
    ];
}


/* =========================================================
   MFS / bKash / Nagad Helpers
========================================================= */

function user_proxy_mfs_provider(string $provider): string
{
    $provider = strtoupper(trim($provider));

    $map = [
        'BKASH' => 'BKASH',
        'B-KASH' => 'BKASH',
        'বিকাশ' => 'BKASH',
        'NAGAD' => 'NAGAD',
        'নগদ' => 'NAGAD',
    ];

    return $map[$provider] ?? '';
}

function user_proxy_mfs_provider_name(string $provider): string
{
    $provider = user_proxy_mfs_provider($provider);

    if ($provider === 'BKASH') {
        return 'bKash';
    }

    if ($provider === 'NAGAD') {
        return 'Nagad';
    }

    return $provider;
}

function user_proxy_mfs_type(string $type): string
{
    $type = strtoupper(trim($type));

    $map = [
        'SEND' => 'SEND_MONEY',
        'SEND_MONEY' => 'SEND_MONEY',
        'PERSONAL' => 'SEND_MONEY',
        'CASHOUT' => 'CASH_OUT',
        'CASH_OUT' => 'CASH_OUT',
        'AGENT_CASH_OUT' => 'CASH_OUT',
    ];

    return $map[$type] ?? 'SEND_MONEY';
}

function user_proxy_country_code_from_user(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        return auth_pricing_country_from_user($user, $wallet);
    }

    if (function_exists('security_user_country_code')) {
        $code = security_user_country_code($user, $wallet);
        if ($code !== '') {
            return $code;
        }
    }

    $country = strtoupper(trim((string)(
        $user['pricing_country']
        ?? $user['market_country']
        ?? $user['service_country']
        ?? $user['country_code']
        ?? $user['country']
        ?? $user['user_country']
        ?? ''
    )));

    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',
        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
    ];

    if (isset($map[$country])) {
        return $map[$country];
    }

    if (defined('DEFAULT_USER_COUNTRY')) {
        $default = strtoupper(trim((string)DEFAULT_USER_COUNTRY));
        return $map[$default] ?? $default;
    }

    return 'BD';
}

function user_proxy_wallet_currency_for_user(array $user, array $wallet): string
{
    if (function_exists('security_user_wallet_currency')) {
        $currency = security_user_wallet_currency($user, $wallet);
        if ($currency !== '') {
            return $currency;
        }
    }

    $currency = strtoupper(trim((string)(
        $user['wallet_currency']
        ?? $user['currency']
        ?? $wallet['wallet_currency']
        ?? $wallet['currency']
        ?? ''
    )));

    $map = [
        'BDT' => 'BDT',
        'TK' => 'BDT',
        'TAKA' => 'BDT',
        'MYR' => 'MYR',
        'RM' => 'MYR',
        'RINGGIT' => 'MYR',
    ];

    if (isset($map[$currency])) {
        return $map[$currency];
    }

    return 'BDT';
}

function user_proxy_mfs_service_mode(string $countryCode, string $walletCurrency): string
{
    $countryCode = strtoupper(trim($countryCode));
    $walletCurrency = strtoupper(trim($walletCurrency));

    if ($countryCode === 'MY' || $walletCurrency === 'MYR') {
        return 'REMITTANCE';
    }

    return 'LOCAL';
}

function user_proxy_mfs_rate_myr_to_bdt(): float
{
    $paths = [
        'MFS_SETTINGS/rate_myr_bdt',
        'MFS_SETTINGS/rates/myr_to_bdt',
        'MFS_CONFIG/RATE/MYR_TO_BDT',
        'MFS_CONFIG/RATES/MYR_TO_BDT',
        'APP_CONFIG/MYR_TO_BDT_RATE',
        'APP_CONFIG/RINGGIT_RATE',
        'RINGGIT_RATE',
        'RINGGIT RATE',
    ];

    foreach ($paths as $path) {
        $value = fb_get($path);

        if (is_numeric($value) && (float)$value > 0) {
            return user_proxy_round_money($value);
        }

        if (is_array($value)) {
            foreach (['rate', 'value', 'amount', 'bdt'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key]) && (float)$value[$key] > 0) {
                    return user_proxy_round_money($value[$key]);
                }
            }
        }
    }

    if (defined('MYR_TO_BDT_RATE') && (float)MYR_TO_BDT_RATE > 0) {
        return user_proxy_round_money(MYR_TO_BDT_RATE);
    }

    return 31.00;
}

function user_proxy_mfs_my_fee_myr(string $role, string $provider = ''): float
{
    $role = strtoupper(trim($role));
    $provider = user_proxy_mfs_provider($provider);

    $paths = [
        'MFS_SETTINGS/fees/MY/' . $provider . '/' . $role,
        'MFS_CONFIG/MY_FEES/' . $provider . '/' . $role,
        'MFS_CONFIG/REMITTANCE_FEES/' . $provider . '/' . $role,
    ];

    if ($role === 'USER') {
        $paths[] = 'MFS_SETTINGS/fees/MY/' . $provider . '/fee_rm';
        $paths[] = 'MFS_SETTINGS/fees/MY/' . $provider . '/fixed';
        $paths[] = 'MFS_SETTINGS/fees/MY/' . $provider . '/fixed_fee';
    }

    $paths[] = 'MFS_CONFIG/MY_FEES/' . $role;
    $paths[] = 'MFS_CONFIG/REMITTANCE_FEES/' . $role;
    $paths[] = 'APP_CONFIG/MFS/MY_FEES/' . $role;

    foreach ($paths as $path) {
        if (strpos($path, '//') !== false) {
            continue;
        }

        $value = fb_get($path);

        if (is_numeric($value)) {
            return user_proxy_round_money($value);
        }

        if (is_array($value)) {
            foreach (['fee_myr', 'fee', 'amount', 'rm'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return user_proxy_round_money($value[$key]);
                }
            }
        }
    }

    if ($role === 'SUBADMIN' && defined('MY_REMITTANCE_FEE_SUBADMIN_RM')) {
        return user_proxy_round_money(MY_REMITTANCE_FEE_SUBADMIN_RM);
    }

    if ($role === 'RETAILER' && defined('MY_REMITTANCE_FEE_RETAILER_RM')) {
        return user_proxy_round_money(MY_REMITTANCE_FEE_RETAILER_RM);
    }

    if (defined('MY_REMITTANCE_FEE_USER_RM')) {
        return user_proxy_round_money(MY_REMITTANCE_FEE_USER_RM);
    }

    if ($role === 'SUBADMIN' || $role === 'RETAILER') {
        return 2.00;
    }

    if ($role === 'ADMIN') {
        return 0.00;
    }

    return 5.00;
}

function user_proxy_mfs_bd_fee_bdt(string $provider, string $mfsType, float $amountBdt): float
{
    $provider = user_proxy_mfs_provider($provider);
    $mfsType = user_proxy_mfs_type($mfsType);
    $amountBdt = user_proxy_round_money($amountBdt);

    $paths = [
        'MFS_SETTINGS/fees/BD/' . $provider . '/' . $mfsType,
        'MFS_SETTINGS/fees/BD/' . $provider,
        'MFS_CONFIG/BD_FEES/' . $provider . '/' . $mfsType,
        'MFS_CONFIG/LOCAL_FEES/' . $provider . '/' . $mfsType,
        'APP_CONFIG/MFS/BD_FEES/' . $provider . '/' . $mfsType,
    ];

    foreach ($paths as $path) {
        $row = fb_get($path);

        if (is_numeric($row)) {
            return user_proxy_round_money($row);
        }

        if (!is_array($row)) {
            continue;
        }

        $fixed = user_proxy_round_money($row['fixed_bdt'] ?? $row['fixed'] ?? 0);
        $percent = (float)($row['percent'] ?? $row['rate'] ?? 0);

        if ($percent > 1) {
            $percent = $percent / 100;
        }

        $minFee = user_proxy_round_money($row['min_fee_bdt'] ?? $row['min_fee'] ?? 0);
        $maxFee = user_proxy_round_money($row['max_fee_bdt'] ?? $row['max_fee'] ?? 0);

        $fee = user_proxy_round_money($fixed + ($amountBdt * $percent));

        if ($minFee > 0 && $fee < $minFee) {
            $fee = $minFee;
        }

        if ($maxFee > 0 && $fee > $maxFee) {
            $fee = $maxFee;
        }

        return user_proxy_round_money($fee);
    }

    return 0.00;
}

function user_proxy_mfs_preview_payload(string $uid, array $body): array
{
    $uid = trim($uid);

    $user = user_proxy_load_user($uid);
    if (!$user) {
        return ['ok' => false, 'code' => 'USER_NOT_FOUND', 'message' => 'User not found', 'data' => []];
    }

    $user['uid'] = $uid;

    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $wallet = user_proxy_load_wallet($uid);

    if ($status !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is inactive', 'data' => []];
    }

    $provider = user_proxy_mfs_provider((string)($body['provider'] ?? $body['mfs_provider'] ?? ''));
    $mfsType = user_proxy_mfs_type((string)($body['mfs_type'] ?? $body['transfer_type'] ?? 'SEND_MONEY'));
    $receiverNumber = user_proxy_normalize_phone((string)(
        $body['receiver_number']
        ?? $body['account_number']
        ?? $body['number']
        ?? ''
    ));

    if ($provider === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Valid provider is required', 'data' => []];
    }

    if (!in_array($provider, ['BKASH', 'NAGAD'], true)) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Only bKash and Nagad are supported', 'data' => []];
    }

    if ($receiverNumber === '' || !preg_match('/^01\d{9}$/', $receiverNumber)) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Receiver number must be 11 digits BD number', 'data' => []];
    }

    $countryCode = user_proxy_country_code_from_user($user, $wallet);
    $walletCurrency = user_proxy_wallet_currency_for_user($user, $wallet);
    $serviceMode = user_proxy_mfs_service_mode($countryCode, $walletCurrency);
    $rate = user_proxy_mfs_rate_myr_to_bdt();

    if ($serviceMode === 'REMITTANCE' && $mfsType !== 'SEND_MONEY') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Malaysia users can only use personal send money', 'data' => []];
    }

    $amountBdtInput = user_proxy_round_money($body['amount_bdt'] ?? $body['amount'] ?? 0);
    $amountMyrInput = user_proxy_round_money($body['amount_rm'] ?? $body['amount_myr'] ?? $body['rm_amount'] ?? 0);

    $amountBdt = 0.00;
    $amountMyr = 0.00;
    $feeBdt = 0.00;
    $feeMyr = 0.00;
    $totalPayBdt = 0.00;
    $totalPayMyr = 0.00;
    $walletHoldAmount = 0.00;

    if ($serviceMode === 'REMITTANCE') {
        if ($amountBdtInput > 0) {
            $amountBdt = $amountBdtInput;
            $amountMyr = $rate > 0 ? user_proxy_round_money($amountBdt / $rate) : 0.00;
        } else {
            $amountMyr = $amountMyrInput;
            $amountBdt = user_proxy_round_money($amountMyr * $rate);
        }

        if ($amountBdt < 500 || $amountBdt > 50000 || $amountMyr <= 0) {
            return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Amount must be between BDT 500 and BDT 50,000', 'data' => []];
        }

        $feeMyr = user_proxy_mfs_my_fee_myr($role, $provider);
        $feeBdt = user_proxy_round_money($feeMyr * $rate);
        $totalPayMyr = user_proxy_round_money($amountMyr + $feeMyr);
        $totalPayBdt = user_proxy_round_money($amountBdt + $feeBdt);
        $walletHoldAmount = $walletCurrency === 'MYR' ? $totalPayMyr : $totalPayBdt;
    } else {
        $amountBdt = $amountBdtInput > 0 ? $amountBdtInput : user_proxy_round_money($amountMyrInput * $rate);

        if ($amountBdt < 500 || $amountBdt > 50000) {
            return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Amount must be between BDT 500 and BDT 50,000', 'data' => []];
        }

        $feeBdt = user_proxy_mfs_bd_fee_bdt($provider, $mfsType, $amountBdt);
        $totalPayBdt = user_proxy_round_money($amountBdt + $feeBdt);
        $amountMyr = $rate > 0 ? user_proxy_round_money($amountBdt / $rate) : 0.00;
        $feeMyr = $rate > 0 ? user_proxy_round_money($feeBdt / $rate) : 0.00;
        $totalPayMyr = $rate > 0 ? user_proxy_round_money($totalPayBdt / $rate) : 0.00;
        $walletHoldAmount = $totalPayBdt;
    }

    $available = user_proxy_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = user_proxy_round_money((float)($wallet['hold_balance'] ?? 0));
    $canPay = $available >= $walletHoldAmount;

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS preview ready',
        'data' => [
            'uid' => $uid,
            'role' => $role,
            'country_code' => $countryCode,
            'service_mode' => $serviceMode,
            'wallet_currency' => $walletCurrency,

            'provider' => $provider,
            'provider_name' => user_proxy_mfs_provider_name($provider),
            'mfs_provider' => $provider,
            'mfs_type' => $mfsType,
            'account_type' => 'PERSONAL',
            'receiver_number' => $receiverNumber,
            'number' => $receiverNumber,

            'amount_bdt' => $amountBdt,
            'amount_myr' => $amountMyr,
            'fee_bdt' => $feeBdt,
            'fee_myr' => $feeMyr,
            'total_pay_bdt' => $totalPayBdt,
            'total_pay_myr' => $totalPayMyr,
            'wallet_hold_amount' => $walletHoldAmount,
            'rate_myr_to_bdt' => $rate,

            'available_balance' => $available,
            'hold_balance' => $hold,
            'can_pay' => $canPay,
            'trxid' => '',
            'reference' => trim((string)($body['reference'] ?? '')),
        ],
    ];
}

function user_proxy_hold_balance_currency(string $uid, float $amount, string $requestId, string $type, string $note, string $currency, array $options = []): array
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $currency = strtoupper(trim($currency));
    $amount = user_proxy_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid wallet hold data',
            'data' => [],
        ];
    }

    if (function_exists('wallet_hold_amount')) {
        $options['ledger_extra'] = array_merge([
            'request_id' => $requestId,
            'ref_id' => $requestId,
            'currency' => $currency,
            'wallet_currency' => $currency,
            'note' => $note,
            'created_by_uid' => $uid,
            'created_by_role' => 'USER',
        ], is_array($options['ledger_extra'] ?? null) ? $options['ledger_extra'] : []);

        $holdResult = wallet_hold_amount($uid, $amount, $requestId, $type, $options);
        if (!empty($holdResult['ok'])) {
            return [
                'ok' => true,
                'code' => 'SUCCESS',
                'message' => 'Balance held successfully',
                'ledger_id' => (string)($holdResult['ledger_id'] ?? ''),
                'available_balance' => (float)($holdResult['available_balance'] ?? $holdResult['after_available'] ?? 0),
                'hold_balance' => (float)($holdResult['hold_balance'] ?? $holdResult['after_hold'] ?? 0),
                'before_available' => (float)($holdResult['before_available'] ?? 0),
                'after_available' => (float)($holdResult['after_available'] ?? $holdResult['available_balance'] ?? 0),
                'before_hold' => (float)($holdResult['before_hold'] ?? 0),
                'after_hold' => (float)($holdResult['after_hold'] ?? $holdResult['hold_balance'] ?? 0),
                'currency' => $currency,
            ];
        }

        return [
            'ok' => false,
            'code' => (string)($holdResult['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($holdResult['message'] ?? 'Failed to hold balance'),
            'data' => (array)($holdResult['data'] ?? []),
        ];
    }

    return [
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'message' => 'Wallet hold helper is unavailable',
        'data' => [],
    ];
}

function user_proxy_send_mfs_telegram(array $row): void
{
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID')) {
        return;
    }

    $token = trim((string)TELEGRAM_BOT_TOKEN);
    $chatId = trim((string)TELEGRAM_CHAT_ID);

    if ($token === '' || $chatId === '') {
        return;
    }

    $requestId = (string)($row['request_id'] ?? '');
    $provider = (string)($row['mfs_provider'] ?? '');
    $currency = strtoupper(trim((string)($row['wallet_currency'] ?? '')));
    $number = (string)($row['receiver_number'] ?? '');
    $serviceMode = strtoupper(trim((string)($row['service_mode'] ?? '')));

    if ($serviceMode === 'REMITTANCE') {
        $rate = (float)($row['rate_myr_to_bdt'] ?? $row['exchange_rate'] ?? 0);
        $amountRm = (float)($row['amount_myr'] ?? $row['amount_rm'] ?? 0);
        if ($amountRm <= 0 && $rate > 0 && (float)($row['amount_bdt'] ?? 0) > 0) {
            $amountRm = round((float)$row['amount_bdt'] / $rate, 2);
        }
        $feeRm = (float)($row['fee_myr'] ?? $row['fee_rm'] ?? 0);
        if ($feeRm <= 0 && $rate > 0 && (float)($row['fee_bdt'] ?? 0) > 0) {
            $feeRm = round((float)$row['fee_bdt'] / $rate, 2);
        }
        $totalRm = (float)($row['total_pay_myr'] ?? $row['total_debit_rm'] ?? 0);
        if ($totalRm <= 0) {
            $totalRm = $amountRm + $feeRm;
        }
        $amountLine = 'Received Amount: BDT ' . user_proxy_round_money($row['amount_bdt'] ?? 0)
            . "\nSend Amount: RM " . user_proxy_round_money($amountRm)
            . "\nFee: RM " . user_proxy_round_money($feeRm)
            . "\nTotal Paid: RM " . user_proxy_round_money($totalRm);
        $amountLine .= "\nRate: RM 1 = BDT " . user_proxy_round_money($row['rate_myr_to_bdt'] ?? 0);
    } else {
        $amountLine = 'Received Amount: BDT ' . user_proxy_round_money($row['amount_bdt'] ?? 0) . "\nFee: BDT " . user_proxy_round_money($row['fee_bdt'] ?? 0) . "\nTotal Paid: BDT " . user_proxy_round_money($row['total_pay_bdt'] ?? 0);
    }

    $text =
        "🔔 New MFS Request\n\n" .
        "ID: {$requestId}\n" .
        "Provider: {$provider}\n" .
        "Role: " . (string)($row['user_role'] ?? $row['role'] ?? 'USER') . "\n" .
        "Mode: {$serviceMode}\n" .
        "Type: " . (string)($row['mfs_type'] ?? 'SEND_MONEY') . "\n" .
        "Number: {$number}\n" .
        $amountLine . "\n" .
        "Reference: " . (string)($row['reference'] ?? '-') . "\n" .
        "Status: PENDING";

    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';

    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query($payload),
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    fb_put('MFS_TELEGRAM_LOGS/' . $requestId, [
        'request_id' => $requestId,
        'http_status' => $http,
        'raw' => is_string($raw) ? substr($raw, 0, 800) : '',
        'sent_at' => user_proxy_now(),
    ]);
}

function user_proxy_create_mfs_request(string $uid, array $body): array
{
    $preview = user_proxy_mfs_preview_payload($uid, $body);

    if (!($preview['ok'] ?? false)) {
        return $preview;
    }

    $data = (array)($preview['data'] ?? []);

    if (empty($data['can_pay'])) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient available balance',
            'data' => [
                'available_balance' => (float)($data['available_balance'] ?? 0),
                'required_amount' => (float)($data['wallet_hold_amount'] ?? 0),
                'currency' => (string)($data['wallet_currency'] ?? 'BDT'),
            ],
        ];
    }

    $pin = trim((string)($body['pin'] ?? ''));

    if ($pin === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'PIN is required', 'data' => []];
    }

    $user = user_proxy_load_user($uid);
    $pinHash = (string)($user['pin_hash'] ?? '');

    if ($pinHash === '' || !password_verify($pin, $pinHash)) {
        return ['ok' => false, 'code' => 'INVALID_PIN', 'message' => 'Invalid transaction PIN', 'data' => []];
    }

    $requestId = user_proxy_make_id('MFS');
    $now = user_proxy_now();
    $operationSeed = trim((string)($body['idempotency_key'] ?? $body['client_request_id'] ?? $body['request_reference'] ?? ''));
    if ($operationSeed === '') {
        $operationSeed = hash('sha256', implode('|', [
            'USER_WEB_MFS_CREATE',
            $uid,
            (string)$data['mfs_provider'],
            (string)$data['mfs_type'],
            (string)$data['receiver_number'],
            number_format((float)$data['wallet_hold_amount'], 2, '.', ''),
            (string)$data['wallet_currency'],
            (string)floor($now / 120),
        ]));
    }
    $operationRef = 'USER_WEB_MFS_CREATE:' . hash('sha256', implode('|', [$uid, $operationSeed]));
    $operation = wallet_financial_operation_begin($operationRef, 'USER_WEB_MFS_CREATE_HOLD', 'REQUEST_CREATE', $uid, (float)$data['wallet_hold_amount'], (string)$data['wallet_currency'], [
        'request_id' => $requestId,
        'provider' => (string)$data['mfs_provider'],
        'receiver_hash' => hash('sha256', (string)$data['receiver_number']),
    ]);
    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'MFS request created successfully',
            'data' => $resultData,
        ];
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'),
            'message' => (string)($operation['message'] ?? 'Wallet operation is unavailable'),
            'data' => [],
        ];
    }
    $financialClaim = (array)$operation['claim'];
    $requestId = trim((string)($financialClaim['meta']['request_id'] ?? $requestId));

    $hold = user_proxy_hold_balance_currency(
        $uid,
        (float)$data['wallet_hold_amount'],
        $requestId,
        'USER_WEB_MFS_HOLD',
        'Balance held for MFS request',
        (string)$data['wallet_currency'],
        [
            'financial_operation' => $financialClaim,
            'ledger_extra' => [
                'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'USER_WEB_MFS_CREATE_HOLD'),
            ],
        ]
    );

    if (!($hold['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($hold['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($hold['message'] ?? 'Failed to hold balance'),
            'data' => (array)($hold['data'] ?? []),
        ];
    }

    $reference = trim((string)($body['reference'] ?? ''));
    if ($reference === '') {
        $reference = 'ZPay Swift';
    }

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => (string)($user['phone'] ?? ''),
        'user_name' => (string)($user['name'] ?? ''),
        'user_role' => (string)($data['role'] ?? $user['role'] ?? 'USER'),
        'role' => (string)($data['role'] ?? $user['role'] ?? 'USER'),

        'request_type' => 'MFS',
        'type' => 'MFS',
        'action' => 'MFS',

        'mfs_provider' => (string)$data['mfs_provider'],
        'provider' => (string)$data['mfs_provider'],
        'provider_name' => (string)$data['provider_name'],
        'mfs_type' => (string)$data['mfs_type'],
        'transfer_type' => (string)$data['mfs_type'],
        'account_type' => 'PERSONAL',

        'receiver_number' => (string)$data['receiver_number'],
        'account_number' => (string)$data['receiver_number'],
        'topup_number' => (string)$data['receiver_number'],
        'number' => (string)$data['receiver_number'],

        'country_code' => (string)$data['country_code'],
        'service_mode' => (string)$data['service_mode'],
        'wallet_currency' => (string)$data['wallet_currency'],
        'currency' => (string)$data['wallet_currency'],

        'amount_bdt' => (float)$data['amount_bdt'],
        'amount_myr' => (float)$data['amount_myr'],
        'fee_bdt' => (float)$data['fee_bdt'],
        'fee_myr' => (float)$data['fee_myr'],
        'total_pay_bdt' => (float)$data['total_pay_bdt'],
        'total_pay_myr' => (float)$data['total_pay_myr'],
        'wallet_hold_amount' => (float)$data['wallet_hold_amount'],
        'held_amount' => (float)$data['wallet_hold_amount'],
        'rate_myr_to_bdt' => (float)$data['rate_myr_to_bdt'],

        'reference' => $reference,
        'trxid' => '',

        'request_pin_verified' => true,
        'status' => 'PENDING',
        'message' => 'Request received',
        'source' => 'USER_PANEL',
        'request_source' => 'USER_PANEL',
        'created_from_user_panel' => true,
        'created_from_api' => false,
        'key_id' => 'PANEL',
        'source_key_id' => 'PANEL',

        'hold_settled_at' => 0,
        'hold_settlement_status' => 'PENDING',

        'telegram_sent' => false,
        'telegram_sent_at' => 0,

        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => 0,
    ];

    $ok = mfs_move_request_bucket($requestId, '', 'PENDING', $row);

    if (!$ok) {
        wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_CREATE_FAILED', 'MFS request could not be saved after wallet hold', [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_id' => $requestId,
            'request_row' => $row,
            'request_finalized' => false,
        ]);

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create MFS request',
            'data' => [],
        ];
    }

    user_proxy_create_request_status(
        $requestId,
        $uid,
        'PENDING',
        'Request received',
        'MFS'
    );

    user_proxy_write_user_request_log(
        $uid,
        $requestId,
        $row,
        'MFS',
        'PENDING',
        'Request received'
    );

    user_proxy_send_mfs_telegram($row);

    fb_patch('MFS_REQUESTS/PENDING/' . $requestId, [
        'telegram_sent' => true,
        'telegram_sent_at' => user_proxy_now(),
        'updated_at' => user_proxy_now(),
    ]);

    if (function_exists('system_log')) {
        system_log('USER_WEB_MFS_CREATE', $requestId, 'User created MFS request from dashboard', [
            'uid' => $uid,
            'provider' => $row['mfs_provider'],
            'receiver_number' => $row['receiver_number'],
            'service_mode' => $row['service_mode'],
            'wallet_currency' => $row['wallet_currency'],
            'wallet_hold_amount' => $row['wallet_hold_amount'],
        ]);
    }

    $response = user_proxy_public_request_log($row, $requestId);
    $responseWallet = [
        'available_balance' => (float)($hold['after_available'] ?? $hold['available_balance'] ?? 0),
        'hold_balance' => (float)($hold['after_hold'] ?? $hold['hold_balance'] ?? 0),
        'currency' => (string)$data['wallet_currency'],
        'wallet_currency' => (string)$data['wallet_currency'],
    ];
    if (function_exists('mfs_wallet_display_payload')) {
        $responseWallet += mfs_wallet_display_payload(is_array($user) ? $user : [], $responseWallet);
    }
    $response['wallet'] = $responseWallet;

    wallet_financial_operation_mark_completed($financialClaim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_finalized' => true,
        'history_written' => true,
        'notification_written' => true,
        'request_id' => $requestId,
        'ledger_id' => (string)($hold['ledger_id'] ?? ''),
        'result_data' => $response,
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request created successfully',
        'data' => $response,
    ];
}


/* =========================================================
   Auth / Registration / Forgot Password Forwarders
========================================================= */

function user_proxy_forward_auth_post(string $relativePath, array $body, string $fallbackCode, string $fallbackMessage): void
{
    $headers = [
        'X-APP-KEY' => APP_KEY,
    ];
    $forwardedCountry = function_exists('market_request_ip_country')
        ? auth_normalize_country_code(market_request_ip_country($body))
        : '';
    $forwardedSignature = function_exists('auth_country_forwarding_signature')
        ? auth_country_forwarding_signature($forwardedCountry)
        : '';

    if ($forwardedCountry !== '' && $forwardedSignature !== '') {
        $headers['X-ZPAY-REQUEST-COUNTRY'] = $forwardedCountry;
        $headers['X-ZPAY-REQUEST-COUNTRY-SIGNATURE'] = $forwardedSignature;
    }

    if (function_exists('market_request_ip') && function_exists('market_forwarding_signature')) {
        $forwardedIp = market_request_ip();
        $forwardedIpSignature = market_forwarding_signature('ip', $forwardedIp);

        if ($forwardedIp !== '' && $forwardedIpSignature !== '') {
            $headers['X-ZPAY-CLIENT-IP'] = $forwardedIp;
            $headers['X-ZPAY-CLIENT-IP-SIGNATURE'] = $forwardedIpSignature;
        }

        $rawIpCountryDetails = function_exists('market_request_ip_country_details')
            ? market_request_ip_country_details($body)
            : [
                'country' => market_request_ip_country($body),
                'source' => 'UNKNOWN',
            ];
        $rawIpCountry = (string)($rawIpCountryDetails['country'] ?? 'UNKNOWN');
        $ipCountrySignature = market_forwarding_signature('ip-country', $rawIpCountry);

        if ($rawIpCountry !== '' && $ipCountrySignature !== '') {
            $headers['X-ZPAY-IP-COUNTRY'] = $rawIpCountry;
            $headers['X-ZPAY-IP-COUNTRY-SIGNATURE'] = $ipCountrySignature;
        }

        $rawIpSource = market_ip_country_source($rawIpCountryDetails['source'] ?? 'UNKNOWN');
        $ipSourceSignature = market_forwarding_signature('ip-source', $rawIpSource);

        if ($rawIpSource !== '' && $ipSourceSignature !== '') {
            $headers['X-ZPAY-IP-SOURCE'] = $rawIpSource;
            $headers['X-ZPAY-IP-SOURCE-SIGNATURE'] = $ipSourceSignature;
        }
    }

    $res = user_proxy_internal_api_request('POST', $relativePath, $body, $headers);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];

        user_proxy_response(
            false,
            (string)($json['code'] ?? $fallbackCode),
            (string)($json['message'] ?? $fallbackMessage),
            (array)($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 400
        );
    }

    user_proxy_response(
        true,
        (string)($res['json']['code'] ?? 'SUCCESS'),
        (string)(($res['json']['message'] ?? '') ?: 'Success'),
        (array)($res['json']['data'] ?? [])
    );
}

function user_proxy_forward_auth_multipart(
    string $relativePath,
    array $fields,
    array $files,
    string $fallbackCode,
    string $fallbackMessage
): void {
    $res = user_proxy_internal_multipart_request(
        $relativePath,
        $fields,
        $files,
        ['X-APP-KEY' => APP_KEY, 'X-ZPAY-CLIENT' => 'USER_WEB']
    );
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];

    user_proxy_response(
        !empty($res['ok']),
        (string)($json['code'] ?? $fallbackCode),
        (string)($json['message'] ?? $fallbackMessage),
        (array)($json['data'] ?? []),
        (int)(($res['status'] ?? 0) > 0 ? $res['status'] : 502)
    );
}

/* =========================================================
   Router
========================================================= */

$action = trim((string)($_GET['action'] ?? ''));

switch ($action) {
    case 'maintenance_status':
        user_proxy_require_method('POST');
        system_require_user_service_available();

        user_proxy_response(true, 'SUCCESS', 'User service is available', [
            'maintenance_mode' => false,
        ]);
        break;

    case 'country_defaults':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $ipCountry = function_exists('market_request_ip_country')
            ? market_request_ip_country($body)
            : '';
        $defaultCountry = in_array($ipCountry, ['BD', 'MY'], true) ? $ipCountry : 'MY';

        user_proxy_response(true, 'SUCCESS', 'Country defaults loaded', [
            'ip_country' => $ipCountry,
            'phone_country' => $defaultCountry,
            'pricing_country' => $defaultCountry,
            'market_country' => $defaultCountry,
            'currency' => $defaultCountry === 'MY' ? 'MYR' : 'BDT',
        ]);
        break;

    case 'registration_location_check':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
        $decision = market_registration_decision($body, $phoneCountry);

        if (empty($decision['ok'])) {
            user_proxy_response(
                false,
                (string)($decision['code'] ?? 'LOCATION_REQUIRED'),
                (string)($decision['message'] ?? 'Location permission is required to create an account.'),
                [],
                422
            );
        }

        unset(
            $decision['ok'],
            $decision['created_ip'],
            $decision['ip_risk_reason']
        );

        user_proxy_response(
            true,
            (string)($decision['code'] ?? 'SUCCESS'),
            (string)($decision['message'] ?? 'Registration market verified'),
            $decision
        );
        break;

    case 'login_trusted_account':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $trustedDeviceCookie = user_proxy_get_trust_cookie();
        if ($trustedDeviceCookie === '') {
            user_proxy_response(true, 'TRUSTED_LOGIN_UNAVAILABLE', 'Trusted login is not available', [
                'trusted_login_available' => false,
            ]);
        }

        $trustedRes = user_proxy_internal_api_request('POST', 'auth/check_number.php', [
            'device_id' => 'USER_WEB',
            'device_name' => 'User Dashboard',
            'app_version' => 'WEB',
            'trusted_device_cookie' => $trustedDeviceCookie,
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$trustedRes['ok']) {
            $trustedJson = is_array($trustedRes['json'] ?? null) ? $trustedRes['json'] : [];
            if (strtoupper((string)($trustedJson['code'] ?? '')) === 'MAINTENANCE') {
                user_proxy_response(false, 'MAINTENANCE', system_maintenance_message(), [], 503);
            }

            user_proxy_response(true, 'TRUSTED_LOGIN_UNAVAILABLE', 'Trusted login is not available', [
                'trusted_login_available' => false,
            ]);
        }

        $trustedData = (array)($trustedRes['json']['data'] ?? []);
        if (empty($trustedData['trusted_login_available']) || trim((string)($trustedData['pre_auth_token'] ?? '')) === '') {
            user_proxy_response(true, 'TRUSTED_LOGIN_UNAVAILABLE', 'Trusted login is not available', [
                'trusted_login_available' => false,
            ]);
        }

        user_proxy_response(true, 'TRUSTED_ACCOUNT_FOUND', 'Trusted account found', $trustedData);
        break;

    case 'login_check_number':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $phone = trim((string)($body['phone'] ?? ''));
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));

        if ($phone === '' || !in_array($phoneCountry, ['BD', 'MY'], true)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'A valid phone and phone country are required', [], 422);
        }

        $ignoreTrustedDevice = user_proxy_bool_value($body['ignore_trusted_device'] ?? false);
        user_proxy_forward_auth_post('auth/check_number.php', [
            'phone' => $phone,
            'phone_country' => $phoneCountry,
            'country_code' => $phoneCountry === 'BD' ? '+880' : '+60',
            'device_id' => 'USER_WEB',
            'device_name' => 'User Dashboard',
            'app_version' => 'WEB',
            'trusted_device_cookie' => $ignoreTrustedDevice ? '' : user_proxy_get_trust_cookie(),
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
        ], 'ACCOUNT_CHECK_FAILED', 'Account could not be verified');
        break;

    case 'login_verify_password':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $phone = trim((string)($body['phone'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));

        if ($phone === '' || $password === '' || !in_array($phoneCountry, ['BD', 'MY'], true)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Phone, phone country and password are required', [], 422);
        }

        user_proxy_forward_auth_post('auth/verify_password.php', [
            'phone' => $phone,
            'phone_country' => $phoneCountry,
            'country_code' => $phoneCountry === 'BD' ? '+880' : '+60',
            'password' => $password,
            'device_id' => 'USER_WEB',
            'device_name' => 'User Dashboard',
            'app_version' => 'WEB',
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
        ], 'PASSWORD_VERIFY_FAILED', 'Password could not be verified');
        break;

    case 'login_verify_pin':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $pin = trim((string)($body['pin'] ?? ''));

        if ($preAuthToken === '' || !preg_match('/^\d{4}$/', $pin)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'A valid login verification and 4 digit PIN are required', [], 422);
        }

        $pinRes = user_proxy_internal_api_request('POST', 'auth/verify_pin.php', [
            'pre_auth_token' => $preAuthToken,
            'pin' => $pin,
            'device_id' => 'USER_WEB',
            'device_name' => 'User Dashboard',
            'app_version' => 'WEB',
            'force_otp' => true,
            'trusted_device_cookie' => user_proxy_get_trust_cookie(),
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$pinRes['ok']) {
            $json = is_array($pinRes['json'] ?? null) ? $pinRes['json'] : [];
            user_proxy_response(
                false,
                (string)($json['code'] ?? 'PIN_VERIFY_FAILED'),
                (string)($json['message'] ?? 'PIN could not be verified'),
                (array)($json['data'] ?? []),
                $pinRes['status'] > 0 ? (int)$pinRes['status'] : 400
            );
        }

        $pinData = (array)($pinRes['json']['data'] ?? []);
        $sessionToken = trim((string)($pinData['session_token'] ?? ''));
        if ($sessionToken !== '' && empty($pinData['otp_required'])) {
            user_proxy_finalize_login_with_session_token($sessionToken);
            user_proxy_response(true, 'SUCCESS', 'Trusted device login successful', [
                'login_complete' => true,
                'session_active' => true,
                'redirect' => 'dashboard',
                'user' => $_SESSION['user_user'],
                'csrf' => user_proxy_get_csrf(),
            ]);
        }

        user_proxy_response(
            true,
            (string)($pinRes['json']['code'] ?? 'PIN_VERIFIED'),
            (string)($pinRes['json']['message'] ?? 'PIN verified'),
            $pinData
        );
        break;

    case 'login_send_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));

        if ($preAuthToken === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Login verification is required before OTP', [], 422);
        }

        user_proxy_forward_auth_post('auth/login_send_otp.php', [
            'pre_auth_token' => $preAuthToken,
        ], 'OTP_SEND_FAILED', 'OTP could not be sent');
        break;

    case 'login':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $phone = trim((string)($body['phone'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $trustDevice = user_proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
        $deviceName = trim((string)($body['device_name'] ?? 'User Dashboard'));
        $trustedDeviceCookie = user_proxy_get_trust_cookie();
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));

        if ($phone === '' || $password === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Phone and password are required', [], 422);
        }

        $loginRes = user_proxy_internal_api_request('POST', 'auth/user_login_start.php', [
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
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$loginRes['ok']) {
            $json = $loginRes['json'] ?? [];

            user_proxy_response(
                false,
                (string)($json['code'] ?? 'LOGIN_FAILED'),
                (string)($json['message'] ?? 'Login failed'),
                (array)($json['data'] ?? []),
                $loginRes['status'] > 0 ? $loginRes['status'] : 401
            );
        }

        $data = (array)($loginRes['json']['data'] ?? []);

        if (!empty($data['require_otp'])) {
            user_proxy_response(true, 'OTP_REQUIRED', (string)($loginRes['json']['message'] ?? 'OTP verification required'), [
                'require_otp' => true,
                'pre_auth_token' => (string)($data['pre_auth_token'] ?? ''),
                'otp_request_id' => (string)($data['otp_request_id'] ?? ''),
                'masked_phone' => (string)($data['masked_phone'] ?? ''),
                'expires_in_seconds' => (int)($data['expires_in_seconds'] ?? 300),
            ]);
        }

        $sessionToken = trim((string)($data['session_token'] ?? ''));
        user_proxy_finalize_login_with_session_token($sessionToken);

        user_proxy_response(true, 'SUCCESS', 'Login successful', [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
            'user' => $_SESSION['user_user'],
            'csrf' => user_proxy_get_csrf(),
        ]);
        break;

    case 'login_verify_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
        $otp = trim((string)($body['otp'] ?? ''));
        $trustDevice = user_proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
        $deviceName = trim((string)($body['device_name'] ?? 'User Dashboard'));

        if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
        }

        $verifyRes = user_proxy_internal_api_request('POST', 'auth/user_login_verify_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
            'otp' => $otp,
            'trust_device' => $trustDevice,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$verifyRes['ok']) {
            $json = $verifyRes['json'] ?? [];

            user_proxy_response(
                false,
                (string)($json['code'] ?? 'OTP_VERIFY_FAILED'),
                (string)($json['message'] ?? 'OTP verification failed'),
                (array)($json['data'] ?? []),
                $verifyRes['status'] > 0 ? $verifyRes['status'] : 400
            );
        }

        $data = (array)($verifyRes['json']['data'] ?? []);
        $sessionToken = trim((string)($data['session_token'] ?? ''));

        user_proxy_finalize_login_with_session_token($sessionToken);

        if (!empty($data['trusted_device_cookie']) && is_array($data['trusted_device_cookie'])) {
            user_proxy_set_trust_cookie($data['trusted_device_cookie']);
        }

        user_proxy_response(true, 'SUCCESS', 'OTP verified successfully', [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
            'user' => $_SESSION['user_user'],
            'csrf' => user_proxy_get_csrf(),
        ]);
        break;

    case 'login_resend_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string)($body['otp_request_id'] ?? ''));

        if ($preAuthToken === '' || $otpRequestId === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
        }

        $resendRes = user_proxy_internal_api_request('POST', 'auth/user_login_resend_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$resendRes['ok']) {
            $json = $resendRes['json'] ?? [];

            user_proxy_response(
                false,
                (string)($json['code'] ?? 'OTP_RESEND_FAILED'),
                (string)($json['message'] ?? 'Failed to resend OTP'),
                (array)($json['data'] ?? []),
                $resendRes['status'] > 0 ? $resendRes['status'] : 400
            );
        }

        $data = (array)($resendRes['json']['data'] ?? []);

        user_proxy_response(true, 'SUCCESS', 'OTP resent successfully', [
            'require_otp' => true,
            'pre_auth_token' => (string)($data['pre_auth_token'] ?? $preAuthToken),
            'otp_request_id' => (string)($data['otp_request_id'] ?? $otpRequestId),
            'masked_phone' => (string)($data['masked_phone'] ?? ''),
            'expires_in_seconds' => (int)($data['expires_in_seconds'] ?? 300),
        ]);
        break;

    case 'logout':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $token = user_proxy_get_session_token();
        $trustedDeviceCookie = user_proxy_get_trust_cookie();

        if ($token !== '') {
            user_proxy_internal_api_request('POST', 'auth/logout.php', [
                'preserve_trusted_device' => $trustedDeviceCookie !== '',
                'trusted_device_cookie' => $trustedDeviceCookie,
            ], [
                'X-APP-KEY' => APP_KEY,
                'X-SESSION-TOKEN' => $token,
            ]);
        }

        user_proxy_clear_session();
        session_regenerate_id(true);

        user_proxy_response(true, 'SUCCESS', 'Logout successful', []);
        break;

    case 'me':
        user_proxy_require_method('GET');

        $user = user_proxy_require_login(true, false);

        user_proxy_response(true, 'SUCCESS', 'Session valid', [
            'user' => $user,
            'csrf' => user_proxy_get_csrf(),
        ]);
        break;

    case 'dashboard_bootstrap':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));

        $limit = (int)($_GET['limit'] ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $month = user_proxy_valid_month_key($_GET['month'] ?? null);
        $summaryOnly = in_array(
            strtolower(trim((string)($_GET['summary_only'] ?? ''))),
            ['1', 'true', 'yes'],
            true
        );

        user_proxy_response(true, 'SUCCESS', 'Dashboard bootstrap loaded', [
            'user' => $sessionUser,
            'csrf' => user_proxy_get_csrf(),
            'wallet_summary' => user_proxy_wallet_summary_payload($uid, $sessionUser),
            'request_logs' => [
                'uid' => $uid,
                'month' => $month,
                'items' => user_proxy_collect_request_logs($uid, $limit, false, $month),
                'wallet_history' => $summaryOnly ? [] : user_proxy_collect_wallet_received($uid, $month, $limit),
                'add_money_history' => $summaryOnly ? [] : add_money_public_request_rows(add_money_list_user_history($uid, $limit)),
                'history_complete' => !$summaryOnly,
            ],
            'loaded_at' => user_proxy_now(),
        ]);
        break;

    case 'wallet_summary':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));

        $payload = user_proxy_wallet_summary_payload($uid, $sessionUser);

        if (trim((string)($payload['uid'] ?? '')) === '') {
            user_proxy_response(false, 'NOT_FOUND', 'User not found', [], 404);
        }

        user_proxy_response(true, 'SUCCESS', 'Wallet summary loaded', $payload);
        break;

    case 'wallet_history':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $month = user_proxy_valid_month_key($_GET['month'] ?? null);
        $limit = (int)($_GET['limit'] ?? 50);
        $items = user_proxy_collect_wallet_received($uid, $month, $limit);

        user_proxy_response(true, 'SUCCESS', 'Wallet received history loaded', [
            'uid' => $uid,
            'month' => $month,
            'items' => $items,
            'count' => count($items),
        ]);
        break;

    case 'add_money_settings':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $userRow = user_proxy_load_user($uid);
        if (!$userRow) {
            $userRow = $sessionUser;
        }
        $walletRow = user_proxy_load_wallet($uid);

        user_proxy_response(true, 'SUCCESS', 'Add money settings loaded', [
            'profile' => add_money_user_payload($userRow, $walletRow),
            'history' => add_money_public_request_rows(add_money_list_user_history($uid, 25)),
        ]);
        break;

    case 'add_money_history':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

        user_proxy_response(true, 'SUCCESS', 'Add money history loaded', [
            'uid' => $uid,
            'items' => add_money_public_request_rows(add_money_list_user_history($uid, $limit)),
        ]);
        break;

    case 'add_money_submit':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $userRow = user_proxy_load_user($uid);
        if (!$userRow) {
            $userRow = $sessionUser;
        }
        $walletRow = user_proxy_load_wallet($uid);
        $body = !empty($_POST) ? $_POST : user_proxy_read_json_body();
        $res = add_money_create_request($uid, $userRow, $walletRow, $body, $_FILES);

        if (empty($res['ok'])) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');
            $httpStatus = in_array($code, [
                'VALIDATION_ERROR',
                'INVALID_AMOUNT',
                'INVALID_METHOD',
                'TXN_REQUIRED',
                'SENDER_REQUIRED',
                'DUPLICATE_TXN_ID',
                'DUPLICATE_TRANSACTION_ID',
                'DUPLICATE_RECEIPT',
                'RECEIPT_REQUIRED',
                'RECEIPT_UPLOAD_FAILED',
                'INVALID_RECEIPT',
                'INVALID_RECEIPT_SIZE',
                'INVALID_RECEIPT_TYPE',
                'PAYMENT_ACCOUNT_REQUIRED',
                'PAYMENT_ACCOUNT_INVALID',
                'PAYMENT_ACCOUNT_UNAVAILABLE',
                'REQUEST_IN_PROGRESS',
                'ADD_MONEY_DISABLED',
            ], true) ? 422 : 500;
            user_proxy_response(false, $code, (string)($res['message'] ?? 'Failed to submit add money request'), (array)($res['data'] ?? []), $httpStatus);
        }

        user_proxy_response(true, 'SUCCESS', 'Add money request submitted. Please wait for approval.', (array)($res['data'] ?? []));
        break;

    case 'request_logs':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 100);
        $legacy = user_proxy_bool_value($_GET['legacy'] ?? false);
        $month = user_proxy_valid_month_key($_GET['month'] ?? null);

        if ($limit <= 0) {
            $limit = 100;
        }

        if ($limit > 300) {
            $limit = 300;
        }

        user_proxy_response(true, 'SUCCESS', 'Request logs loaded', [
            'uid' => $uid,
            'month' => $month,
            'items' => user_proxy_collect_request_logs($uid, $limit, $legacy, $month),
            'wallet_history' => user_proxy_collect_wallet_received($uid, $month, $limit),
            'add_money_history' => add_money_public_request_rows(add_money_list_user_history($uid, $limit)),
            'mode' => $legacy ? 'fast_with_legacy_fallback' : 'fast',
        ]);
        break;

    case 'validate_pin':
    case 'pin_validate':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();
        $pin = trim((string)($body['pin'] ?? $body['transaction_pin'] ?? ''));

        $res = user_proxy_validate_transaction_pin($uid, $pin);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');
            $httpStatus = 500;

            if ($code === 'VALIDATION_ERROR') {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to validate PIN'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'PIN verified'),
            (array)($res['data'] ?? []),
            200
        );
        break;
        
        
    case 'mfs_preview':
    case 'bkash_preview':
    case 'nagad_preview':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();

        if ($action === 'bkash_preview') {
            $body['provider'] = 'BKASH';
        } elseif ($action === 'nagad_preview') {
            $body['provider'] = 'NAGAD';
        }

        user_proxy_forward_authenticated_json(
            'POST',
            'mfs/preview.php',
            $body,
            'MFS_PREVIEW_FAILED',
            'MFS preview could not be loaded.'
        );
        break;

    case 'mfs_create':
    case 'mfs_create_panel':
    case 'bkash_create':
    case 'nagad_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();

        if ($action === 'bkash_create') {
            $body['provider'] = 'BKASH';
        } elseif ($action === 'nagad_create') {
            $body['provider'] = 'NAGAD';
        }
        $body['source'] = 'USER_API';

        user_proxy_forward_authenticated_json(
            'POST',
            'mfs/create.php',
            $body,
            'MFS_CREATE_FAILED',
            'MFS request could not be created.'
        );
        break;
        
        

    case 'bundle_offers_panel':
    case 'bundle_offers':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $operator = trim((string)($_GET['operator'] ?? ''));

        $res = user_proxy_bundle_offers_for_user($uid, $operator);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;

            if (in_array($code, ['VALIDATION_ERROR', 'BUNDLE_DISABLED'], true)) {
                $httpStatus = 422;
            } elseif ($code === 'ACCOUNT_INACTIVE') {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to load bundle offers'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Bundle offers loaded successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;

    case 'bundle_create_panel':
    case 'bundle_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();

        $offerId = trim((string)($body['offer_id'] ?? ''));
        $bundleNumber = trim((string)($body['bundle_number'] ?? $body['number'] ?? ''));
        $pin = trim((string)($body['pin'] ?? ''));
        $note = trim((string)($body['note'] ?? ''));

        $res = user_proxy_create_bundle_request($uid, $offerId, $bundleNumber, $pin, $note);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;

            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE', 'BUNDLE_DISABLED', 'OFFER_INACTIVE', 'INVALID_OFFER'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
                $httpStatus = 403;
            } elseif (in_array($code, ['USER_NOT_FOUND', 'OFFER_NOT_FOUND'], true)) {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to create bundle request'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Bundle request created successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;
        
        
    case 'bundle_preview':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'bundle/preview.php',
            $body,
            'BUNDLE_PREVIEW_FAILED',
            'Bundle preview could not be loaded.'
        );
        break;

    case 'bundle_submit':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'bundle/submit.php',
            $body,
            'BUNDLE_SUBMIT_FAILED',
            'Bundle request could not be submitted.'
        );
        break;

    case 'bundle_favorites':
        user_proxy_require_method('GET');
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $favoriteNode = fb_get(favorite_numbers_path($uid));
        $favorites = is_array($favoriteNode) ? favorite_rows_list($favoriteNode) : [];
        user_proxy_response(true, 'FAVORITES_LOADED', 'Favorite numbers loaded.', [
            'favorites' => $favorites,
            'count' => count($favorites),
            'limit' => FAVORITE_NUMBERS_LIMIT,
        ]);
        break;

    case 'bundle_favorite_add':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $result = favorite_create_for_user($uid, user_proxy_read_json_body());
        if (empty($result['ok'])) {
            user_proxy_response(
                false,
                (string)($result['code'] ?? 'FAVORITE_SAVE_FAILED'),
                (string)($result['message'] ?? 'Favorite number could not be saved.'),
                [],
                (int)($result['http_status'] ?? 400)
            );
        }
        user_proxy_response(
            true,
            'FAVORITE_SAVED',
            'Favorite number saved.',
            (array)($result['data'] ?? [])
        );
        break;

    case 'bundle_favorite_update':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $result = favorite_update_for_user($uid, user_proxy_read_json_body());
        if (empty($result['ok'])) {
            user_proxy_response(
                false,
                (string)($result['code'] ?? 'FAVORITE_UPDATE_FAILED'),
                (string)($result['message'] ?? 'Favorite number could not be updated.'),
                [],
                (int)($result['http_status'] ?? 400)
            );
        }
        user_proxy_response(
            true,
            'FAVORITE_UPDATED',
            'Favorite number updated.',
            (array)($result['data'] ?? [])
        );
        break;

    case 'bundle_favorite_remove':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $result = favorite_delete_for_user($uid, user_proxy_read_json_body());
        if (empty($result['ok'])) {
            user_proxy_response(
                false,
                (string)($result['code'] ?? 'FAVORITE_REMOVE_FAILED'),
                (string)($result['message'] ?? 'Favorite number could not be removed.'),
                [],
                (int)($result['http_status'] ?? 400)
            );
        }
        user_proxy_response(
            true,
            'FAVORITE_REMOVED',
            'Favorite number removed.',
            (array)($result['data'] ?? [])
        );
        break;

    case 'topup_preview':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'topup/preview.php',
            $body,
            'TOPUP_PREVIEW_FAILED',
            'Top-up preview could not be loaded.'
        );
        break;

    case 'topup_submit':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'topup/submit.php',
            $body,
            'TOPUP_SUBMIT_FAILED',
            'Top-up request could not be submitted.'
        );
        break;
        
        

    case 'create_topup':
    case 'topup_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();

        $res = user_proxy_create_topup_request($uid, $body);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;

            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE', 'TOPUP_DISABLED'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to create topup'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Topup request created successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;

    case 'profile_get':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        user_proxy_forward_authenticated_json(
            'GET',
            'auth/session.php',
            null,
            'PROFILE_LOAD_FAILED',
            'Profile could not be loaded.'
        );
        break;

    case 'profile_update':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        $profileBody = [];
        if (array_key_exists('name', $body)) {
            $profileBody['name'] = (string)$body['name'];
        }
        if (array_key_exists('email', $body)) {
            $profileBody['email'] = (string)$body['email'];
        }
        user_proxy_forward_authenticated_json(
            'POST',
            'user/profile_update.php',
            $profileBody,
            'PROFILE_UPDATE_FAILED',
            'Profile could not be updated.'
        );
        break;

    case 'profile_photo_upload':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        user_proxy_forward_authenticated_multipart(
            'user/profile_photo_upload.php',
            [],
            array_intersect_key($_FILES, array_flip(['profile_photo', 'photo', 'avatar', 'file'])),
            'PROFILE_PHOTO_UPLOAD_FAILED',
            'Profile photo could not be updated.'
        );
        break;

    case 'profile_change_password':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'user/change_password.php',
            [
                'current_password' => (string)($body['current_password'] ?? ''),
                'new_password' => (string)($body['new_password'] ?? ''),
                'confirm_password' => (string)($body['confirm_password'] ?? ''),
            ],
            'PASSWORD_UPDATE_FAILED',
            'Password could not be updated.'
        );
        break;

    case 'profile_change_pin':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'user/change_pin.php',
            [
                'current_pin' => (string)($body['current_pin'] ?? ''),
                'new_pin' => (string)($body['new_pin'] ?? ''),
                'confirm_pin' => (string)($body['confirm_pin'] ?? ''),
            ],
            'PIN_UPDATE_FAILED',
            'PIN could not be updated.'
        );
        break;

    case 'transfer_favorites':
        user_proxy_require_method('GET');
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $limit = max(1, min(10, (int)($_GET['limit'] ?? 10)));
        user_proxy_response(true, 'TRANSFER_FAVORITES_OK', 'Favorite receivers loaded.', [
            'favorites' => user_proxy_load_transfer_favorites($uid, $limit),
            'limit' => $limit,
        ]);
        break;

    case 'transfer_favorite_add':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();
        $recipientInput = trim((string)($body['recipient_phone'] ?? $body['receiver_phone'] ?? $body['phone'] ?? ''));
        $recipientDigits = user_proxy_normalize_phone($recipientInput);
        if ($recipientDigits === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Receiver phone number is required.', [], 422);
        }

        $lookup = user_proxy_internal_api_request(
            'POST',
            'transfer/check_recipient.php',
            ['recipient_phone' => $recipientInput],
            user_proxy_authenticated_headers()
        );
        $lookupJson = is_array($lookup['json'] ?? null) ? $lookup['json'] : [];
        $lookupData = is_array($lookupJson['data'] ?? null) ? $lookupJson['data'] : [];
        $recipient = is_array($lookupData['recipient'] ?? null) ? $lookupData['recipient'] : [];
        if (empty($lookup['ok']) || empty($lookupData['can_transfer']) || empty($recipient['can_transfer'])) {
            user_proxy_response(
                false,
                (string)($lookupJson['code'] ?? $lookupData['validation_code'] ?? 'TRANSFER_FAVORITE_INVALID'),
                (string)($lookupJson['message'] ?? $lookupData['validation_message'] ?? 'This receiver cannot be saved.'),
                [],
                (int)(($lookup['status'] ?? 0) > 0 ? $lookup['status'] : 422)
            );
        }

        $receiverPhone = trim((string)($recipient['receiver_phone'] ?? $recipientInput));
        $favoriteId = user_proxy_transfer_favorite_id($receiverPhone);
        $favoritePath = user_proxy_transfer_favorite_path($uid);
        $existing = fb_get($favoritePath . '/' . $favoriteId);
        if (is_array($existing)) {
            user_proxy_response(false, 'TRANSFER_FAVORITE_DUPLICATE', 'This receiver is already in your favorites.', [
                'favorite' => user_proxy_public_transfer_favorite($existing),
                'favorites' => user_proxy_load_transfer_favorites($uid, 10),
            ], 409);
        }

        $currentFavorites = user_proxy_load_transfer_favorites($uid, 20);
        if (count($currentFavorites) >= 10) {
            user_proxy_response(false, 'TRANSFER_FAVORITES_LIMIT', 'You can save up to 10 favorite receivers.', [], 422);
        }

        $now = user_proxy_now();
        $row = [
            'favorite_id' => $favoriteId,
            'uid' => $uid,
            'receiver_uid' => (string)($recipient['receiver_uid'] ?? ''),
            'name' => trim((string)($recipient['receiver_name'] ?? $recipient['receiver_name_masked'] ?? 'Z-Pay User')),
            'phone' => $receiverPhone,
            'phone_masked' => trim((string)($recipient['receiver_phone_masked'] ?? user_proxy_transfer_mask_phone($receiverPhone))),
            'wallet_currency' => strtoupper(trim((string)($lookupData['receiver_wallet_currency'] ?? $lookupData['wallet_currency'] ?? ''))),
            'source' => 'USER_WEB',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (!fb_put($favoritePath . '/' . $favoriteId, $row)) {
            user_proxy_response(false, 'TRANSFER_FAVORITE_SAVE_FAILED', 'Favorite receiver could not be saved.', [], 500);
        }

        user_proxy_response(true, 'TRANSFER_FAVORITE_SAVED', 'Favorite receiver saved.', [
            'favorite' => user_proxy_public_transfer_favorite($row),
            'favorites' => user_proxy_load_transfer_favorites($uid, 10),
        ]);
        break;

    case 'transfer_favorite_remove':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();
        $favoriteId = trim((string)($body['favorite_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $favoriteId)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Favorite receiver is invalid.', [], 422);
        }

        $path = user_proxy_transfer_favorite_path($uid) . '/' . $favoriteId;
        $existing = fb_get($path);
        if (!is_array($existing)) {
            user_proxy_response(true, 'TRANSFER_FAVORITE_REMOVED', 'Favorite receiver removed.', [
                'favorites' => user_proxy_load_transfer_favorites($uid, 10),
            ]);
        }

        if (!fb_delete($path)) {
            user_proxy_response(false, 'TRANSFER_FAVORITE_REMOVE_FAILED', 'Favorite receiver could not be removed.', [], 500);
        }

        user_proxy_response(true, 'TRANSFER_FAVORITE_REMOVED', 'Favorite receiver removed.', [
            'favorites' => user_proxy_load_transfer_favorites($uid, 10),
        ]);
        break;

    case 'transfer_recipient':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'transfer/check_recipient.php',
            ['recipient_phone' => trim((string)($body['recipient_phone'] ?? $body['receiver_phone'] ?? ''))],
            'TRANSFER_LOOKUP_FAILED',
            'Receiver could not be checked.'
        );
        break;

    case 'transfer_preview':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        $sessionUser = user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        $checkOnly = !empty($body['check_only']) || !empty($body['validate_only']);
        if (!$checkOnly) {
            $pinResult = user_proxy_validate_transaction_pin(
                trim((string)($sessionUser['uid'] ?? '')),
                trim((string)($body['pin'] ?? ''))
            );
            if (empty($pinResult['ok'])) {
                $pinCode = (string)($pinResult['code'] ?? 'INVALID_PIN');
                user_proxy_response(
                    false,
                    $pinCode,
                    (string)($pinResult['message'] ?? 'PIN is incorrect.'),
                    [],
                    in_array($pinCode, ['INVALID_PIN', 'ACCOUNT_INACTIVE'], true) ? 403 : 422
                );
            }
        }
        user_proxy_forward_authenticated_json(
            'POST',
            'transfer/preview.php',
            [
                'recipient_phone' => trim((string)($body['recipient_phone'] ?? $body['receiver_phone'] ?? '')),
                'amount' => $body['amount'] ?? 0,
                'check_only' => $checkOnly,
            ],
            'TRANSFER_PREVIEW_FAILED',
            'Transfer preview could not be loaded.'
        );
        break;

    case 'transfer_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $body = user_proxy_read_json_body();
        user_proxy_forward_authenticated_json(
            'POST',
            'transfer/create.php',
            [
                'preview_token' => trim((string)($body['preview_token'] ?? '')),
                'reference' => trim((string)($body['reference'] ?? $body['note'] ?? '')),
            ],
            'TRANSFER_FAILED',
            'Transfer could not be completed.',
            [
                'canonical_only' => true,
                'max_attempts' => 1,
                'connect_timeout' => 15,
                'timeout' => 60,
            ]
        );
        break;

    case 'transfer_history':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        user_proxy_forward_authenticated_json(
            'GET',
            'transfer/history.php?' . http_build_query(['limit' => $limit]),
            null,
            'TRANSFER_HISTORY_FAILED',
            'Transfer history could not be loaded.'
        );
        break;

    case 'support_config':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        user_proxy_forward_authenticated_json('GET', 'support/config.php', null, 'SUPPORT_LOAD_FAILED', 'Support could not be loaded.');
        break;

    case 'support_list':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        $supportStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
        $supportLimit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        user_proxy_forward_authenticated_json(
            'GET',
            'support/list.php?' . http_build_query(['status' => $supportStatus, 'limit' => $supportLimit]),
            null,
            'SUPPORT_LIST_FAILED',
            'Support requests could not be loaded.'
        );
        break;

    case 'support_details':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        $ticketId = trim((string)($_GET['ticket_id'] ?? ''));
        user_proxy_forward_authenticated_json(
            'GET',
            'support/details.php?' . http_build_query(['ticket_id' => $ticketId]),
            null,
            'SUPPORT_DETAILS_FAILED',
            'Support conversation could not be loaded.'
        );
        break;

    case 'support_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $supportFields = array_intersect_key($_POST, array_flip([
            'category_code', 'subject', 'message', 'related_type', 'related_request_id', 'idempotency_key',
        ]));
        user_proxy_forward_authenticated_multipart(
            'support/create.php',
            $supportFields,
            array_intersect_key($_FILES, array_flip(['attachments', 'attachment', 'file'])),
            'SUPPORT_CREATE_FAILED',
            'Support request could not be submitted.'
        );
        break;

    case 'support_reply':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();
        user_proxy_require_login(true, false);
        $supportFields = array_intersect_key($_POST, array_flip([
            'ticket_id', 'message', 'reply_to_message_id', 'idempotency_key',
        ]));
        user_proxy_forward_authenticated_multipart(
            'support/reply.php',
            $supportFields,
            array_intersect_key($_FILES, array_flip(['attachments', 'attachment', 'file'])),
            'SUPPORT_REPLY_FAILED',
            'Reply could not be sent.'
        );
        break;

    case 'support_attachment':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        $ticketId = trim((string)($_GET['ticket_id'] ?? ''));
        $attachmentId = trim((string)($_GET['attachment_id'] ?? ''));
        $binary = user_proxy_internal_binary_request(
            'support/attachment.php?' . http_build_query([
                'ticket_id' => $ticketId,
                'attachment_id' => $attachmentId,
            ]),
            user_proxy_authenticated_headers()
        );
        if (empty($binary['ok'])) {
            $json = json_decode((string)($binary['body'] ?? ''), true);
            user_proxy_response(
                false,
                (string)($json['code'] ?? 'SUPPORT_ATTACHMENT_FAILED'),
                (string)($json['message'] ?? 'Attachment could not be loaded.'),
                [],
                (int)(($binary['status'] ?? 0) > 0 ? $binary['status'] : 502)
            );
        }
        $contentType = (string)($binary['content_type'] ?? '');
        if (!in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            user_proxy_response(false, 'SUPPORT_ATTACHMENT_UNSUPPORTED', 'Attachment type is not supported.', [], 415);
        }
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen((string)$binary['body']));
        header('Content-Disposition: inline; filename="support-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $attachmentId) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        echo (string)$binary['body'];
        exit;

    case 'notifications_list':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        $notificationLimit = max(1, min(50, (int)($_GET['limit'] ?? 30)));
        $notificationFilter = strtoupper(trim((string)($_GET['filter'] ?? 'ALL')));
        user_proxy_forward_authenticated_json(
            'GET',
            'notifications/list.php?' . http_build_query(['limit' => $notificationLimit, 'filter' => $notificationFilter]),
            null,
            'NOTIFICATIONS_LOAD_FAILED',
            'Notifications could not be loaded.'
        );
        break;

    case 'notifications_unread':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        user_proxy_forward_authenticated_json('GET', 'notifications/unread_count.php', null, 'NOTIFICATIONS_LOAD_FAILED', 'Notifications could not be loaded.');
        break;

    case 'notification_mark_read':
        user_proxy_require_method('POST');
        user_proxy_require_login(true, false);
        user_proxy_require_csrf();
        $body = user_proxy_read_json_body();
        $markReadPayload = [];
        if (is_array($body['notification_ids'] ?? null)) {
            $markReadPayload['notification_ids'] = array_values((array)$body['notification_ids']);
        } else {
            $markReadPayload['notification_id'] = trim((string)($body['notification_id'] ?? ''));
        }
        user_proxy_forward_authenticated_json(
            'POST',
            'notifications/mark_read.php',
            $markReadPayload,
            'NOTIFICATION_UPDATE_FAILED',
            'Notification could not be updated.'
        );
        break;

    case 'notification_details':
        user_proxy_require_method('GET');
        user_proxy_require_login(true, false);
        $notificationId = trim((string)($_GET['notification_id'] ?? ''));
        user_proxy_forward_authenticated_json(
            'GET',
            'notifications/details.php?' . http_build_query(['notification_id' => $notificationId]),
            null,
            'NOTIFICATION_DETAILS_FAILED',
            'Notification details could not be loaded.'
        );
        break;

    case 'notifications_delete':
        user_proxy_require_method('POST');
        user_proxy_require_login(true, false);
        user_proxy_require_csrf();
        $body = user_proxy_read_json_body();
        $notificationIds = is_array($body['notification_ids'] ?? null)
            ? array_values((array)$body['notification_ids'])
            : [trim((string)($body['notification_id'] ?? ''))];
        user_proxy_forward_authenticated_json(
            'POST',
            'notifications/delete.php',
            ['notification_ids' => $notificationIds],
            'NOTIFICATION_DELETE_FAILED',
            'Notifications could not be deleted.'
        );
        break;

    case 'notifications_mark_all_read':
        user_proxy_require_method('POST');
        user_proxy_require_login(true, false);
        user_proxy_require_csrf();
        user_proxy_forward_authenticated_json('POST', 'notifications/mark_all_read.php', [], 'NOTIFICATION_UPDATE_FAILED', 'Notifications could not be updated.');
        break;

    case 'register_precheck':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $stage = strtoupper(trim((string)($body['stage'] ?? '')));
        require_once dirname(__DIR__) . '/lib/auth_android.php';
        require_once dirname(__DIR__) . '/lib/register_android.php';
        require_once dirname(__DIR__) . '/lib/user_web_credentials.php';
        require_once dirname(__DIR__) . '/lib/user_registration_identity.php';

        if ($stage === 'PHONE') {
            $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
            if (!in_array($phoneCountry, ['BD', 'MY'], true)) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'This phone country is not supported.', ['field' => 'phone_country'], 422);
            }
            $phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
            if ($phone === '') {
                user_proxy_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), ['field' => 'phone'], 422);
            }
            if (reg_app_phone_uid($phone, $phoneCountry) !== '') {
                user_proxy_response(false, 'PHONE_ALREADY_REGISTERED', 'This phone number is already registered.', [], 409);
            }

            user_proxy_response(true, 'REGISTER_PHONE_AVAILABLE', 'Phone number is available.', [
                'phone_country' => $phoneCountry,
                'phone_available' => true,
            ]);
        }

        if ($stage === 'PERSONAL') {
            $name = trim((string)($body['name'] ?? ''));
            $email = strtolower(trim((string)($body['email'] ?? '')));
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

            if ($name === '' || $nameLength > 100) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'Please enter your full name.', ['field' => 'name'], 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'Please enter a valid email address.', ['field' => 'email'], 422);
            }
            if (reg_app_email_uid($email) !== '') {
                user_proxy_response(false, 'EMAIL_ALREADY_REGISTERED', 'This email is already registered.', [], 409);
            }

            user_proxy_response(true, 'REGISTER_PERSONAL_AVAILABLE', 'Registration details are available.', [
                'email_available' => true,
            ]);
        }

        if ($stage === 'IDENTITY') {
            $identityTypeInput = strtoupper(trim((string)($body['identity_type'] ?? '')));
            $identityNumber = auth_app_identity_number($body);
            if (!in_array($identityTypeInput, ['NID', 'PASSPORT'], true)) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'Select a valid identity type.', ['field' => 'identity_type'], 422);
            }
            $identityHash = auth_app_identity_hash($identityNumber);
            if ($identityHash === '') {
                user_proxy_response(false, 'IDENTITY_REQUIRED', $identityTypeInput === 'PASSPORT' ? 'Passport number is required.' : 'NID number is required.', ['field' => 'identity_number'], 422);
            }
            $identityLookup = user_web_registration_identity_lookup(
                user_web_registration_identity_hashes($identityNumber),
                $identityTypeInput
            );
            if (empty($identityLookup['ok'])) {
                user_proxy_response(false, 'IDENTITY_CHECK_UNAVAILABLE', 'Identity availability could not be checked. Please try again.', [], 503);
            }
            if (!empty($identityLookup['occupied'])) {
                $code = $identityTypeInput === 'PASSPORT' ? 'PASSPORT_ALREADY_REGISTERED' : 'NID_ALREADY_REGISTERED';
                $message = $identityTypeInput === 'PASSPORT'
                    ? 'This Passport is already registered.'
                    : 'This NID is already registered.';
                user_proxy_response(false, $code, $message, [], 409);
            }

            user_proxy_response(true, 'REGISTER_IDENTITY_VALID', 'Identity details are valid.', [
                'identity_type' => $identityTypeInput,
            ]);
        }

        if ($stage === 'PASSWORD') {
            $password = (string)($body['password'] ?? '');
            $confirmPassword = (string)($body['confirm_password'] ?? '');
            if (!user_web_password_valid($password)) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'Password must be exactly 6 digits.', ['field' => 'password'], 422);
            }
            if ($password !== $confirmPassword) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match.', ['field' => 'confirm_password'], 422);
            }

            user_proxy_response(true, 'REGISTER_PASSWORD_VALID', 'Password is valid.');
        }

        if ($stage === 'PIN') {
            $pin = trim((string)($body['pin'] ?? ''));
            $confirmPin = trim((string)($body['confirm_pin'] ?? ''));
            if (!user_web_transaction_pin_valid($pin)) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'PIN must be exactly 4 digits.', ['field' => 'pin'], 422);
            }
            if ($pin !== $confirmPin) {
                user_proxy_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match.', ['field' => 'confirm_pin'], 422);
            }

            user_proxy_response(true, 'REGISTER_PIN_VALID', 'PIN is valid.');
        }

        user_proxy_response(false, 'VALIDATION_ERROR', 'Invalid registration precheck stage.', [], 422);
        break;

    case 'register_prepare_kyc':
        user_proxy_require_method('POST');
        $body = user_proxy_read_json_body();
        user_proxy_forward_auth_post('auth/user_register_prepare_kyc.php', [
            'name' => trim((string)($body['name'] ?? '')),
            'phone' => trim((string)($body['phone'] ?? '')),
            'phone_country' => auth_normalize_country_code((string)($body['phone_country'] ?? '')),
            'email' => trim((string)($body['email'] ?? '')),
            'identity_type' => strtoupper(trim((string)($body['identity_type'] ?? ''))),
            'identity_number' => trim((string)($body['identity_number'] ?? '')),
        ], 'REGISTER_KYC_PREPARE_FAILED', 'Registration verification could not be prepared.');
        break;

    case 'register_upload_kyc':
        user_proxy_require_method('POST');
        $fields = array_intersect_key($_POST, array_flip([
            'pre_auth_token', 'register_token', 'upload_type', 'kyc_type',
        ]));
        user_proxy_forward_auth_multipart(
            'auth/register_upload_kyc.php',
            $fields,
            array_intersect_key($_FILES, array_flip(['document_photo', 'selfie_photo', 'file'])),
            'REGISTER_KYC_UPLOAD_FAILED',
            'Verification image could not be uploaded.'
        );
        break;

    case 'register':
    case 'register_send_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        user_proxy_forward_auth_post('auth/user_register_send_otp.php', [
            'name' => trim((string)($body['name'] ?? '')),
            'phone' => trim((string)($body['phone'] ?? '')),
            'phone_country' => auth_normalize_country_code((string)($body['phone_country'] ?? '')),
            'email' => trim((string)($body['email'] ?? '')),
            'identity_type' => strtoupper(trim((string)($body['identity_type'] ?? 'NID'))),
            'identity_number' => trim((string)($body['identity_number'] ?? '')),
            'password' => (string)($body['password'] ?? ''),
            'confirm_password' => (string)($body['confirm_password'] ?? ''),
            'pin' => trim((string)($body['pin'] ?? '')),
            'confirm_pin' => trim((string)($body['confirm_pin'] ?? '')),
            'terms_accepted' => user_proxy_bool_value($body['terms_accepted'] ?? false),
            'device_id' => trim((string)($body['device_id'] ?? 'USER_WEB')),
            'device_name' => trim((string)($body['device_name'] ?? 'User Register')),
            'user_agent' => security_user_agent(),
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
            'gps_lat' => $body['gps_lat'] ?? null,
            'gps_lng' => $body['gps_lng'] ?? null,
            'gps_accuracy' => $body['gps_accuracy'] ?? null,
            'kyc_register_token' => trim((string)($body['kyc_register_token'] ?? '')),
        ], 'REGISTER_OTP_SEND_FAILED', 'Failed to send register OTP');
        break;

    case 'register_resend_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string)($body['otp_request_id'] ?? ''));

        if ($preAuthToken === '' || $otpRequestId === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_register_resend_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
        ], 'REGISTER_OTP_RESEND_FAILED', 'Failed to resend register OTP');
        break;

    case 'register_confirm':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
        $otp = trim((string)($body['otp'] ?? ''));

        if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_register_confirm.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
            'otp' => $otp,
        ], 'REGISTER_CONFIRM_FAILED', 'Failed to confirm registration');
        break;

    case 'forgot_start':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $phone = trim((string)($body['phone'] ?? ''));
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
        if ($phone === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Phone is required', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_forgot_start.php', [
            'phone' => $phone,
            'phone_country' => $phoneCountry,
            'device_id' => 'USER_WEB',
            'device_name' => 'User Forgot',
            'client_ip' => security_client_ip(),
            'ip_country' => auth_request_ip_country(),
            'user_agent' => security_user_agent(),
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
        ], 'FORGOT_START_FAILED', 'Account recovery could not be started');
        break;

    case 'forgot_verify_identity':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $preAuthToken = trim((string)(
            $body['pre_auth_token']
            ?? $body['reset_token']
            ?? $body['forgot_token']
            ?? ''
        ));
        $identityNumber = trim((string)($body['identity_number'] ?? ''));
        if ($preAuthToken === '' || $identityNumber === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Recovery session and identity number are required', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_forgot_verify_identity.php', [
            'pre_auth_token' => $preAuthToken,
            'reset_token' => $preAuthToken,
            'forgot_token' => $preAuthToken,
            'identity_number' => $identityNumber,
            'device_id' => 'USER_WEB',
            'device_name' => 'User Forgot',
        ], 'FORGOT_IDENTITY_FAILED', 'Identity verification could not be completed');
        break;

    case 'forgot':
    case 'forgot_password':
    case 'forgot_send_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $phone = trim((string)($body['phone'] ?? ''));
        $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
        $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));

        if (!in_array($resetType, ['PASSWORD', 'PIN', 'PASSWORD_PIN'], true)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
        }

        if ($resetType === 'PASSWORD_PIN') {
            $preAuthToken = trim((string)(
                $body['pre_auth_token']
                ?? $body['reset_token']
                ?? $body['forgot_token']
                ?? ''
            ));
            if ($preAuthToken === '') {
                user_proxy_response(false, 'IDENTITY_REQUIRED', 'Identity verification is required before OTP.', [], 409);
            }

            user_proxy_forward_auth_post('auth/user_forgot_send_otp.php', [
                'pre_auth_token' => $preAuthToken,
                'reset_token' => $preAuthToken,
                'forgot_token' => $preAuthToken,
                'reset_type' => 'PASSWORD_PIN',
                'device_id' => 'USER_WEB',
                'device_name' => 'User Forgot',
            ], 'FORGOT_OTP_SEND_FAILED', 'Failed to send forgot OTP');
            break;
        }

        if ($phone === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Phone is required', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_forgot_send_otp.php', [
            'phone' => $phone,
            'phone_country' => $phoneCountry,
            'reset_type' => $resetType,
            'device_id' => trim((string)($body['device_id'] ?? 'USER_WEB')),
            'device_name' => trim((string)($body['device_name'] ?? 'User Forgot')),
            'client_ip' => security_client_ip(),
            'ip_country' => auth_request_ip_country(),
            'user_agent' => security_user_agent(),
            'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
        ], 'FORGOT_OTP_SEND_FAILED', 'Failed to send forgot OTP');
        break;

    case 'forgot_resend_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $preAuthToken = trim((string)(
            $body['pre_auth_token']
            ?? $body['reset_token']
            ?? $body['forgot_token']
            ?? ''
        ));

        $otpRequestId = trim((string)(
            $body['otp_request_id']
            ?? $body['request_id']
            ?? ''
        ));

        if ($preAuthToken === '' || $otpRequestId === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_forgot_resend_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'reset_token' => $preAuthToken,
            'forgot_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
            'request_id' => $otpRequestId,
            'device_id' => 'USER_WEB',
            'device_name' => 'User Forgot',
        ], 'FORGOT_OTP_RESEND_FAILED', 'Failed to resend forgot OTP');
        break;

    case 'forgot_verify_otp':
    case 'forgot_confirm':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $preAuthToken = trim((string)(
            $body['pre_auth_token']
            ?? $body['reset_token']
            ?? $body['forgot_token']
            ?? ''
        ));

        $otpRequestId = trim((string)(
            $body['otp_request_id']
            ?? $body['request_id']
            ?? ''
        ));

        $otp = trim((string)($body['otp'] ?? ''));
        $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));

        if (!in_array($resetType, ['PASSWORD', 'PIN', 'PASSWORD_PIN'], true)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
        }

        if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
        }

        $forwardBody = [
            'pre_auth_token' => $preAuthToken,
            'reset_token' => $preAuthToken,
            'forgot_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
            'request_id' => $otpRequestId,
            'otp' => $otp,
            'reset_type' => $resetType,
            'device_id' => 'USER_WEB',
            'device_name' => 'User Forgot',
            'identity_number' => trim((string)(
                $body['identity_number']
                ?? $body['nid_or_passport_number']
                ?? $body['nid_number']
                ?? $body['passport_number']
                ?? ''
            )),
        ];

        if ($resetType === 'PIN') {
            $forwardBody['new_pin'] = trim((string)($body['new_pin'] ?? ''));
            $forwardBody['confirm_pin'] = trim((string)($body['confirm_pin'] ?? ''));
        } elseif ($resetType === 'PASSWORD') {
            $forwardBody['new_password'] = (string)($body['new_password'] ?? '');
            $forwardBody['confirm_password'] = (string)($body['confirm_password'] ?? '');
        }

        user_proxy_forward_auth_post(
            'auth/user_forgot_verify_otp.php',
            $forwardBody,
            'FORGOT_VERIFY_FAILED',
            'Failed to verify OTP'
        );
        break;

    case 'forgot_reset_credentials':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();
        $preAuthToken = trim((string)(
            $body['pre_auth_token']
            ?? $body['reset_token']
            ?? $body['forgot_token']
            ?? ''
        ));
        $resetAuthorizationToken = trim((string)($body['reset_authorization_token'] ?? ''));

        if ($preAuthToken === '' || $resetAuthorizationToken === '') {
            user_proxy_response(false, 'FORGOT_SESSION_EXPIRED', 'Reset authorization expired. Please start again.', [], 410);
        }

        user_proxy_forward_auth_post('auth/user_forgot_reset.php', [
            'pre_auth_token' => $preAuthToken,
            'reset_token' => $preAuthToken,
            'forgot_token' => $preAuthToken,
            'reset_authorization_token' => $resetAuthorizationToken,
            'new_password' => (string)($body['new_password'] ?? ''),
            'confirm_password' => (string)($body['confirm_password'] ?? ''),
            'new_pin' => trim((string)($body['new_pin'] ?? '')),
            'confirm_pin' => trim((string)($body['confirm_pin'] ?? '')),
            'device_id' => 'USER_WEB',
            'device_name' => 'User Forgot',
            'client_ip' => security_client_ip(),
            'user_agent' => security_user_agent(),
        ], 'FORGOT_RESET_FAILED', 'Password and PIN could not be updated');
        break;

    default:
        user_proxy_response(false, 'NOT_FOUND', 'Unknown proxy action', [], 404);
}
