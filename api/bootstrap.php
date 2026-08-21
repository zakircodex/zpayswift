<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: application/json; charset=utf-8');

// API failures must never render PHP warnings, stack traces, or private paths.
ini_set('display_errors', '0');
ini_set('html_errors', '0');

require_once __DIR__ . '/lib/app_paths.php';
$privateConfigPath = app_private_config_path();
if (!is_file($privateConfigPath) || !is_readable($privateConfigPath)) {
    error_log('Z-Pay Swift private configuration is unavailable.');
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'success' => false,
        'code' => 'SERVICE_UNAVAILABLE',
        'message' => 'Service is temporarily unavailable.',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
require_once $privateConfigPath;
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/firebase.php';
require_once __DIR__ . '/lib/system_maintenance.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/phone_country.php';
require_once __DIR__ . '/lib/market_detection.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/subadmin_api.php';

security_enforce_request([
    'area' => 'api',
    'file' => 'bootstrap.php',
]);

function api_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);

    echo json_encode([
        'ok' => $ok,
        'success' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

function api_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function api_get_header(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return trim((string)$value);
            }
        }
    }

    return null;
}

function api_require_app_key(): void
{
    $incoming = api_get_header('X-APP-KEY');

    if (!$incoming || !hash_equals(APP_KEY, $incoming)) {
        api_response(false, 'UNAUTHORIZED', 'Invalid app key', [], 401);
    }
}

function api_require_worker_key(): void
{
    $incoming = api_get_header('X-WORKER-KEY');

    if (!$incoming || !hash_equals(WORKER_KEY, $incoming)) {
        api_response(false, 'INVALID_WORKER', 'Invalid worker key', [], 401);
    }
}

function api_require_admin_key(): void
{
    $incoming = api_get_header('X-ADMIN-KEY');

    if (!$incoming || !hash_equals(ADMIN_KEY, $incoming)) {
        api_response(false, 'UNAUTHORIZED', 'Invalid admin key', [], 401);
    }
}

function api_read_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        api_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}
