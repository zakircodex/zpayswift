<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

api_require_method('POST');

$auth = auth_require_admin_session(true);

if (!function_exists('mfs_mark_failed')) {
    api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: mfs_mark_failed', [
        'function' => 'mfs_mark_failed',
    ], 500);
}

$body = api_read_json_body();

$requestId = trim((string)($body['request_id'] ?? ''));
$message = trim((string)($body['message'] ?? $body['final_message'] ?? ''));

if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [], 422);
}

if ($message === '') {
    $message = 'Transaction failed';
}

$actorUser = is_array($auth) ? $auth : [];

$actor = [
    'uid' => (string)($actorUser['uid'] ?? $actorUser['user']['uid'] ?? ''),
    'role' => (string)($actorUser['role'] ?? $actorUser['user']['role'] ?? 'ADMIN'),
];

$res = mfs_mark_failed($requestId, $message, $actor);

if (empty($res['ok'])) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');

    $httpStatus = 500;

    if ($code === 'VALIDATION_ERROR' || $code === 'INVALID_REQUEST') {
        $httpStatus = 422;
    } elseif ($code === 'NOT_FOUND') {
        $httpStatus = 404;
    } elseif ($code === 'ALREADY_COMPLETED') {
        $httpStatus = 409;
    }

    api_response(false, $code, (string)($res['message'] ?? 'Failed to mark failed'), (array)($res['data'] ?? []), $httpStatus);
}

api_response(true, 'SUCCESS', 'MFS request marked failed', (array)($res['data'] ?? []));