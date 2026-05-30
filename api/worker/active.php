<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/worker.php';

api_require_method('POST');
api_require_worker_key();

$body = api_read_json_body();
$deviceId = trim((string)($body['device_id'] ?? ''));

if ($deviceId === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id is required', [], 422);
}

$active = worker_get_assigned_active_request($deviceId);

if (!$active) {
    worker_mark_status($deviceId, 'IDLE');
    api_response(false, 'NO_ACTIVE_REQUEST', 'No active assigned request', [], 200);
}

worker_mark_status($deviceId, 'BUSY');

api_response(true, 'SUCCESS', 'Active assigned request found', $active);