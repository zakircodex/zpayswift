<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/roles.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/bundle.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

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

    $script = $_SERVER['SCRIPT_NAME'] ?? '/zpayswift/api/user/proxy.php';
    $apiPath = dirname(dirname($script));
    return rtrim(user_proxy_scheme() . '://' . user_proxy_host() . $apiPath, '/');
}

function user_proxy_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = user_proxy_api_base_url() . '/' . ltrim($relativePath, '/');

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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
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
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
        'error' => null,
        'raw' => substr((string)$raw, 0, 800),
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

    setcookie(user_proxy_trust_cookie_name(), $selector . ':' . $token, [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[user_proxy_trust_cookie_name()] = $selector . ':' . $token;
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
        user_proxy_clear_session();

        $msg = $res['json']['message'] ?? 'Session expired';
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
        return $row;
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

    return [
        'offer_id' => $offerId,
        'operator' => $operatorCode ?: strtoupper(trim((string)($row['operator'] ?? ''))),
        'operator_name' => user_proxy_operator_name($operatorCode ?: (string)($row['operator'] ?? '')),
        'bundle_name' => (string)($row['bundle_name'] ?? $row['name'] ?? ''),
        'name' => (string)($row['name'] ?? $row['bundle_name'] ?? ''),
        'description' => (string)($row['description'] ?? ''),

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
        'expires_at' => (int)($item['expires_at'] ?? 0),
        'expired' => (bool)($item['expired'] ?? false),
        'status' => (string)($item['status'] ?? 'ACTIVE'),
        'active' => (bool)($item['active'] ?? true),
        'customized_by_subadmin' => (bool)($item['customized_by_subadmin'] ?? false),
        'created_at' => (int)($item['created_at'] ?? 0),
        'updated_at' => (int)($item['updated_at'] ?? 0),
    ];
}

function user_proxy_bundle_offers_for_user(string $uid): array
{
    $uid = trim($uid);

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

function user_proxy_hold_balance(string $uid, float $amount, string $requestId, string $type, string $note): array
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
        $res = wallet_hold_amount($uid, $amount, $requestId, $type);

        if (is_array($res)) {
            return $res;
        }
    }

    $wallet = user_proxy_load_wallet($uid);
    $now = user_proxy_now();

    $available = user_proxy_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = user_proxy_round_money((float)($wallet['hold_balance'] ?? 0));

    if ($available < $amount) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient available balance',
            'data' => [
                'available_balance' => $available,
                'required_amount' => $amount,
            ],
        ];
    }

    $newAvailable = user_proxy_round_money($available - $amount);
    $newHold = user_proxy_round_money($hold + $amount);

    $ok = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to hold balance',
            'data' => [],
        ];
    }

    $ledgerId = user_proxy_make_id('WL');
    $month = user_proxy_month_key($now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => $type,
        'direction' => 'HOLD',
        'amount' => $amount,
        'currency' => 'BDT',
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => $uid,
        'created_by_role' => 'USER',
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Balance held successfully',
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
    ];
}

function user_proxy_release_hold_rollback(string $uid, float $amount, string $requestId, string $type, string $note): void
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $amount = user_proxy_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0) {
        return;
    }

    $wallet = user_proxy_load_wallet($uid);
    $now = user_proxy_now();

    $available = user_proxy_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = user_proxy_round_money((float)($wallet['hold_balance'] ?? 0));

    $newAvailable = user_proxy_round_money($available + $amount);
    $newHold = user_proxy_round_money(max(0, $hold - $amount));

    fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'updated_at' => $now,
    ]);

    $ledgerId = user_proxy_make_id('WL');
    $month = user_proxy_month_key($now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => $type,
        'direction' => 'RELEASE_HOLD',
        'amount' => $amount,
        'currency' => 'BDT',
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => $uid,
        'created_by_role' => 'SYSTEM',
    ]);
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

