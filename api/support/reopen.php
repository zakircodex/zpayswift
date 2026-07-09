<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$body = api_read_json_body();
$ticketId = support_clean_text($body['ticket_id'] ?? '', 40);
$config = support_config();

if (empty($config['reopen_allowed'])) {
    api_response(false, 'SUPPORT_REOPEN_DISABLED', 'Reopen is not available for this ticket.', [], 409);
}

$details = support_details_for_user($auth, $ticketId);
if (empty($details['ok'])) {
    api_response(false, (string)$details['code'], (string)$details['message'], [], (int)($details['status'] ?? 400));
}

$ticket = $details['ticket'];
if (!in_array(support_clean_code($ticket['status'] ?? ''), ['RESOLVED', 'CLOSED'], true)) {
    api_response(false, 'SUPPORT_REOPEN_NOT_REQUIRED', 'This ticket is already open.', [], 409);
}

$now = support_now();
fb_patch('SUPPORT_TICKETS/' . $ticket['ticket_id'], [
    'status' => 'OPEN',
    'updated_at' => $now,
    'reopened_at' => $now,
    'reopened_by' => support_uid_from_auth($auth),
]);
fb_patch('SUPPORT_USER_INDEX/' . support_uid_from_auth($auth) . '/' . $ticket['ticket_id'], [
    'updated_at' => $now,
    'status' => 'OPEN',
]);

$result = support_details_for_user($auth, $ticketId);
api_response(true, 'SUPPORT_TICKET_REOPENED', 'Support ticket reopened.', [
    'ticket' => $result['ticket'],
    'messages' => $result['messages'],
    'attachments' => $result['attachments'],
]);

