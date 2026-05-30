<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

api_require_method('GET');
auth_require_admin_session();

if (!function_exists('mfs_read_bucket')) {
    api_response(false, 'MFS_FUNCTION_MISSING', 'Required MFS function missing: mfs_read_bucket', [
        'function' => 'mfs_read_bucket',
    ], 500);
}

$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 50);

$items = mfs_read_bucket('PROCESSING');
$items = function_exists('mfs_apply_filters') ? mfs_apply_filters($items, $_GET) : $items;
$paginated = function_exists('mfs_paginate')
    ? mfs_paginate($items, $page, $limit)
    : [
        'items' => array_slice(array_values($items), 0, $limit),
        'pagination' => [
            'page' => 1,
            'limit' => $limit,
            'total' => count($items),
            'has_more' => false,
        ],
    ];

api_response(true, 'SUCCESS', 'Processing MFS requests loaded', [
    'bucket' => 'PROCESSING',
    'items' => $paginated['items'],
    'pagination' => $paginated['pagination'],
]);