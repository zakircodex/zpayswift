<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/admin_topup.php';

api_require_method('GET');
auth_require_admin_session();

$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 20);

$items = admin_topup_read_bucket('PENDING');
$items = admin_topup_apply_filters($items, $_GET);

$result = admin_topup_paginate($items, $page, $limit);

api_response(true, 'SUCCESS', 'Pending topup requests loaded', [
    'bucket' => 'PENDING',
    'items' => $result['items'],
    'pagination' => $result['pagination'],
]);