<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

api_require_method('GET');
auth_require_admin_session();

if (!function_exists('mfs_find_request')) {
    api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: mfs_find_request', [
        'function' => 'mfs_find_request',
    ], 500);
}

$requestId = trim((string)($_GET['request_id'] ?? ''));

if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [], 422);
}

$row = mfs_find_request($requestId);

if (!$row) {
    api_response(false, 'NOT_FOUND', 'MFS request not found', [
        'request_id' => $requestId,
    ], 404);
}

$public = function_exists('mfs_public_log_row') ? mfs_public_log_row($row) : $row;

api_response(true, 'SUCCESS', 'MFS request loaded', [
    'item' => $public,
    'raw' => $row,
]);