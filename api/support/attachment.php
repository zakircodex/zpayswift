<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('GET');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$ticketId = support_clean_text($_GET['ticket_id'] ?? '', 40);
$attachmentId = support_clean_text($_GET['attachment_id'] ?? '', 40);

$details = support_details_for_user($auth, $ticketId);
if (empty($details['ok'])) {
    api_response(false, (string)$details['code'], (string)$details['message'], [], (int)($details['status'] ?? 400));
}

$row = fb_get('SUPPORT_ATTACHMENTS/' . $ticketId . '/' . $attachmentId);
if (!is_array($row)) {
    api_response(false, 'SUPPORT_ATTACHMENT_NOT_FOUND', 'Attachment was not found.', [], 404);
}

$path = support_attachment_absolute_path($row);
if ($path === '' || !is_file($path)) {
    api_response(false, 'SUPPORT_ATTACHMENT_NOT_FOUND', 'Attachment was not found.', [], 404);
}

header('Content-Type: ' . (string)($row['mime'] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes((string)($row['original_name'] ?? 'attachment'), "\"\\") . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
