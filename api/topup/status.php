<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$uid = (string)$auth['user']['uid'];

$requestId = trim((string)($_GET['request_id'] ?? ''));
if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [
        'field' => 'request_id',
    ], 422);
}

$status = fb_get('REQUEST_STATUS/' . $requestId);
if (!is_array($status)) {
    api_response(false, 'NOT_FOUND', 'Request status not found', [], 404);
}

if ((string)($status['uid'] ?? '') !== $uid) {
    api_response(false, 'UNAUTHORIZED', 'You do not have access to this request', [], 403);
}

api_response(true, 'SUCCESS', 'Status loaded', [
    'request_id' => (string)($status['request_id'] ?? ''),
    'type' => (string)($status['type'] ?? ''),
    'status' => (string)($status['status'] ?? ''),
    'message' => (string)($status['message'] ?? ''),
    'updated_at' => (int)($status['updated_at'] ?? 0),
]);
