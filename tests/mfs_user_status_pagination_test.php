<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$assertions = 0;
$store = [];

function mfs_user_page_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mfs_user_page_get(string $path): mixed
{
    global $store;
    $node = $store;
    foreach (array_values(array_filter(explode('/', trim($path, '/')))) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function fb_get(string $path, array $query = []): mixed
{
    $rows = mfs_user_page_get($path);
    if (!is_array($rows) || $query === []) {
        return $rows;
    }

    ksort($rows, SORT_STRING);
    $endAt = isset($query['endAt']) ? json_decode((string)$query['endAt'], true) : '';
    if (is_string($endAt) && $endAt !== '') {
        $rows = array_filter(
            $rows,
            static fn(mixed $_row, string $key): bool => strcmp($key, $endAt) <= 0,
            ARRAY_FILTER_USE_BOTH
        );
    }

    $limit = max(1, (int)($query['limitToLast'] ?? count($rows)));
    return array_slice($rows, -$limit, null, true);
}

require_once dirname(__DIR__) . '/api/lib/mfs.php';

$targetUid = 'SUBADMIN_TARGET';
for ($i = 1; $i <= 80; $i++) {
    $requestId = sprintf('MF100%03d', $i);
    $store['MFS_REQUESTS']['DONE'][$requestId] = [
        'request_id' => $requestId,
        'uid' => 'OTHER_USER',
        'status' => 'SUCCESSFUL',
        'provider' => 'BKASH',
        'receiver_number' => '01700000000',
        'created_at' => 2000 + $i,
    ];
}

for ($i = 1; $i <= 12; $i++) {
    $requestId = sprintf('MF000%03d', $i);
    $store['MFS_REQUESTS']['DONE'][$requestId] = [
        'request_id' => $requestId,
        'uid' => $targetUid,
        'status' => 'SUCCESSFUL',
        'provider' => $i === 1 ? 'NAGAD' : 'BKASH',
        'receiver_number' => '01800000000',
        'created_at' => 1000 + $i,
    ];
    $store['MFS_USER_STATUS_COUNTERS'][$targetUid]['SUCCESSFUL'][$requestId] = true;
}

$sparseGlobalPage = mfs_read_bucket_page('DONE', [
    'uid' => $targetUid,
    'status' => 'SUCCESSFUL',
]);
mfs_user_page_expect($sparseGlobalPage['items'] === [], 'Sparse global scan should reproduce the empty first page');
mfs_user_page_expect(!empty($sparseGlobalPage['pagination']['has_more']), 'Sparse global scan must expose its continuation');

$first = mfs_read_user_status_page($targetUid, 'SUCCESSFUL', [], '', 10);
mfs_user_page_expect(count($first['items']) === 10, 'Indexed first page must contain ten user requests');
mfs_user_page_expect(($first['items'][0]['request_id'] ?? '') === 'MF000012', 'Indexed page is not newest first');
mfs_user_page_expect(($first['items'][9]['request_id'] ?? '') === 'MF000003', 'Indexed first-page boundary is incorrect');
mfs_user_page_expect(!empty($first['pagination']['has_more']), 'Indexed first page must expose a continuation');

$second = mfs_read_user_status_page(
    $targetUid,
    'SUCCESSFUL',
    [],
    (string)$first['pagination']['next_cursor'],
    10
);
mfs_user_page_expect(count($second['items']) === 2, 'Indexed second page must contain the final two requests');
mfs_user_page_expect(($second['items'][0]['request_id'] ?? '') === 'MF000002', 'Indexed second page started at the wrong request');
mfs_user_page_expect(empty($second['pagination']['has_more']), 'Indexed final page must be exhausted');

$providerFiltered = mfs_read_user_status_page($targetUid, 'SUCCESSFUL', ['provider' => 'NAGAD'], '', 10);
mfs_user_page_expect(count($providerFiltered['items']) === 1, 'Indexed provider filter returned the wrong number of requests');
mfs_user_page_expect(($providerFiltered['items'][0]['request_id'] ?? '') === 'MF000001', 'Indexed provider filter returned the wrong request');

$allIds = array_map(
    static fn(array $row): string => (string)$row['request_id'],
    array_merge($first['items'], $second['items'])
);
mfs_user_page_expect(count($allIds) === count(array_unique($allIds)), 'Indexed cursor pages duplicated requests');
mfs_user_page_expect(
    count(array_filter($allIds, static fn(string $requestId): bool => str_starts_with($requestId, 'MF100'))) === 0,
    'Indexed cursor page leaked another user request'
);

echo "MFS user status pagination tests passed ({$assertions} assertions).\n";
