<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('POST');
api_require_admin_key();

$body = api_read_json_body();
$appId = trim((string)($body['app_id'] ?? ''));
$status = strtoupper(trim((string)($body['build_status'] ?? '')));
$artifactName = trim((string)($body['artifact_name'] ?? ''));
$runId = trim((string)($body['run_id'] ?? ''));
$runUrl = trim((string)($body['run_url'] ?? ''));

if ($appId === '') { api_response(false, 'APP_ID_REQUIRED', 'app_id is required', [], 422); }
if (!in_array($status, ['ARTIFACT_READY', 'BUILD_FAILED', 'BUILD_QUEUED', 'BUILDING'], true)) {
    api_response(false, 'INVALID_STATUS', 'Invalid build status', [], 422);
}

$appPath = 'Z_BUILDER_WORKER_APPS/' . $appId;
$app = fb_get($appPath);
if (!is_array($app)) { api_response(false, 'APP_NOT_FOUND', 'Worker app not found', [], 404); }
$ownerId = (string)($app['owner_id'] ?? '');

$patch = [
    'build_status' => $status,
    'updated_at' => zb_now_iso(),
];
if ($artifactName !== '') { $patch['artifact_name'] = $artifactName; }
if ($runId !== '') { $patch['github_run_id'] = $runId; }
if ($runUrl !== '') { $patch['github_run_url'] = $runUrl; }
if ($status === 'ARTIFACT_READY') {
    $patch['status'] = 'APK_READY';
    $patch['download_url'] = $runUrl;
    $patch['download_note'] = 'Open GitHub Actions run and download APK artifact.';
} elseif ($status === 'BUILD_FAILED') {
    $patch['status'] = 'BUILD_FAILED';
}

fb_patch($appPath, $patch);
if ($ownerId !== '') { fb_patch('Z_BUILDER_OWNER_WORKER_APPS/' . $ownerId . '/' . $appId, $patch); }

api_response(true, 'SUCCESS', 'Worker app build status updated', ['app_id' => $appId, 'build_status' => $status]);
