<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'UNAUTHORIZED', 'Session is required', [], 401);
}

$requestId = trim((string)($_GET['request_id'] ?? $_GET['id'] ?? ''));
if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [], 422);
}

$row = mfs_find_request($requestId);
if (!$row || (string)($row['uid'] ?? '') !== $uid) {
    api_response(false, 'NOT_FOUND', 'MFS request not found.', [], 404);
}

unset($row['_bucket']);
api_response(true, 'MFS_STATUS_OK', 'MFS status loaded.', $row);
