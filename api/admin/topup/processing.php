<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/admin_topup.php';

api_require_method('GET');
auth_require_admin_session();

$page = max(1, (int)($_GET['page'] ?? 1));
$cursor = trim((string)($_GET['cursor'] ?? ''));
$result = admin_topup_read_bucket_page('PROCESSING', $_GET, $cursor, 10);
$result['pagination']['page'] = $page;

api_response(true, 'SUCCESS', 'Processing topup requests loaded', [
    'bucket' => 'PROCESSING',
    'items' => $result['items'],
    'pagination' => $result['pagination'],
]);
