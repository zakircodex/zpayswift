<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/account_review.php';

api_require_method('POST');

$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];
$body = api_read_json_body();
$uid = trim((string)($body['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

$result = account_review_apply(
    $uid,
    'REJECT',
    (string)($adminUser['uid'] ?? ''),
    'ADMIN'
);

api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'SERVER_ERROR'),
    (string)($result['message'] ?? 'Failed to reject account'),
    (array)($result['data'] ?? []),
    !empty($result['ok'])
        ? 200
        : (($result['code'] ?? '') === 'NOT_FOUND' ? 404 : (($result['code'] ?? '') === 'SERVER_ERROR' ? 500 : 422))
);
