<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('POST');

function zb_github_api_post(string $url, string $token, array $payload): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'body' => 'cURL not available'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: Z-Pay-Swift-Z-Builder',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'body' => $body, 'error' => $err];
}

$ctx = zb_require_owner_session();
$ownerId = (string)($ctx['owner']['owner_id'] ?? '');
$body = api_read_json_body();
$appId = trim((string)($body['app_id'] ?? ''));
$appToken = trim((string)($body['app_token'] ?? ''));
if ($appId === '') { api_response(false, 'APP_ID_REQUIRED', 'app_id is required', [], 422); }
if ($appToken === '') { api_response(false, 'APP_TOKEN_REQUIRED', 'app token is required for build dispatch', [], 422); }

$appPath = 'Z_BUILDER_WORKER_APPS/' . $appId;
$app = fb_get($appPath);
if (!is_array($app) || (string)($app['owner_id'] ?? '') !== $ownerId) {
    api_response(false, 'APP_NOT_FOUND', 'Worker app not found', [], 404);
}
if (!hash_equals((string)($app['app_token_hash'] ?? ''), hash('sha256', $appToken))) {
    api_response(false, 'INVALID_APP_TOKEN', 'Invalid app token', [], 401);
}

$githubToken = defined('ZBUILDER_GITHUB_TOKEN') ? (string)constant('ZBUILDER_GITHUB_TOKEN') : '';
$githubRepo = defined('ZBUILDER_GITHUB_REPO') ? (string)constant('ZBUILDER_GITHUB_REPO') : 'zakircodex/zpayswift';
$apiBase = defined('ZBUILDER_API_BASE') ? (string)constant('ZBUILDER_API_BASE') : 'https://zpayswift.com';
if ($githubToken === '') {
    api_response(false, 'GITHUB_TOKEN_NOT_CONFIGURED', 'GitHub build token is not configured on server', [
        'app' => zb_worker_app_public($app),
        'manual_workflow' => '.github/workflows/z-builder-worker-build.yml',
    ], 503);
}

$url = 'https://api.github.com/repos/' . $githubRepo . '/actions/workflows/z-builder-worker-build.yml/dispatches';
$payload = [
    'ref' => 'main',
    'inputs' => [
        'app_name' => (string)($app['app_name'] ?? 'Z Builder Worker'),
        'package_name' => (string)($app['package_name'] ?? 'com.zbuilder.worker'),
        'app_id' => $appId,
        'app_token' => $appToken,
        'api_base' => $apiBase,
    ],
];
$res = zb_github_api_post($url, $githubToken, $payload);
if (!$res['ok']) {
    api_response(false, 'BUILD_DISPATCH_FAILED', 'Failed to start GitHub build workflow', [
        'http' => $res['http'],
        'error' => $res['error'] ?? '',
        'body' => $res['body'] ?? '',
    ], 500);
}

$patch = [
    'status' => 'BUILD_QUEUED',
    'build_status' => 'BUILD_QUEUED',
    'updated_at' => zb_now_iso(),
];
fb_patch($appPath, $patch);
fb_patch('Z_BUILDER_OWNER_WORKER_APPS/' . $ownerId . '/' . $appId, $patch);

api_response(true, 'BUILD_QUEUED', 'Worker APK build started', ['app_id' => $appId]);
