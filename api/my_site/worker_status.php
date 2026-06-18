<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('GET');

function zb_worker_build_progress(array $row): int
{
    $status = strtoupper((string)($row['build_status'] ?? $row['status'] ?? ''));
    if (in_array($status, ['ARTIFACT_READY', 'APK_READY'], true)) { return 100; }
    if ($status === 'BUILD_FAILED') { return 0; }
    if ($status === 'READY_TO_BUILD') { return 5; }
    if ($status === 'BUILD_QUEUED') {
        $started = strtotime((string)($row['build_started_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? ''));
        $elapsed = $started ? max(0, time() - $started) : 0;
        return min(95, 15 + (int)floor($elapsed / 12));
    }
    return 0;
}

function zb_worker_build_label(array $row, int $progress): string
{
    $status = strtoupper((string)($row['build_status'] ?? $row['status'] ?? ''));
    if (in_array($status, ['ARTIFACT_READY', 'APK_READY'], true)) { return 'APK_READY 100%'; }
    if ($status === 'BUILD_FAILED') { return 'BUILD_FAILED'; }
    if ($status === 'READY_TO_BUILD') { return 'READY_TO_BUILD ' . $progress . '%'; }
    if ($status === 'BUILD_QUEUED') { return 'BUILDING ' . $progress . '%'; }
    return $status !== '' ? $status : 'NOT_STARTED';
}

$ctx = zb_require_owner_session();
$ownerId = (string)($ctx['owner']['owner_id'] ?? '');
$plan = fb_get('Z_BUILDER_OWNER_PLANS/' . $ownerId);
$apps = fb_get('Z_BUILDER_OWNER_WORKER_APPS/' . $ownerId);
$legacyWorkers = fb_get('Z_BUILDER_OWNER_WORKERS/' . $ownerId);
if (!is_array($apps)) { $apps = []; }
if (!is_array($legacyWorkers)) { $legacyWorkers = []; }

$appList = [];
foreach ($apps as $appId => $row) {
    if (!is_array($row)) { continue; }
    unset($row['app_token_hash']);
    $row['app_id'] = (string)($row['app_id'] ?? $appId);
    $progress = zb_worker_build_progress($row);
    $row['build_progress'] = $progress;
    $row['build_status_raw'] = (string)($row['build_status'] ?? $row['status'] ?? '');
    $row['build_status'] = zb_worker_build_label($row, $progress);
    $appList[] = $row;
}
usort($appList, function ($a, $b) { return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });

$workerList = [];
foreach ($legacyWorkers as $workerId => $row) {
    if (!is_array($row)) { continue; }
    unset($row['connect_code_hash']);
    $row['worker_id'] = (string)($row['worker_id'] ?? $workerId);
    $workerList[] = $row;
}

api_response(true, 'SUCCESS', 'Worker status loaded', [
    'plan_status' => is_array($plan) ? (string)($plan['status'] ?? 'NO_PLAN') : 'NO_PLAN',
    'worker_allowed' => is_array($plan) && (string)($plan['status'] ?? '') === 'PAID_ACTIVE',
    'apps' => $appList,
    'workers' => $workerList,
]);
