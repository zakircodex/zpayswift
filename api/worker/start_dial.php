<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/worker.php';

api_require_method('POST');
api_require_worker_key();

$body = api_read_json_body();

$deviceId = trim((string)($body['device_id'] ?? ''));
$requestId = trim((string)($body['request_id'] ?? ''));
$assignedSlot = trim((string)($body['assigned_slot'] ?? ''));
$dialPreview = trim((string)($body['dial_preview'] ?? ''));

if ($deviceId === '' || $requestId === '' || $assignedSlot === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id, request_id and assigned_slot are required', [], 422);
}

$ok = worker_mark_processing($requestId, $deviceId, $assignedSlot, $dialPreview);

if (!$ok) {
    api_response(false, 'SERVER_ERROR', 'Failed to mark request as processing', [], 500);
}

api_response(true, 'PROCESSING_STARTED', 'Request marked as processing', [
    'request_id' => $requestId,
]);