<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$uid = (string)$auth['user']['uid'];

$month = trim((string)($_GET['month'] ?? month_key()));
$items = fb_get('TOPUP_HISTORY/' . $uid . '/' . $month);

if (!is_array($items)) {
    $items = [];
}

$list = array_values($items);

usort($list, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

api_response(true, 'SUCCESS', 'Topup history loaded', [
    'month' => $month,
    'items' => $list,
]);