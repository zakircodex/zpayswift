<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$items = fb_get('BUNDLE_REQUESTS/PENDING');
if (!is_array($items)) {
    $items = [];
}

$list = array_values($items);

usort($list, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

api_response(true, 'SUCCESS', 'Pending bundle requests loaded', [
    'items' => $list,
]);