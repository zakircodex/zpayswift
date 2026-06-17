<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('GET');

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
