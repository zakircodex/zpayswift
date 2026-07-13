<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('POST');
api_require_app_key();
$auth = auth_require_user(true);

$uid = (string)($auth['user']['uid'] ?? '');
$marked = notification_mark_all_read($uid);
api_response(true, 'NOTIFICATIONS_ALL_READ_OK', 'Notifications marked as read.', [
    'marked_count' => $marked,
    'unread_count' => notification_unread_count($uid),
]);
