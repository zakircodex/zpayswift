<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('GET');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$ticketId = support_clean_text($_GET['ticket_id'] ?? '', 40);

$result = support_details_for_user($auth, $ticketId);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
}
if (!empty($result['ticket']['user_unread'])) {
    fb_patch('SUPPORT_TICKETS/' . $ticketId, ['user_unread' => false]);
    $result = support_details_for_user($auth, $ticketId);
}
support_mark_user_ticket_notifications_read(support_uid_from_auth($auth), $ticketId);

api_response(true, 'SUPPORT_DETAILS_OK', 'Support ticket loaded.', [
    'ticket' => $result['ticket'],
    'messages' => $result['messages'],
    'attachments' => $result['attachments'],
]);
