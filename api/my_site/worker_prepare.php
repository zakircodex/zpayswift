<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('POST');

$ctx = zb_require_owner_session();
$owner = $ctx['owner'];
$ownerId = (string)($owner['owner_id'] ?? '');
$plan = fb_get('Z_BUILDER_OWNER_PLANS/' . $ownerId);
$planStatus = is_array($plan) ? (string)($plan['status'] ?? '') : 'NO_PLAN';
if ($planStatus !== 'PAID_ACTIVE') {
    api_response(false, 'WORKER_LOCKED', 'Worker app is available only for active paid plan', ['plan_status' => $planStatus], 403);
}

$body = api_read_json_body();
$appName = trim((string)($body['app_name'] ?? ''));
$packageName = strtolower(trim((string)($body['package_name'] ?? '')));
if ($appName === '') { api_response(false, 'APP_NAME_REQUIRED', 'App name is required', [], 422); }
if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$/', $packageName)) {
    api_response(false, 'INVALID_PACKAGE_NAME', 'Package name must look like com.company.worker', [], 422);
}

$now = time();
$appId = 'ZBAPP_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$appToken = random_token(32);
$row = [
    'app_id' => $appId,
    'owner_id' => $ownerId,
    'owner_name' => (string)($owner['name'] ?? ''),
    'app_name' => $appName,
    'package_name' => $packageName,
    'app_token_hash' => hash('sha256', $appToken),
    'status' => 'BUILD_READY',
    'build_status' => 'READY_TO_BUILD',
    'created_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
    'download_url' => null,
    'template_path' => 'z-builder-worker',
];

fb_put('Z_BUILDER_WORKER_APPS/' . $appId, $row);
fb_put('Z_BUILDER_OWNER_WORKER_APPS/' . $ownerId . '/' . $appId, zb_worker_app_public($row));

api_response(true, 'WORKER_APP_READY', 'Worker app config generated. APK build is ready to start.', [
    'app' => zb_worker_app_public($row),
    'build' => [
        'template_path' => 'z-builder-worker',
        'app_name' => $appName,
        'package_name' => $packageName,
        'api_base' => 'https://zpayswift.com',
        'app_id' => $appId,
        'app_token' => $appToken,
    ],
]);
