<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($body['connect_code'] ?? $body['code'] ?? '')));
$deviceId = trim((string)($body['device_id'] ?? ''));
$deviceName = trim((string)($body['device_name'] ?? ''));
$appVersion = trim((string)($body['app_version'] ?? ''));
if ($code === '' || strlen($code) < 6) { api_response(false, 'CODE_REQUIRED', 'Connection code required', [], 422); }
if ($deviceId === '') { api_response(false, 'DEVICE_ID_REQUIRED', 'device_id is required', [], 422); }
if ($deviceName === '') { $deviceName = 'Worker Phone'; }

$hash = hash('sha256', $code);
$codePath = 'Z_BUILDER_WORKER_CONNECT_CODES/' . $hash;
$connect = fb_get($codePath);
if (!is_array($connect) || ($connect['status'] ?? '') !== 'ACTIVE') { api_response(false, 'INVALID_CODE', 'Invalid connection code', [], 400); }
if (strtotime((string)($connect['expires_at'] ?? '')) < time()) {
    fb_patch($codePath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'CODE_EXPIRED', 'Connection code expired', [], 400);
}

$workerId = (string)($connect['worker_id'] ?? '');
$ownerId = (string)($connect['owner_id'] ?? '');
$workerPath = 'Z_BUILDER_WORKERS/' . $workerId;
$worker = fb_get($workerPath);
if (!is_array($worker)) { api_response(false, 'WORKER_NOT_FOUND', 'Worker setup not found', [], 404); }

$now = time();
$patch = [
    'status' => 'CONNECTED',
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'app_version' => $appVersion,
    'connected_at' => zb_now_iso($now),
    'last_seen_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
];
fb_patch($workerPath, $patch);
fb_patch('Z_BUILDER_OWNER_WORKERS/' . $ownerId . '/' . $workerId, $patch);
fb_patch($codePath, ['status' => 'USED', 'used_at' => zb_now_iso($now), 'device_id' => $deviceId]);
fb_put('Z_BUILDER_WORKER_DEVICE_INDEX/' . $deviceId, ['worker_id' => $workerId, 'owner_id' => $ownerId, 'connected_at' => zb_now_iso($now)]);

api_response(true, 'CONNECTED', 'Worker connected successfully', [
    'worker_id' => $workerId,
    'owner_id' => $ownerId,
    'device_id' => $deviceId,
    'status' => 'CONNECTED',
]);
