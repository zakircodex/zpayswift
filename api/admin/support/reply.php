<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false
    ? $_POST
    : api_read_json_body();

$ticketId = support_clean_text($body['ticket_id'] ?? '', 40);
$message = (string)($body['message'] ?? '');
$idem = support_clean_text($body['idempotency_key'] ?? '', 120);
$result = support_reply($auth, $ticketId, $message, $_FILES, 'ADMIN', [
    'idempotency_key' => $idem,
    'source' => 'ADMIN_PANEL',
    'reply_to_message_id' => support_clean_text($body['reply_to_message_id'] ?? '', 80),
]);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
}

api_response(true, !empty($result['duplicate']) ? 'ADMIN_SUPPORT_REPLY_DUPLICATE' : 'ADMIN_SUPPORT_REPLY_SENT', !empty($result['duplicate']) ? 'Support reply already sent.' : 'Support reply sent.', [
    'ticket' => $result['ticket'],
    'messages' => $result['messages'],
    'attachments' => $result['attachments'],
]);
