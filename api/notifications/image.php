<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/notifications.php';

api_require_method('GET');
api_require_app_key();
$auth = auth_require_user(true);

$uid = (string)($auth['user']['uid'] ?? '');
$notificationId = (string)($_GET['notification_id'] ?? $_GET['id'] ?? '');
$row = notification_get_for_user($uid, $notificationId);
if ($row === []) {
    api_response(false, 'NOTIFICATION_NOT_FOUND', 'Notification was not found.', [], 404);
}

$path = (string)($row['image_path'] ?? '');
$mime = (string)($row['image_mime'] ?? '');
$name = (string)($row['image_name'] ?? 'notice.jpg');
$real = $path !== '' ? realpath($path) : false;
$root = realpath(notification_private_storage_root());
if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real) || !is_readable($real)) {
    api_response(false, 'NOTICE_IMAGE_NOT_FOUND', 'Notice image was not found.', [], 404);
}
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    api_response(false, 'NOTICE_IMAGE_UNSUPPORTED', 'Notice image type is not supported.', [], 415);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($real));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
readfile($real);
exit;
