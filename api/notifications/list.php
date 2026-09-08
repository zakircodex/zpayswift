<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('GET');
api_require_app_key();
$auth = auth_require_user(true);

$uid = (string)($auth['user']['uid'] ?? '');
$limit = (int)($_GET['limit'] ?? 20);
$before = (int)($_GET['before'] ?? 0);
$beforeId = (string)($_GET['before_id'] ?? '');
$filter = (string)($_GET['filter'] ?? 'ALL');
$rows = notification_rows_for_user($uid);
$page = notification_page_from_rows($rows, $limit, $before, $filter, $beforeId);

api_response(true, 'NOTIFICATIONS_LIST_OK', 'Notifications loaded.', $page);
