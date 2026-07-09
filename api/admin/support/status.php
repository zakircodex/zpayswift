<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();
$ticketId = support_clean_text($body['ticket_id'] ?? '', 40);
$status = support_clean_code($body['status'] ?? '');

$result = support_admin_set_status($ticketId, $status, is_array($auth['user'] ?? null) ? $auth['user'] : []);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], [], (int)($result['status'] ?? 400));
}

api_response(true, 'ADMIN_SUPPORT_STATUS_SAVED', 'Support ticket status updated.', [
    'ticket' => $result['ticket'],
    'messages' => $result['messages'],
    'attachments' => $result['attachments'],
]);

