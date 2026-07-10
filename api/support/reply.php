<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);

$body = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false
    ? $_POST
    : api_read_json_body();

$ticketId = support_clean_text($body['ticket_id'] ?? '', 40);
$message = (string)($body['message'] ?? '');
$uid = support_uid_from_auth($auth);
$idem = support_clean_text($body['idempotency_key'] ?? '', 120);
if ($uid !== '' && $ticketId !== '' && $idem !== '') {
    $existing = fb_get('SUPPORT_USER_REPLY_IDEMPOTENCY/' . $uid . '/' . $ticketId . '/' . hash('sha256', $idem));
    if (is_array($existing) && (string)($existing['message_id'] ?? '') !== '') {
        $ticket = support_read_ticket($ticketId);
        if ($ticket !== [] && support_user_can_access($auth, $ticket)) {
            $payload = support_details_payload($ticket);
            api_response(true, 'SUPPORT_REPLY_DUPLICATE', 'Reply already sent.', [
                'ticket' => $payload['ticket'],
                'messages' => $payload['messages'],
                'attachments' => $payload['attachments'],
            ]);
        }
    }
}

$result = support_reply($auth, $ticketId, $message, $_FILES, 'USER', [
    'idempotency_key' => $idem,
    'source' => 'ANDROID',
]);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
}

if ($uid !== '' && $ticketId !== '' && $idem !== '') {
    $messages = (array)($result['messages'] ?? []);
    $last = end($messages);
    fb_put('SUPPORT_USER_REPLY_IDEMPOTENCY/' . $uid . '/' . $ticketId . '/' . hash('sha256', $idem), [
        'message_id' => is_array($last) ? (string)($last['message_id'] ?? '') : '',
        'created_at' => support_now(),
    ]);
}

api_response(true, 'SUPPORT_REPLY_SENT', 'Reply sent.', [
    'ticket' => $result['ticket'],
    'messages' => $result['messages'],
    'attachments' => $result['attachments'],
]);
