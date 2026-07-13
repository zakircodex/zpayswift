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
$filter = (string)($_GET['filter'] ?? 'ALL');
$items = notification_list_for_user($uid, $limit, $before, $filter);
$nextBefore = 0;
if ($items !== []) {
    $last = end($items);
    $nextBefore = (int)($last['created_at'] ?? 0);
}

api_response(true, 'NOTIFICATIONS_LIST_OK', 'Notifications loaded.', [
    'items' => $items,
    'limit' => max(1, min(50, $limit)),
    'next_before' => $nextBefore,
    'unread_count' => notification_unread_count($uid),
]);
