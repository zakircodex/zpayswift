<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('GET');
$auth = auth_require_admin_session(true);
$status = support_clean_code($_GET['status'] ?? '');
$query = support_clean_text($_GET['query'] ?? '', 120);
$cursor = trim((string)($_GET['cursor'] ?? ''));
$pageNumber = max(1, (int)($_GET['page'] ?? 1));
$page = support_admin_page($status, $query, $cursor, 10);
$pagination = (array)($page['pagination'] ?? []);
$pagination['page'] = $pageNumber;

api_response(true, 'ADMIN_SUPPORT_LIST_OK', 'Support tickets loaded.', [
    'tickets' => (array)($page['items'] ?? []),
    'pagination' => $pagination,
    'admin_uid' => (string)($auth['user']['uid'] ?? ''),
]);
