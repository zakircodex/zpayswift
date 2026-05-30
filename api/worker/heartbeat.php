<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/worker.php';

api_require_method('POST');
api_require_worker_key();

$body = api_read_json_body();

$deviceId = trim((string)($body['device_id'] ?? ''));
$deviceName = array_key_exists('device_name', $body) ? trim((string)$body['device_name']) : null;
$workerEnabled = array_key_exists('worker_enabled', $body) ? (bool)$body['worker_enabled'] : null;
$accessibilityEnabled = array_key_exists('accessibility_enabled', $body) ? (bool)$body['accessibility_enabled'] : null;
$appVersion = array_key_exists('app_version', $body) ? trim((string)$body['app_version']) : null;
$simSlots = (array_key_exists('sim_slots', $body) && is_array($body['sim_slots'])) ? $body['sim_slots'] : null;

if ($deviceId === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id is required', [], 422);
}

$ok = worker_update_heartbeat(
    $deviceId,
    $deviceName,
    $workerEnabled,
    $accessibilityEnabled,
    $appVersion,
    $simSlots
);

if (!$ok) {
    api_response(false, 'SERVER_ERROR', 'Failed to update heartbeat', [], 500);
}

api_response(true, 'WORKER_OK', 'Heartbeat updated', []);