<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/worker.php';
require_once dirname(__DIR__) . '/lib/topup.php';

api_require_method('POST');
api_require_worker_key();

$body = api_read_json_body();

$deviceId = trim((string)($body['device_id'] ?? ''));
$requestId = trim((string)($body['request_id'] ?? ''));
$resultStatus = strtoupper(trim((string)($body['result_status'] ?? '')));
$resultMessage = trim((string)($body['result_message'] ?? ''));
$rawResponse = trim((string)($body['raw_response'] ?? ''));

if ($deviceId === '' || $requestId === '' || $resultStatus === '') {
    api_response(false, 'VALIDATION_ERROR', 'device_id, request_id and result_status are required', [], 422);
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