function user_proxy_collect_fast_request_logs(string $uid, int $limit = 100): array
{
    $uid = trim($uid);

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

            if ($rid !== '') {
                $map[$rid] = $public;
            }
        }
    }

    $monthKeys = [
        user_proxy_month_key(),
        user_proxy_month_key(strtotime('-1 month') ?: null),
    ];

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

            if ($rid !== '') {
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

function user_proxy_collect_legacy_request_logs(string $uid, int $limit = 100): array
{
    $uid = trim($uid);
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
            $rows[$public['request_id']] = user_proxy_apply_request_status_row($public);
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
            $rows[$public['request_id']] = user_proxy_apply_request_status_row($public);
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

function user_proxy_collect_request_logs(string $uid, int $limit = 100, bool $legacyFallback = false): array
{
    $fast = user_proxy_collect_fast_request_logs($uid, $limit);

    if (!$legacyFallback || count($fast) > 0) {
        return $fast;
    }

    return user_proxy_collect_legacy_request_logs($uid, $limit);
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

    $topupNumber = user_proxy_normalize_phone((string)($body['topup_number'] ?? ''));
    $operator = user_proxy_operator_code((string)($body['operator'] ?? ''));
    $amount = user_proxy_round_money((float)($body['amount'] ?? 0));
    $pin = trim((string)($body['pin'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));

    if ($topupNumber === '' || strlen($topupNumber) < 10) {
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

    [$amountOk, $amountMsg] = user_proxy_validate_amount($amount, $roleSettings);

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

    $requestId = user_proxy_make_id('TR');

    $hold = user_proxy_hold_balance(
        $uid,
        $amount,
        $requestId,
        'USER_WEB_TOPUP_HOLD',
        'Balance held for web topup request'
    );

    if (!($hold['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($hold['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($hold['message'] ?? 'Failed to hold balance'),
            'data' => (array)($hold['data'] ?? []),
        ];
    }

    $now = user_proxy_now();

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => (string)($userRow['phone'] ?? ''),
        'topup_number' => $topupNumber,
        'operator' => $operator,
        'operator_name' => user_proxy_operator_name($operator),
        'amount' => $amount,
        'note' => $note,
        'request_pin_verified' => true,
        'wallet_hold_amount' => $amount,
        'held_amount' => $amount,
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
        user_proxy_release_hold_rollback(
            $uid,
            $amount,
            $requestId,
            'USER_WEB_TOPUP_HOLD_ROLLBACK',
            'Topup hold rollback after request create failure'
        );

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
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Topup request created successfully',
        'data' => [
            'request_id' => $requestId,
            'uid' => $uid,
            'status' => 'PENDING',
            'operator' => $operator,
            'operator_name' => user_proxy_operator_name($operator),
            'topup_number' => $topupNumber,
            'amount' => $amount,
            'created_at' => $now,
            'wallet' => [
                'available_balance' => (float)($hold['after_available'] ?? $hold['available_balance'] ?? 0),
                'hold_balance' => (float)($hold['after_hold'] ?? $hold['hold_balance'] ?? 0),
            ],
        ],
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

    $hold = user_proxy_hold_balance(
        $uid,
        $payableAmount,
        $requestId,
        'USER_WEB_BUNDLE_HOLD',
        'Balance held for web bundle request'
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
        'wallet_hold_amount' => $payableAmount,
        'held_amount' => $payableAmount,
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
        user_proxy_release_hold_rollback(
            $uid,
            $payableAmount,
            $requestId,
            'USER_WEB_BUNDLE_HOLD_ROLLBACK',
            'Bundle hold rollback after request create failure'
        );

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

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle request created successfully',
        'data' => [
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
            'wallet_hold_amount' => $payableAmount,

            'created_at' => $now,
            'wallet' => [
                'available_balance' => (float)($hold['after_available'] ?? $hold['available_balance'] ?? 0),
                'hold_balance' => (float)($hold['after_hold'] ?? $hold['hold_balance'] ?? 0),
            ],
        ],
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

function user_proxy_country_code_from_user(array $user): string
{
    if (function_exists('security_user_country_code')) {
        $code = security_user_country_code($user);
        if ($code !== '') {
            return $code;
        }
    }

    $country = strtoupper(trim((string)(
        $user['country_code']
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

    $phone = trim((string)(
        $user['phone']
        ?? $user['mobile']
        ?? $user['number']
        ?? $user['login_phone']
        ?? ''
    ));
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    if ($digits !== '') {
        if (strpos($phone, '+60') === 0 || preg_match('/^60\d{7,12}$/', $digits)) {
            return 'MY';
        }

        if (strpos($phone, '+880') === 0 || preg_match('/^8801\d{9}$/', $digits) || preg_match('/^01\d{9}$/', $digits)) {
            return 'BD';
        }
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
        'MFS_SETTINGS/fees/MY/' . $provider . '/fee_rm',
        'MFS_SETTINGS/fees/MY/' . $provider . '/fixed',
        'MFS_SETTINGS/fees/MY/' . $provider . '/fixed_fee',
        'MFS_CONFIG/MY_FEES/' . $provider . '/' . $role,
        'MFS_CONFIG/REMITTANCE_FEES/' . $provider . '/' . $role,
    ];

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

    if ($provider !== '') {
        return defined('MY_REMITTANCE_FEE_RM') ? user_proxy_round_money(MY_REMITTANCE_FEE_RM) : 3.00;
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

    return $role === 'RETAILER' ? 3.00 : 5.00;
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

    $countryCode = user_proxy_country_code_from_user($user);
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
        if ($amountMyrInput > 0) {
            $amountMyr = $amountMyrInput;
            $amountBdt = user_proxy_round_money($amountMyr * $rate);
        } else {
            $amountBdt = $amountBdtInput;
            $amountMyr = $rate > 0 ? user_proxy_round_money($amountBdt / $rate) : 0.00;
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

function user_proxy_hold_balance_currency(string $uid, float $amount, string $requestId, string $type, string $note, string $currency): array
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

    $wallet = user_proxy_load_wallet($uid);
    $now = user_proxy_now();

    $available = user_proxy_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = user_proxy_round_money((float)($wallet['hold_balance'] ?? 0));

    if ($available < $amount) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient available balance',
            'data' => [
                'available_balance' => $available,
                'required_amount' => $amount,
                'currency' => $currency,
            ],
        ];
    }

    $newAvailable = user_proxy_round_money($available - $amount);
    $newHold = user_proxy_round_money($hold + $amount);

    $ok = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to hold balance',
            'data' => [],
        ];
    }

    $ledgerId = user_proxy_make_id('WL');
    $month = user_proxy_month_key($now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => $type,
        'direction' => 'HOLD',
        'amount' => $amount,
        'currency' => $currency,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => $uid,
        'created_by_role' => 'USER',
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Balance held successfully',
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'currency' => $currency,
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
        $amountLine = $currency === 'MYR'
            ? 'Pay / Hold: RM ' . user_proxy_round_money($row['total_pay_myr'] ?? 0) . "\nAmount BDT: BDT " . user_proxy_round_money($row['amount_bdt'] ?? 0) . "\nFee: RM " . user_proxy_round_money($row['fee_myr'] ?? 0)
            : 'Pay / Hold: BDT ' . user_proxy_round_money($row['total_pay_bdt'] ?? 0) . "\nAmount RM: RM " . user_proxy_round_money($row['amount_myr'] ?? 0) . "\nFee: BDT " . user_proxy_round_money($row['fee_bdt'] ?? 0);
        $amountLine .= "\nRate: RM 1 = BDT " . user_proxy_round_money($row['rate_myr_to_bdt'] ?? 0);
    } else {
        $amountLine = 'Pay: BDT ' . user_proxy_round_money($row['total_pay_bdt'] ?? 0) . "\nAmount: BDT " . user_proxy_round_money($row['amount_bdt'] ?? 0) . "\nFee: BDT " . user_proxy_round_money($row['fee_bdt'] ?? 0);
    }

    $text =
        "🔔 New MFS Request\n\n" .
        "ID: {$requestId}\n" .
        "Provider: {$provider}\n" .
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

    $hold = user_proxy_hold_balance_currency(
        $uid,
        (float)$data['wallet_hold_amount'],
        $requestId,
        'USER_WEB_MFS_HOLD',
        'Balance held for MFS request',
        (string)$data['wallet_currency']
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

    $ok = fb_put('MFS_REQUESTS/PENDING/' . $requestId, $row);

    if (!$ok) {
        user_proxy_release_hold_rollback(
            $uid,
            (float)$data['wallet_hold_amount'],
            $requestId,
            'USER_WEB_MFS_HOLD_ROLLBACK',
            'MFS hold rollback after request create failure'
        );

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
    $res = user_proxy_internal_api_request('POST', $relativePath, $body, [
        'X-APP-KEY' => APP_KEY,
    ]);

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

/* =========================================================
   Router
========================================================= */

$action = trim((string)($_GET['action'] ?? ''));

switch ($action) {
    case 'login':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $phone = trim((string)($body['phone'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $trustDevice = user_proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
        $deviceName = trim((string)($body['device_name'] ?? 'User Dashboard'));
        $trustedDeviceCookie = user_proxy_get_trust_cookie();

        if ($phone === '' || $password === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Phone and password are required', [], 422);
        }

        $loginRes = user_proxy_internal_api_request('POST', 'auth/user_login_start.php', [
            'phone' => $phone,
            'password' => $password,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'trust_device' => $trustDevice,
            'trusted_device_cookie' => $trustedDeviceCookie,
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

        if ($token !== '') {
            user_proxy_internal_api_request('POST', 'auth/logout.php', [], [
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

        user_proxy_response(true, 'SUCCESS', 'Dashboard bootstrap loaded', [
            'user' => $sessionUser,
            'csrf' => user_proxy_get_csrf(),
            'wallet_summary' => user_proxy_wallet_summary_payload($uid, $sessionUser),
            'request_logs' => [
                'uid' => $uid,
                'items' => user_proxy_collect_request_logs($uid, $limit, false),
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

    case 'request_logs':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 100);
        $legacy = user_proxy_bool_value($_GET['legacy'] ?? false);

        if ($limit <= 0) {
            $limit = 100;
        }

        if ($limit > 300) {
            $limit = 300;
        }

        user_proxy_response(true, 'SUCCESS', 'Request logs loaded', [
            'uid' => $uid,
            'items' => user_proxy_collect_request_logs($uid, $limit, $legacy),
            'mode' => $legacy ? 'fast_with_legacy_fallback' : 'fast',
        ]);
        break;
        
        
    case 'mfs_preview':
    case 'bkash_preview':
    case 'nagad_preview':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();

        if ($action === 'bkash_preview') {
            $body['provider'] = 'BKASH';
        } elseif ($action === 'nagad_preview') {
            $body['provider'] = 'NAGAD';
        }

        $res = user_proxy_mfs_preview_payload($uid, $body);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;

            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to preview MFS request'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'MFS preview ready'),
            (array)($res['data'] ?? []),
            200
        );
        break;

    case 'mfs_create':
    case 'mfs_create_panel':
    case 'bkash_create':
    case 'nagad_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();

        if ($action === 'bkash_create') {
            $body['provider'] = 'BKASH';
        } elseif ($action === 'nagad_create') {
            $body['provider'] = 'NAGAD';
        }

        $res = user_proxy_create_mfs_request($uid, $body);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;

            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to create MFS request'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'MFS request created successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;
        
        

    case 'bundle_offers_panel':
    case 'bundle_offers':
        user_proxy_require_method('GET');

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));

        $res = user_proxy_bundle_offers_for_user($uid);

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
        
        
        case 'mfs_create':
        user_proxy_require_method('POST');
        user_proxy_require_csrf();

        $sessionUser = user_proxy_require_login(true, false);
        $uid = trim((string)($sessionUser['uid'] ?? ''));
        $body = user_proxy_read_json_body();

        if (!function_exists('mfs_create_request')) {
            user_proxy_response(false, 'SERVER_ERROR', 'MFS helper not loaded', [], 500);
        }

        $res = mfs_create_request($uid, $body, 'USER_PANEL', 'PANEL', [
            'uid' => $uid,
            'role' => (string)($sessionUser['role'] ?? 'USER'),
        ]);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;

            if (in_array($code, [
                'VALIDATION_ERROR',
                'INSUFFICIENT_BALANCE',
                'MFS_DISABLED',
                'PROVIDER_DISABLED',
                'SERVICE_NOT_ALLOWED',
                'COUNTRY_MISSING',
                'WALLET_CURRENCY_MISSING',
                'COUNTRY_CURRENCY_MISMATCH',
                'UNSUPPORTED_COUNTRY_CURRENCY',
            ], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'INVALID_PIN'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            user_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to create MFS request'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        user_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'MFS request created successfully'),
            (array)($res['data'] ?? []),
            200
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

    case 'register':
    case 'register_send_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        user_proxy_forward_auth_post('auth/user_register_send_otp.php', [
            'name' => trim((string)($body['name'] ?? '')),
            'phone' => trim((string)($body['phone'] ?? '')),
            'email' => trim((string)($body['email'] ?? '')),
            'password' => (string)($body['password'] ?? ''),
            'confirm_password' => (string)($body['confirm_password'] ?? ''),
            'pin' => trim((string)($body['pin'] ?? '')),
            'confirm_pin' => trim((string)($body['confirm_pin'] ?? '')),
            'device_id' => trim((string)($body['device_id'] ?? 'USER_WEB')),
            'device_name' => trim((string)($body['device_name'] ?? 'User Register')),
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

    case 'forgot':
    case 'forgot_password':
    case 'forgot_send_otp':
        user_proxy_require_method('POST');

        $body = user_proxy_read_json_body();

        $phone = trim((string)($body['phone'] ?? ''));
        $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));

        if ($phone === '') {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Phone is required', [], 422);
        }

        if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
            user_proxy_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
        }

        user_proxy_forward_auth_post('auth/user_forgot_send_otp.php', [
            'phone' => $phone,
            'reset_type' => $resetType,
            'device_id' => trim((string)($body['device_id'] ?? 'USER_WEB')),
            'device_name' => trim((string)($body['device_name'] ?? 'User Forgot')),
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

        if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
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
        ];

        if ($resetType === 'PIN') {
            $forwardBody['new_pin'] = trim((string)($body['new_pin'] ?? ''));
            $forwardBody['confirm_pin'] = trim((string)($body['confirm_pin'] ?? ''));
        } else {
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

    default:
        user_proxy_response(false, 'NOT_FOUND', 'Unknown proxy action', [], 404);
}
