<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('GET');
auth_require_admin_session(true);

$ticketId = support_clean_text($_GET['ticket_id'] ?? '', 40);
$attachmentId = support_clean_text($_GET['attachment_id'] ?? '', 40);

$ticket = support_read_ticket($ticketId);
if ($ticket === []) {
    api_response(false, 'SUPPORT_TICKET_NOT_FOUND', 'Support ticket was not found.', [], 404);
}

$row = fb_get('SUPPORT_ATTACHMENTS/' . $ticketId . '/' . $attachmentId);
if (!is_array($row) || (string)($row['ticket_id'] ?? '') !== $ticketId) {
    api_response(false, 'SUPPORT_ATTACHMENT_NOT_FOUND', 'Attachment was not found.', [], 404);
}

$mime = (string)($row['mime'] ?? '');
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    api_response(false, 'SUPPORT_ATTACHMENT_TYPE_INVALID', 'Attachment type is not supported.', [], 415);
}

$path = support_attachment_absolute_path($row);
if ($path === '' || !is_file($path)) {
    api_response(false, 'SUPPORT_ATTACHMENT_NOT_FOUND', 'Attachment was not found.', [], 404);
}

$fileName = support_clean_text($row['original_name'] ?? 'attachment', 120) ?: 'attachment';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes($fileName, "\"\\") . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($path);
exit;

