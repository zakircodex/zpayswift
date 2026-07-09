<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('GET');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$status = support_clean_code($_GET['status'] ?? '');
$limit = support_int($_GET['limit'] ?? 50, 50, 1, 100);

api_response(true, 'SUPPORT_LIST_OK', 'Support requests loaded.', [
    'tickets' => support_list_for_uid(support_uid_from_auth($auth), $status, $limit),
]);

