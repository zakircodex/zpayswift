<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/worker_sms_archive.php';

api_require_method('GET');
auth_require_admin_session(true);

$limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$cursor = trim((string)($_GET['cursor'] ?? ''));
$page = worker_sms_archive_page($limit, $cursor);
if (empty($page['ok'])) {
    api_response(
        false,
        (string)($page['code'] ?? 'SERVER_ERROR'),
        (string)($page['message'] ?? 'Failed to load worker SMS archive'),
        [],
        ($page['code'] ?? '') === 'INVALID_CURSOR' ? 422 : 500
    );
}

api_response(true, 'SUCCESS', 'Worker SMS archive loaded', [
    'items' => (array)($page['items'] ?? []),
    'pagination' => (array)($page['pagination'] ?? []),
]);
