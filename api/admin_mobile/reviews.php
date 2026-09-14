<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/account_review.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
$auth = admin_mobile_require_session(true);
$deviceId = admin_mobile_device_id(true);

if ($method === 'GET') {
    $query = http_build_query([
        'status' => 'REVIEW',
        'cursor' => trim((string)($_GET['cursor'] ?? '')),
        'page' => max(1, (int)($_GET['page'] ?? 1)),
        'search' => trim((string)($_GET['query'] ?? '')),
    ], '', '&', PHP_QUERY_RFC3986);
    $result = admin_mobile_internal_request(
        'GET',
        'admin/users/list.php?' . $query,
        null,
        admin_mobile_current_token(),
        $deviceId
    );
    admin_mobile_emit_internal($result);
}

$body = api_read_json_body();
$uid = trim((string)($body['uid'] ?? ''));
$decision = strtoupper(trim((string)($body['decision'] ?? '')));
if ($uid === '' || !in_array($decision, ['APPROVE', 'REJECT'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'Account review action is invalid.', [], 422);
}
$result = account_review_apply(
    $uid,
    $decision,
    (string)($auth['user']['uid'] ?? ''),
    'ADMIN_MOBILE'
);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'SERVER_ERROR'),
    (string)($result['message'] ?? 'Account review action failed.'),
    (array)($result['data'] ?? []),
    account_review_http_status($result)
);
