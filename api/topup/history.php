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

$list = array_values(array_filter($items, static fn($row): bool => is_array($row)));

usort($list, static function (array $a, array $b): int {
    $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['completed_at'] ?? 0) ?: ($a['created_at'] ?? 0));
    $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['completed_at'] ?? 0) ?: ($b['created_at'] ?? 0));
    return $bTime <=> $aTime;
});

api_response(true, 'SUCCESS', 'Topup history loaded', [
    'month' => $month,
    'items' => $list,
]);
