<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('GET');
auth_require_admin_session(true);
$ticketId = support_clean_text($_GET['ticket_id'] ?? '', 40);
$ticket = support_read_ticket($ticketId);
if ($ticket === []) {
    api_response(false, 'SUPPORT_TICKET_NOT_FOUND', 'Support ticket was not found.', [], 404);
}
if (!empty($ticket['admin_unread'])) {
    fb_patch('SUPPORT_TICKETS/' . $ticketId, ['admin_unread' => false]);
    $ticket = support_read_ticket($ticketId);
}

$payload = support_details_payload($ticket);
api_response(true, 'ADMIN_SUPPORT_DETAILS_OK', 'Support ticket loaded.', [
    'ticket' => $payload['ticket'],
    'messages' => $payload['messages'],
    'attachments' => $payload['attachments'],
]);
