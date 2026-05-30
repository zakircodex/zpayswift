<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

try {
    require_once dirname(__DIR__, 2) . '/lib/mfs.php';
} catch (Throwable $e) {
    api_response(false, 'MFS_LIB_ERROR', 'Failed to load mfs.php', [
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}

api_require_method('GET');

try {
    auth_require_admin_session();

    foreach (['mfs_read_bucket', 'mfs_apply_filters', 'mfs_paginate'] as $fn) {
        if (!function_exists($fn)) {
            api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: ' . $fn, [
                'function' => $fn,
            ], 500);
        }
    }

    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 50);

    $items = mfs_read_bucket('PENDING');
    $items = mfs_apply_filters($items, $_GET);
    $paginated = mfs_paginate($items, $page, $limit);

    api_response(true, 'SUCCESS', 'Pending MFS requests loaded', [
        'bucket' => 'PENDING',
        'items' => $paginated['items'] ?? [],
        'pagination' => $paginated['pagination'] ?? [
            'page' => $page,
            'limit' => $limit,
            'total' => 0,
            'has_more' => false,
        ],
    ]);
} catch (Throwable $e) {
    api_response(false, 'MFS_PENDING_ERROR', 'Pending MFS request load failed', [
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], 500);
}