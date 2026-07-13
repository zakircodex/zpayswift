<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('GET');
api_require_app_key();
$auth = auth_require_user(true);

$uid = (string)($auth['user']['uid'] ?? '');
api_response(true, 'NOTIFICATIONS_UNREAD_OK', 'Unread notification count loaded.', [
    'unread_count' => notification_unread_count($uid),
]);
