<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';
require_once dirname(__DIR__, 2) . '/lib/topup.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];

$body = api_read_json_body();

$requestId = trim((string)($body['request_id'] ?? ''));
$message = trim((string)($body['message'] ?? 'Topup completed manually'));

if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [], 422);
}

$res = topup_mark_success($requestId, $message);

if (!($res['ok'] ?? false)) {
    api_response(false, (string)($res['code'] ?? 'SERVER_ERROR'), (string)($res['message'] ?? 'Failed to mark success'), [], 500);
}

admin_action_log('TOPUP_MARK_SUCCESS', $requestId, 'Admin marked topup success', [
    'request_id' => $requestId,
    'message' => $message,
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

api_response(true, 'TOPUP_SUCCESS', 'Topup marked as success', [
    'request_id' => $requestId,
]);