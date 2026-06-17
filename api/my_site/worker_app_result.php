<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/wallet.php';
require_once __DIR__ . '/../lib/worker.php';
require_once __DIR__ . '/../lib/topup.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$ctx = zb_require_worker_app($body);
$app = $ctx['app'];
$ownerId = (string)($app['owner_id'] ?? '');

$deviceId = trim((string)($body['device_id'] ?? ''));
$requestId = trim((string)($body['request_id'] ?? ''));
$resultStatus = strtoupper(trim((string)($body['result_status'] ?? '')));
$resultMessage = trim((string)($body['result_message'] ?? ''));
$rawResponse = trim((string)($body['raw_response'] ?? ''));

if ($deviceId === '' || $requestId === '' || $resultStatus === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id, request_id and result_status are required', [], 422);
}

$device = worker_get_device($deviceId);
if (!is_array($device) || (string)($device['z_builder_owner_id'] ?? '') !== $ownerId) {
    api_response(false, 'INVALID_WORKER_DEVICE', 'Worker device is not linked to this app', [], 403);
}

$processing = fb_get('TOPUP_REQUESTS/PROCESSING/' . $requestId);
if (!is_array($processing) || (string)($processing['z_builder_owner_id'] ?? $processing['tenant_owner_id'] ?? '') !== $ownerId) {
    api_response(false, 'INVALID_REQUEST_SCOPE', 'Request is not assigned to this Z Builder owner', [], 403);
}

if ($resultMessage === '') {
    $resultMessage = $resultStatus === 'SUCCESS' ? 'Recharge successful' : 'Recharge failed';
}

if ($resultStatus === 'SUCCESS') {
    $res = worker_finalize_success($requestId, $deviceId, $resultMessage, $rawResponse);
} else {
    $res = worker_finalize_failed($requestId, $deviceId, $resultMessage, $rawResponse);
}

if (!($res['ok'] ?? false)) {
    api_response(false, (string)($res['code'] ?? 'SERVER_ERROR'), (string)($res['message'] ?? 'Worker result failed'), [], 500);
}

api_response(true, 'SUCCESS', 'Worker result saved', [
    'request_id' => $requestId,
    'final_status' => $resultStatus,
]);
