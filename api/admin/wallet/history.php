<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';

api_require_method('GET');
auth_require_admin_session(true);

$month = wallet_valid_month_key((string)($_GET['month'] ?? ''));
$limit = (int)($_GET['limit'] ?? 200);

$items = wallet_list_transfer_history($month, [
    'receiver' => trim((string)($_GET['receiver'] ?? '')),
    'sender_role' => trim((string)($_GET['sender_role'] ?? '')),
    'receiver_role' => trim((string)($_GET['receiver_role'] ?? '')),
    'type' => trim((string)($_GET['type'] ?? '')),
], $limit);

api_response(true, 'SUCCESS', 'Wallet transfer history loaded', [
    'month' => $month,
    'items' => $items,
    'count' => count($items),
]);
