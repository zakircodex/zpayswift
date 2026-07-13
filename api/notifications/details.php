<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('GET');
api_require_app_key();
$auth = auth_require_user(true);

$uid = (string)($auth['user']['uid'] ?? '');
$notificationId = (string)($_GET['notification_id'] ?? $_GET['id'] ?? '');
$item = notification_details_for_user($uid, $notificationId);
if ($item === []) {
    api_response(false, 'NOTIFICATION_NOT_FOUND', 'Notification was not found.', [], 404);
}

api_response(true, 'NOTIFICATION_DETAILS_OK', 'Notification details loaded.', [
    'notification' => $item,
    'unread_count' => notification_unread_count($uid),
]);
