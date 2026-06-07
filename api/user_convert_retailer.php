<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/wallet.php';
require_once __DIR__ . '/lib/users_admin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function admin_convert_api_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_convert_api_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        admin_convert_api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function admin_convert_api_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        admin_convert_api_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function admin_convert_api_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function admin_convert_api_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function admin_convert_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/user_convert_retailer.php';
    $apiPath = dirname($script);
    return rtrim(admin_convert_api_scheme() . '://' . admin_convert_api_host() . $apiPath, '/');
}

function admin_convert_api_internal_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = admin_convert_api_base_url() . '/' . ltrim($relativePath, '/');

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
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $finalHeaders,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'status' => $status,
            'json' => null,
            'error' => 'Invalid JSON response from internal API',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
        'error' => null,
    ];
}

function admin_convert_api_extract_session_token(): string
{
    $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

function admin_convert_api_require_admin_actor(): array
{
    $sessionToken = admin_convert_api_extract_session_token();
    if ($sessionToken === '') {
        admin_convert_api_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $res = admin_convert_api_internal_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        admin_convert_api_response(
            false,
            (string)($json['code'] ?? 'SESSION_EXPIRED'),
            (string)($json['message'] ?? 'Session expired'),
            [],
            $res['status'] > 0 ? $res['status'] : 401
        );
    }

    $actor = (array)($res['json']['data'] ?? []);
    $role = strtoupper(trim((string)($actor['role'] ?? '')));

    if (!in_array($role, ['ADMIN', 'SUBADMIN'], true)) {
        admin_convert_api_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can access this endpoint', [], 403);
    }

    $status = strtoupper(trim((string)($actor['status'] ?? 'INACTIVE')));
    if ($status !== 'ACTIVE') {
        admin_convert_api_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return $actor;
}

admin_convert_api_require_method('POST');
$actor = admin_convert_api_require_admin_actor();
$body = admin_convert_api_read_json_body();

$uid = trim((string)($body['uid'] ?? ''));

if ($uid === '') {
    admin_convert_api_response(false, 'VALIDATION_ERROR', 'User ID is required', [], 422);
}

$result = admin_users_convert_to_retailer(
    $uid,
    (string)($actor['uid'] ?? ''),
    (string)($actor['role'] ?? '')
);

if (!($result['ok'] ?? false)) {
    $code = (string)($result['code'] ?? 'SERVER_ERROR');
    $message = (string)($result['message'] ?? 'Failed to convert user');

    $httpStatus = 400;
    if ($code === 'NOT_FOUND') {
        $httpStatus = 404;
    } elseif ($code === 'SERVER_ERROR') {
        $httpStatus = 500;
    } elseif ($code === 'FORBIDDEN' || $code === 'NOT_ALLOWED') {
        $httpStatus = 403;
    }

    admin_convert_api_response(false, $code, $message, [], $httpStatus);
}

admin_convert_api_response(true, 'SUCCESS', 'User converted to retailer successfully', [
    'item' => (array)($result['data'] ?? []),
    'actor' => [
        'uid' => (string)($actor['uid'] ?? ''),
        'name' => (string)($actor['name'] ?? ''),
        'role' => (string)($actor['role'] ?? ''),
    ],
]);
