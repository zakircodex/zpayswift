<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$uid = trim((string)($_GET['uid'] ?? ''));
$month = trim((string)($_GET['month'] ?? month_key()));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', [], 422);
}

$items = fb_get('WALLET_LEDGER/' . $uid . '/' . $month);
if (!is_array($items)) {
    $items = [];
}

$list = array_values($items);

usort($list, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

api_response(true, 'SUCCESS', 'Wallet ledger loaded', [
    'uid' => $uid,
    'month' => $month,
    'items' => $list,
]);