<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

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
$deviceName = trim((string)($body['device_name'] ?? ''));
if ($appName === '') { $appName = 'Z Builder Worker'; }
if ($deviceName === '') { $deviceName = 'Worker Phone'; }

$now = time();
$workerId = 'ZBWRK_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$connectCode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$codeHash = hash('sha256', $connectCode);
$row = [
    'worker_id' => $workerId,
    'owner_id' => $ownerId,
    'owner_name' => (string)($owner['name'] ?? ''),
    'app_name' => $appName,
    'device_name' => $deviceName,
    'connect_code_hash' => $codeHash,
    'status' => 'WAITING_CONNECT',
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (30 * 60)),
    'connected_at' => null,
    'download_url' => '/download-apk',
    'package_name' => 'com.zworker.app',
];

fb_put('Z_BUILDER_WORKERS/' . $workerId, $row);
fb_put('Z_BUILDER_OWNER_WORKERS/' . $ownerId . '/' . $workerId, $row);
fb_put('Z_BUILDER_WORKER_CONNECT_CODES/' . $codeHash, [
    'worker_id' => $workerId,
    'owner_id' => $ownerId,
    'status' => 'ACTIVE',
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (30 * 60)),
]);

api_response(true, 'WORKER_READY', 'Worker connection code generated', [
    'worker' => $row,
    'connect_code' => $connectCode,
    'download_url' => '/download-apk',
    'expires_in' => 1800,
]);
