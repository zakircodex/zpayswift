<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/admin_pagination.php';

try {
    require_once dirname(__DIR__, 2) . '/lib/mfs.php';
} catch (Throwable $e) {
    api_response(false, 'MFS_LIB_ERROR', 'MFS service could not be loaded', [], 500);
}

api_require_method('GET');

try {
    auth_require_admin_session();

    foreach (['mfs_read_bucket_page', 'mfs_apply_filters'] as $fn) {
        if (!function_exists($fn)) {
            api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: ' . $fn, [
                'function' => $fn,
            ], 500);
        }
    }

    $page = (int)($_GET['page'] ?? 1);
    $cursor = trim((string)($_GET['cursor'] ?? ''));
    $paginated = mfs_read_bucket_page('PENDING', $_GET, $cursor, 10);
    $pagination = (array)($paginated['pagination'] ?? []);
    $pagination['page'] = max(1, $page);

    api_response(true, 'SUCCESS', 'Pending MFS requests loaded', [
        'bucket' => 'PENDING',
        'items' => $paginated['items'] ?? [],
        'pagination' => $pagination,
    ]);
} catch (Throwable $e) {
    api_response(false, 'MFS_PENDING_ERROR', 'Pending MFS request load failed', [], 500);
}
