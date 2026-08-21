<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/admin_pagination.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

api_require_method('GET');
auth_require_admin_session();

if (!function_exists('mfs_read_bucket_page')) {
    api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: mfs_read_bucket_page', [
        'function' => 'mfs_read_bucket_page',
    ], 500);
}

$page = (int)($_GET['page'] ?? 1);
$cursor = trim((string)($_GET['cursor'] ?? ''));
$paginated = mfs_read_bucket_page('DONE', $_GET, $cursor, 10);
$pagination = (array)($paginated['pagination'] ?? []);
$pagination['page'] = max(1, $page);

api_response(true, 'SUCCESS', 'Done MFS requests loaded', [
    'bucket' => 'DONE',
    'items' => $paginated['items'],
    'pagination' => $pagination,
]);
