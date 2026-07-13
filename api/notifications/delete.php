<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('POST');
api_require_app_key();
$auth = auth_require_user(true);
$body = api_read_json_body();

$uid = (string)($auth['user']['uid'] ?? '');
$ids = [];
if (is_array($body['notification_ids'] ?? null)) {
    $ids = (array)$body['notification_ids'];
} elseif (isset($body['notification_id'])) {
    $ids = [(string)$body['notification_id']];
}
if ($ids === []) {
    api_response(false, 'NOTIFICATION_ID_REQUIRED', 'Notification ID is required.', [], 422);
}

$deleted = notification_delete_many($uid, $ids);
api_response(true, 'NOTIFICATIONS_DELETED_OK', 'Notifications deleted.', [
    'deleted_count' => $deleted,
    'unread_count' => notification_unread_count($uid),
]);
