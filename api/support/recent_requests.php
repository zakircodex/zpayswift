<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('GET');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$uid = support_uid_from_auth($auth);
$limit = support_int($_GET['limit'] ?? 30, 30, 1, 50);

api_response(true, 'SUPPORT_RECENT_REQUESTS_OK', 'Recent requests loaded.', [
    'items' => support_recent_requests_for_uid($uid, $limit),
]);

