<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$uid = (string)$auth['user']['uid'];

$month = trim((string)($_GET['month'] ?? month_key()));
$items = fb_get('BUNDLE_HISTORY/' . $uid . '/' . $month);

if (!is_array($items)) {
    $items = [];
}

$list = [];
foreach ($items as $requestId => $row) {
    if (!is_array($row)) {
        continue;
    }
    $row['request_id'] = (string)($row['request_id'] ?? $requestId);
    $list[$row['request_id']] = $row;
}

$pending = fb_get('BUNDLE_REQUESTS/PENDING');
if (is_array($pending)) {
    foreach ($pending as $requestId => $row) {
        if (!is_array($row) || (string)($row['uid'] ?? '') !== $uid) {
            continue;
        }
        $createdAt = (int)($row['created_at'] ?? 0);
        if ($createdAt > 0 && date('Y-m', $createdAt) !== $month) {
            continue;
        }
        $row['request_id'] = (string)($row['request_id'] ?? $requestId);
        $row['request_type'] = 'BUNDLE';
        $row['display_status'] = 'Pending';
        $list[$row['request_id']] = $row;
    }
}

$list = array_values($list);

usort($list, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

api_response(true, 'SUCCESS', 'Bundle history loaded', [
    'month' => $month,
    'items' => $list,
]);
