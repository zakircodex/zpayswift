<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function zb_worker_app_token_from_request(): string
{
    $token = api_get_header('X-ZBUILDER-WORKER-TOKEN') ?: api_get_header('X-Z-BUILDER-WORKER-TOKEN');
    if (!$token) {
        $auth = api_get_header('Authorization') ?: '';
        if (stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
        }
    }
    return trim((string)$token);
}

function zb_worker_app_id_from_request(array $body): string
{
    $id = api_get_header('X-ZBUILDER-WORKER-APP-ID') ?: api_get_header('X-Z-BUILDER-WORKER-APP-ID');
    if (!$id) {
        $id = (string)($body['app_id'] ?? $body['worker_app_id'] ?? '');
    }
    return trim((string)$id);
}

function zb_require_worker_app(array $body): array
{
    $appId = zb_worker_app_id_from_request($body);
    $token = zb_worker_app_token_from_request();
    if ($appId === '' || $token === '') {
        api_response(false, 'WORKER_APP_AUTH_REQUIRED', 'Worker app id and token required', [], 401);
    }

    $appPath = 'Z_BUILDER_WORKER_APPS/' . $appId;
    $app = fb_get($appPath);
    if (!is_array($app)) {
        api_response(false, 'WORKER_APP_NOT_FOUND', 'Worker app not found', [], 404);
    }

    $hash = hash('sha256', $token);
    if (!hash_equals((string)($app['app_token_hash'] ?? ''), $hash)) {
        api_response(false, 'INVALID_WORKER_APP_TOKEN', 'Invalid worker app token', [], 401);
    }

    $status = strtoupper((string)($app['status'] ?? ''));
    if (in_array($status, ['REVOKED', 'BLOCKED', 'DELETED'], true)) {
        api_response(false, 'WORKER_APP_BLOCKED', 'Worker app is blocked', [], 403);
    }

    return ['app_id' => $appId, 'app' => $app, 'path' => $appPath];
}

function zb_worker_app_public(array $app): array
{
    unset($app['app_token_hash']);
    return $app;
}
