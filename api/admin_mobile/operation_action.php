<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/add_money.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

api_require_method('POST');
$auth = admin_mobile_require_session(true);
$body = api_read_json_body();
$type = strtoupper(trim((string)($body['type'] ?? '')));
$decision = strtoupper(trim((string)($body['decision'] ?? '')));
$requestId = trim((string)($body['request_id'] ?? ''));
$message = trim((string)($body['message'] ?? ''));
$deviceId = admin_mobile_device_id(true);

if (!in_array($type, ['TOPUP', 'BUNDLE', 'BKASH', 'NAGAD', 'ADD_MONEY'], true)
    || !in_array($decision, ['SUCCESS', 'FAILED', 'APPROVE', 'REJECT'], true)
    || $requestId === ''
) {
    api_response(false, 'VALIDATION_ERROR', 'Operation action is invalid.', [], 422);
}

if ($type === 'ADD_MONEY') {
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
        api_response(false, 'VALIDATION_ERROR', 'Add Money requires approve or reject.', [], 422);
    }
    $result = add_money_process_request(
        $requestId,
        $decision,
        (string)($auth['user']['uid'] ?? ''),
        'ADMIN_MOBILE',
        trim((string)($body['reason'] ?? $message))
    );
    if (function_exists('admin_action_log')) {
        admin_action_log('ADMIN_MOBILE_ADD_MONEY_' . $decision, $requestId, 'Admin mobile processed Add Money request', [
            'request_id' => $requestId,
            'admin_uid' => (string)($auth['user']['uid'] ?? ''),
            'result_code' => (string)($result['code'] ?? ''),
        ]);
    }
    $code = (string)($result['code'] ?? 'SERVER_ERROR');
    $httpStatus = !empty($result['ok']) ? 200 : (in_array($code, ['REQUEST_BUSY', 'ALREADY_PROCESSED'], true) ? 409 : ($code === 'NOT_FOUND' ? 404 : 422));
    api_response(!empty($result['ok']), $code, (string)($result['message'] ?? 'Add Money action failed.'), (array)($result['data'] ?? []), $httpStatus);
}

if (!in_array($decision, ['SUCCESS', 'FAILED'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'This operation requires success or failed.', [], 422);
}

if ($type === 'BKASH' || $type === 'NAGAD') {
    $request = mfs_find_request($requestId);
    $provider = mfs_normalize_provider((string)($request['provider'] ?? ''));
    if ($request === [] || $provider !== $type) {
        api_response(false, 'NOT_FOUND', 'MFS request was not found for this provider.', [], 404);
    }
}

$routes = [
    'TOPUP' => [
        'SUCCESS' => 'admin/topup/mark_success.php',
        'FAILED' => 'admin/topup/mark_failed.php',
    ],
    'BUNDLE' => [
        'SUCCESS' => 'admin/bundle/mark_success.php',
        'FAILED' => 'admin/bundle/mark_failed.php',
    ],
    'BKASH' => [
        'SUCCESS' => 'admin/mfs/mark_success.php',
        'FAILED' => 'admin/mfs/mark_failed.php',
    ],
    'NAGAD' => [
        'SUCCESS' => 'admin/mfs/mark_success.php',
        'FAILED' => 'admin/mfs/mark_failed.php',
    ],
];
$payload = [
    'request_id' => $requestId,
    'message' => $message,
    'trxid' => trim((string)($body['trxid'] ?? '')),
    'sender_details' => trim((string)($body['sender_details'] ?? '')),
];
$result = admin_mobile_internal_request(
    'POST',
    $routes[$type][$decision],
    $payload,
    admin_mobile_current_token(),
    $deviceId
);
admin_mobile_emit_internal($result);
