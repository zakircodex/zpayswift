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
if ($idem !== '') {
    $existing = fb_get('SUPPORT_ADMIN_REPLY_IDEMPOTENCY/' . $ticketId . '/' . hash('sha256', $idem));
    if (is_array($existing) && (string)($existing['message_id'] ?? '') !== '') {
        $ticket = support_read_ticket($ticketId);
        if ($ticket !== []) {
            $payload = support_details_payload($ticket);
            api_response(true, 'ADMIN_SUPPORT_REPLY_DUPLICATE', 'Support reply already sent.', [
                'ticket' => $payload['ticket'],
                'messages' => $payload['messages'],
                'attachments' => $payload['attachments'],
            ]);
        }
    }
}
$result = support_reply($auth, $ticketId, $message, $_FILES, 'ADMIN', [
    'idempotency_key' => $idem,
    'source' => 'ADMIN_PANEL',
]);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
}

if ($idem !== '') {
    $messages = (array)($result['messages'] ?? []);
    $last = end($messages);
    fb_put('SUPPORT_ADMIN_REPLY_IDEMPOTENCY/' . $ticketId . '/' . hash('sha256', $idem), [
        'message_id' => is_array($last) ? (string)($last['message_id'] ?? '') : '',
        'created_at' => support_now(),
    ]);
}

api_response(true, 'ADMIN_SUPPORT_REPLY_SENT', 'Support reply sent.', [
    'ticket' => $result['ticket'],
    'messages' => $result['messages'],
    'attachments' => $result['attachments'],
]);
