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
    $result = notification_mark_many_read_result($uid, (array)$body['notification_ids']);
    if (empty($result['ok'])) {
        api_response(false, (string)($result['code'] ?? 'NOTIFICATION_UPDATE_FAILED'), 'Notifications could not be updated.', [], 503);
    }
    api_response(true, 'NOTIFICATIONS_READ_OK', 'Notifications marked as read.', [
        'marked_count' => (int)($result['marked_count'] ?? 0),
        'unread_count' => (int)($result['unread_count'] ?? 0),
    ]);
}
if ($notificationId === '') {
    api_response(false, 'NOTIFICATION_ID_REQUIRED', 'Notification ID is required.', [], 422);
}
$result = notification_mark_many_read_result($uid, [$notificationId]);
if (empty($result['ok'])) {
    api_response(false, (string)($result['code'] ?? 'NOTIFICATION_UPDATE_FAILED'), 'Notification could not be updated.', [], 503);
}
if ((int)($result['marked_count'] ?? 0) !== 1) {
    api_response(false, 'NOTIFICATION_NOT_FOUND', 'Notification was not found.', [], 404);
}

api_response(true, 'NOTIFICATION_READ_OK', 'Notification marked as read.', [
    'notification_id' => notification_clean_text($notificationId, 80),
    'marked_count' => 1,
    'unread_count' => (int)($result['unread_count'] ?? 0),
]);
