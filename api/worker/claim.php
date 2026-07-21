<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/operator_private.php';
require_once dirname(__DIR__) . '/lib/worker.php';
require_once dirname(__DIR__) . '/lib/topup.php';

api_require_method('POST');
api_require_worker_key();

$body = api_read_json_body();
$deviceId = trim((string)($body['device_id'] ?? ''));

if ($deviceId === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id is required', [], 422);
}

$device = worker_get_device($deviceId);
if (!$device) {
    api_response(false, 'INVALID_WORKER', 'Worker device not found', [], 404);
}

if (!worker_is_available($device)) {
    api_response(false, 'WORKER_OFFLINE', 'Worker is not ready', [], 422);
}

$claimed = worker_claim_request($deviceId);
if (!$claimed) {
    api_response(false, 'NO_PENDING_REQUEST', 'No pending request found', []);
}

$slot = (string)$claimed['assigned_slot'];
$dialTemplate = (string)$claimed['dial_template'];
$number = (string)$claimed['topup_number'];
$amount = (float)$claimed['amount'];
$retailerPin = (string)$claimed['retailer_secret_pin'];

$preview = str_replace(
    ['{NUMBER}', '{AMOUNT}', '{PIN}'],
    [$number, (string)$amount, '*****'],
    $dialTemplate
);

if (!worker_mark_processing(
    (string)$claimed['request_id'],
    $deviceId,
    $slot,
    $preview
)) {
    api_response(false, 'CLAIM_TRANSITION_FAILED', 'Request was claimed but could not enter processing. Please retry.', [], 409);
}

api_response(true, 'REQUEST_CLAIMED', 'Request claimed', [
    'request_id' => (string)$claimed['request_id'],
    'topup_number' => $number,
    'operator' => (string)$claimed['operator'],
    'amount' => $amount,
    'assigned_slot' => $slot,
    'dial_template' => $dialTemplate,
    'retailer_secret_pin' => $retailerPin,
    'dial_preview_masked' => $preview,
]);
