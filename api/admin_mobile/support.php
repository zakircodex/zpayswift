<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/support.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
$auth = admin_mobile_require_session(true);

if ($method === 'GET') {
    $ticketId = support_clean_text($_GET['ticket_id'] ?? '', 40);
    if ($ticketId !== '') {
        $ticket = support_read_ticket($ticketId);
        if ($ticket === []) {
            api_response(false, 'SUPPORT_TICKET_NOT_FOUND', 'Support ticket was not found.', [], 404);
        }
        if (!empty($ticket['admin_unread'])) {
            fb_patch('SUPPORT_TICKETS/' . $ticketId, ['admin_unread' => false]);
            $ticket = support_read_ticket($ticketId);
        }
        $payload = support_details_payload($ticket);
        api_response(true, 'ADMIN_MOBILE_SUPPORT_DETAILS_OK', 'Support ticket loaded.', [
            'ticket' => (array)($payload['ticket'] ?? []),
            'messages' => (array)($payload['messages'] ?? []),
            'attachments' => (array)($payload['attachments'] ?? []),
        ]);
    }

    $status = support_clean_code($_GET['status'] ?? 'OPEN');
    $query = support_clean_text($_GET['query'] ?? '', 120);
    $cursor = trim((string)($_GET['cursor'] ?? ''));
    $page = support_admin_page($status, $query, $cursor, 10);
    api_response(true, 'ADMIN_MOBILE_SUPPORT_LIST_OK', 'Support tickets loaded.', [
        'tickets' => (array)($page['items'] ?? []),
        'pagination' => (array)($page['pagination'] ?? []),
    ]);
}

$body = api_read_json_body();
$ticketId = support_clean_text($body['ticket_id'] ?? '', 40);
$message = (string)($body['message'] ?? '');
$idempotencyKey = support_clean_text($body['idempotency_key'] ?? '', 120);
if ($idempotencyKey === '') {
    api_response(false, 'VALIDATION_ERROR', 'A reply idempotency key is required.', [], 422);
}
$result = support_reply($auth, $ticketId, $message, [], 'ADMIN', [
    'idempotency_key' => $idempotencyKey,
    'source' => 'ADMIN_MOBILE',
    'reply_to_message_id' => support_clean_text($body['reply_to_message_id'] ?? '', 80),
]);
if (empty($result['ok'])) {
    api_response(false, (string)($result['code'] ?? 'SUPPORT_REPLY_FAILED'), (string)($result['message'] ?? 'Support reply failed.'), [], (int)($result['status'] ?? 400));
}

api_response(true, !empty($result['duplicate']) ? 'ADMIN_MOBILE_SUPPORT_REPLY_DUPLICATE' : 'ADMIN_MOBILE_SUPPORT_REPLY_SENT', !empty($result['duplicate']) ? 'Support reply already sent.' : 'Support reply sent.', [
    'ticket' => (array)($result['ticket'] ?? []),
    'messages' => (array)($result['messages'] ?? []),
    'attachments' => (array)($result['attachments'] ?? []),
]);
