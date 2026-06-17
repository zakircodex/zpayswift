<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/worker.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$ctx = zb_require_worker_app($body);
$app = $ctx['app'];
$appId = $ctx['app_id'];
$ownerId = (string)($app['owner_id'] ?? '');

$deviceId = trim((string)($body['device_id'] ?? ''));
$deviceName = array_key_exists('device_name', $body) ? trim((string)$body['device_name']) : null;
$workerEnabled = array_key_exists('worker_enabled', $body) ? (bool)$body['worker_enabled'] : true;
$accessibilityEnabled = array_key_exists('accessibility_enabled', $body) ? (bool)$body['accessibility_enabled'] : null;
$appVersion = array_key_exists('app_version', $body) ? trim((string)$body['app_version']) : null;
$simSlots = (array_key_exists('sim_slots', $body) && is_array($body['sim_slots'])) ? $body['sim_slots'] : null;

if ($deviceId === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id is required', [], 422);
}

$ok = worker_update_heartbeat($deviceId, $deviceName, $workerEnabled, $accessibilityEnabled, $appVersion, $simSlots);
if (!$ok) {
    api_response(false, 'SERVER_ERROR', 'Failed to update worker heartbeat', [], 500);
}

$patch = [
    'status' => 'CONNECTED',
    'build_status' => (string)($app['build_status'] ?? 'READY_TO_BUILD'),
    'device_id' => $deviceId,
    'device_name' => $deviceName ?: 'Worker Device',
    'last_seen_at' => zb_now_iso(),
    'updated_at' => zb_now_iso(),
];
fb_patch('Z_BUILDER_WORKER_APPS/' . $appId, $patch);
fb_patch('Z_BUILDER_OWNER_WORKER_APPS/' . $ownerId . '/' . $appId, $patch);
fb_patch('WORKER_DEVICES/' . $deviceId, [
    'z_builder_worker_app_id' => $appId,
    'z_builder_owner_id' => $ownerId,
    'worker_scope' => 'Z_BUILDER',
]);

api_response(true, 'WORKER_OK', 'Worker app heartbeat updated', []);
