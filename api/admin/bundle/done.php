<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
$auth = auth_require_admin_session(true);

$items = fb_get('BUNDLE_REQUESTS/DONE');
if (!is_array($items)) {
    $items = [];
}

$list = [];
foreach ($items as $requestId => $row) {
    if (!is_array($row)) {
        continue;
    }

    $row['request_id'] = (string)($row['request_id'] ?? $requestId);
    $list[] = $row;
}

usort($list, static function (array $a, array $b): int {
    $aTs = (int)($a['updated_at'] ?? $a['completed_at'] ?? $a['created_at'] ?? 0);
    $bTs = (int)($b['updated_at'] ?? $b['completed_at'] ?? $b['created_at'] ?? 0);
    return $bTs <=> $aTs;
});

api_response(true, 'SUCCESS', 'Done bundle requests loaded', [
    'items' => $list,
]);