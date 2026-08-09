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

$result = support_create_ticket($auth, $body, $_FILES);
if (empty($result['ok'])) {
    $data = [];
    if ((string)($result['code'] ?? '') === 'SUPPORT_ACTIVE_TICKET_EXISTS') {
        $data = [
            'active_ticket_id' => (string)($result['active_ticket_id'] ?? ''),
            'active_ticket' => is_array($result['active_ticket'] ?? null) ? $result['active_ticket'] : [],
        ];
    }
    api_response(false, (string)$result['code'], (string)$result['message'], $data, (int)($result['status'] ?? 400));
}

api_response(true, 'SUPPORT_TICKET_CREATED', 'Support request submitted.', [
    'ticket' => $result['ticket'],
]);
