<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('POST');
api_require_app_key();
$auth = auth_require_user(true);
$body = api_read_json_body();

$uid = (string)($auth['user']['uid'] ?? '');
$notificationId = (string)($body['notification_id'] ?? '');
if (is_array($body['notification_ids'] ?? null)) {
    $marked = notification_mark_many_read($uid, (array)$body['notification_ids']);
    api_response(true, 'NOTIFICATIONS_READ_OK', 'Notifications marked as read.', [
        'marked_count' => $marked,
        'unread_count' => notification_unread_count($uid),
    ]);
}
if ($notificationId === '') {
    api_response(false, 'NOTIFICATION_ID_REQUIRED', 'Notification ID is required.', [], 422);
}
if (!notification_mark_read($uid, $notificationId)) {
    api_response(false, 'NOTIFICATION_NOT_FOUND', 'Notification was not found.', [], 404);
}

api_response(true, 'NOTIFICATION_READ_OK', 'Notification marked as read.', [
    'notification_id' => notification_clean_text($notificationId, 80),
    'unread_count' => notification_unread_count($uid),
]);
