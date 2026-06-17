<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('GET');

$ctx = zb_require_owner_session();
$ownerId = (string)($ctx['owner']['owner_id'] ?? '');
$plan = fb_get('Z_BUILDER_OWNER_PLANS/' . $ownerId);
$workers = fb_get('Z_BUILDER_OWNER_WORKERS/' . $ownerId);
if (!is_array($workers)) { $workers = []; }

$list = [];
foreach ($workers as $workerId => $row) {
    if (!is_array($row)) { continue; }
    unset($row['connect_code_hash']);
    $row['worker_id'] = (string)($row['worker_id'] ?? $workerId);
    $list[] = $row;
}
usort($list, function ($a, $b) { return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });

api_response(true, 'SUCCESS', 'Worker status loaded', [
    'plan_status' => is_array($plan) ? (string)($plan['status'] ?? 'NO_PLAN') : 'NO_PLAN',
    'worker_allowed' => is_array($plan) && (string)($plan['status'] ?? '') === 'PAID_ACTIVE',
    'workers' => $list,
    'download_url' => '/download-apk',
]);
