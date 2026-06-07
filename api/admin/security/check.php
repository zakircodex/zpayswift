<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/security.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

api_require_method('GET');

function admin_security_cookie_path(): string
{
    if (function_exists('app_cookie_path')) {
        return app_cookie_path('admin');
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/admin/security/check.php';
    $path = rtrim(dirname(dirname($script)), '/');

    return $path !== '' && $path !== '.' ? $path : '/';
}

/*
|--------------------------------------------------------------------------
| Admin Security Check
|--------------------------------------------------------------------------
| Direct browser open করলে normal X-SESSION-TOKEN header থাকে না।
| তাই admin PHP session থেকে token নিয়ে auth_require_admin_session() কে দিচ্ছি।
|--------------------------------------------------------------------------
*/

function admin_security_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    session_name('zawtopup_admin_v3');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => admin_security_cookie_path(),
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function admin_security_find_session_token(): string
{
    $headerToken = trim((string)(
        $_SERVER['HTTP_X_SESSION_TOKEN']
        ?? $_SERVER['HTTP_X_ADMIN_SESSION_TOKEN']
        ?? ''
    ));

    if ($headerToken !== '') {
        return $headerToken;
    }

    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if (stripos($auth, 'Bearer ') === 0) {
        $bearer = trim(substr($auth, 7));
        if ($bearer !== '') {
            return $bearer;
        }
    }

    $keys = [
        'admin_session_token',
        'admin_token',
        'session_token',
        'user_session_token',
    ];

    foreach ($keys as $key) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            return trim((string)$_SESSION[$key]);
        }
    }

    return '';
}

admin_security_start_session();

$token = admin_security_find_session_token();

if ($token === '') {
    api_response(false, 'SESSION_EXPIRED', 'Missing admin session token', [
        'session_name' => session_name(),
        'session_keys' => array_keys($_SESSION),
        'note' => 'If admin/proxy.php uses a different session name or token key, match it here.',
    ], 401);
}

/*
|--------------------------------------------------------------------------
| auth_require_admin_session() সাধারণত header থেকে token পড়ে।
| তাই direct browser session token header হিসেবে inject করা হলো।
|--------------------------------------------------------------------------
*/
$_SERVER['HTTP_X_SESSION_TOKEN'] = $token;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

if (!function_exists('auth_require_admin_session')) {
    api_response(false, 'SERVER_ERROR', 'Admin auth helper missing', [], 500);
}

$admin = auth_require_admin_session(true);

$ip = security_client_ip();
$risk = security_detect_ip_risk($ip);

$config = [
    'SECURITY_ENABLED' => security_enabled(),
    'SECURITY_EXTERNAL_IP_LOOKUP_ENABLED' => security_bool_constant('SECURITY_EXTERNAL_IP_LOOKUP_ENABLED', false),
    'SECURITY_IP_RISK_ENDPOINT_SET' => security_string_constant('SECURITY_IP_RISK_ENDPOINT') !== '',
    'SECURITY_IP_RISK_API_KEY_SET' => security_string_constant('SECURITY_IP_RISK_API_KEY') !== '',
    'SECURITY_ALLOW_UNKNOWN_IP_RISK' => security_bool_constant('SECURITY_ALLOW_UNKNOWN_IP_RISK', true),
    'SECURITY_BLOCK_VPN' => security_bool_constant('SECURITY_BLOCK_VPN', true),
    'SECURITY_BLOCK_PROXY' => security_bool_constant('SECURITY_BLOCK_PROXY', true),
    'SECURITY_BLOCK_TOR' => security_bool_constant('SECURITY_BLOCK_TOR', true),
    'SECURITY_BLOCK_DATACENTER' => security_bool_constant('SECURITY_BLOCK_DATACENTER', true),
    'SECURITY_BLOCK_ANONYMOUS' => security_bool_constant('SECURITY_BLOCK_ANONYMOUS', true),
    'SECURITY_BLOCK_HIGH_RISK_SCORE' => security_bool_constant('SECURITY_BLOCK_HIGH_RISK_SCORE', true),
    'SECURITY_HIGH_RISK_SCORE_BLOCK_AT' => security_int_constant('SECURITY_HIGH_RISK_SCORE_BLOCK_AT', 85),
    'SECURITY_IP_CACHE_TTL_SECONDS' => security_cache_ttl_seconds(),
];

api_response(true, 'SUCCESS', 'Security check loaded', [
    'admin' => [
        'uid' => (string)($admin['uid'] ?? ''),
        'name' => (string)($admin['name'] ?? ''),
        'role' => (string)($admin['role'] ?? ''),
        'status' => (string)($admin['status'] ?? ''),
    ],
    'client_ip' => $ip,
    'ip_family' => $ip !== '' ? security_ip_family($ip) : 'UNKNOWN',
    'is_public_ip' => $ip !== '' ? security_is_public_ip($ip) : false,
    'ip_hash' => $ip !== '' ? security_ip_hash($ip) : '',
    'support_code' => $ip !== '' ? substr(security_ip_hash($ip), 0, 12) : '',
    'risk' => $risk,
    'config' => $config,
    'request' => [
        'method' => security_request_method(),
        'path' => security_request_path(),
        'user_agent' => security_user_agent(),
        'cf_connecting_ip' => (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        'x_forwarded_for' => (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        'x_real_ip' => (string)($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ],
]);
