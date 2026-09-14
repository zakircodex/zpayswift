<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/add_money.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/bundle.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

function admin_mobile_operation_status(string $type, string $requestId): string
{
    if ($type === 'BKASH' || $type === 'NAGAD') {
        $row = mfs_find_request($requestId);
        return strtoupper(trim((string)($row['status'] ?? '')));
    }
    if ($type === 'TOPUP') {
        $row = topup_find_request($requestId);
        return strtoupper(trim((string)($row['status'] ?? '')));
    }
    if ($type === 'BUNDLE') {
        $row = fb_get('BUNDLE_REQUESTS/DONE/' . $requestId);
        if (!is_array($row)) {
            $row = fb_get('BUNDLE_REQUESTS/PENDING/' . $requestId);
        }
        return strtoupper(trim((string)($row['status'] ?? '')));
    }
    return '';
}

function admin_mobile_operation_is_requested_terminal_status(string $decision, string $status): bool
{
    $status = strtoupper(trim($status));
    return $decision === 'SUCCESS'
        ? in_array($status, ['SUCCESS', 'SUCCESSFUL', 'DONE', 'COMPLETED'], true)
        : in_array($status, ['FAILED', 'REJECTED'], true);
}

function admin_mobile_operation_http_status(string $code): int
{
    if ($code === 'NOT_FOUND') {
        return 404;
    }
    if (in_array($code, ['ALREADY_DONE', 'ALREADY_COMPLETED', 'REQUEST_BUSY', 'FINANCIAL_OPERATION_BUSY'], true)) {
        return 409;
    }
    if (in_array($code, ['VALIDATION_ERROR', 'INVALID_REQUEST', 'SENDER_DETAILS_REQUIRED'], true)) {
        return 422;
    }
    return 500;
}

api_require_method('POST');
$auth = admin_mobile_require_session(true);
$body = api_read_json_body();
$type = strtoupper(trim((string)($body['type'] ?? '')));
$decision = strtoupper(trim((string)($body['decision'] ?? '')));
$requestId = trim((string)($body['request_id'] ?? ''));
$message = trim((string)($body['message'] ?? ''));

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

$actor = [
    'uid' => (string)($auth['user']['uid'] ?? ''),
    'role' => 'ADMIN',
];
$result = [];

if ($type === 'TOPUP') {
    $defaultMessage = $decision === 'SUCCESS' ? 'Topup completed manually' : 'Topup failed manually';
    $result = $decision === 'SUCCESS'
        ? topup_mark_success($requestId, $message !== '' ? $message : $defaultMessage)
        : topup_mark_failed($requestId, $message !== '' ? $message : $defaultMessage);
} elseif ($type === 'BUNDLE') {
    $defaultMessage = $decision === 'SUCCESS' ? 'Bundle sent manually' : 'Failed to send bundle';
    $result = $decision === 'SUCCESS'
        ? bundle_mark_success($requestId, $message !== '' ? $message : $defaultMessage)
        : bundle_mark_failed($requestId, $message !== '' ? $message : $defaultMessage);
} elseif ($decision === 'SUCCESS') {
    $senderDetails = trim((string)($body['sender_details'] ?? ''));
    if ($senderDetails === '') {
        api_response(false, 'VALIDATION_ERROR', 'Sender details are required.', [], 422);
    }
    $saved = mfs_save_sender_details($requestId, $senderDetails);
    if (empty($saved['ok'])) {
        $savedStatus = admin_mobile_operation_status($type, $requestId);
        if (admin_mobile_operation_is_requested_terminal_status($decision, $savedStatus)) {
            api_response(true, 'ALREADY_APPLIED', 'This request was already marked successful.', [
                'request_id' => $requestId,
                'status' => $savedStatus,
                'idempotent_replay' => true,
            ]);
        }
        $savedCode = (string)($saved['code'] ?? 'SERVER_ERROR');
        api_response(false, $savedCode, (string)($saved['message'] ?? 'Sender details could not be saved.'), (array)($saved['data'] ?? []), admin_mobile_operation_http_status($savedCode));
    }
    $successMessage = $message !== '' ? $message : 'Transaction successful. Sender details: ' . $senderDetails;
    $result = mfs_mark_success($requestId, $successMessage, trim((string)($body['trxid'] ?? '')), $actor);
} else {
    $result = mfs_mark_failed($requestId, $message !== '' ? $message : 'Transaction failed', $actor);
}

if (empty($result['ok'])) {
    $status = admin_mobile_operation_status($type, $requestId);
    if (admin_mobile_operation_is_requested_terminal_status($decision, $status)) {
        api_response(true, 'ALREADY_APPLIED', 'This request was already updated.', [
            'request_id' => $requestId,
            'status' => $status,
            'idempotent_replay' => true,
        ]);
    }
    $code = (string)($result['code'] ?? 'SERVER_ERROR');
    api_response(false, $code, (string)($result['message'] ?? 'Operation action failed.'), (array)($result['data'] ?? []), admin_mobile_operation_http_status($code));
}

if (function_exists('admin_action_log')) {
    admin_action_log('ADMIN_MOBILE_' . $type . '_' . $decision, $requestId, 'Admin mobile processed request', [
        'request_id' => $requestId,
        'admin_uid' => (string)($auth['user']['uid'] ?? ''),
        'result_code' => (string)($result['code'] ?? ''),
    ]);
}

api_response(true, (string)($result['code'] ?? 'SUCCESS'), (string)($result['message'] ?? 'Request updated.'), [
    'request_id' => $requestId,
    'status' => admin_mobile_operation_status($type, $requestId),
]);
