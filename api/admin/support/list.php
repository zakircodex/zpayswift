<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('GET');
$auth = auth_require_admin_session(true);
$status = support_clean_code($_GET['status'] ?? '');
$query = support_clean_text($_GET['query'] ?? '', 120);
$limit = support_int($_GET['limit'] ?? 50, 50, 1, 200);

api_response(true, 'ADMIN_SUPPORT_LIST_OK', 'Support tickets loaded.', [
    'tickets' => support_admin_list($status, $query, $limit),
    'admin_uid' => (string)($auth['user']['uid'] ?? ''),
]);

