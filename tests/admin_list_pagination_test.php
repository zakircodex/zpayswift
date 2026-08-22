<?php
declare(strict_types=1);

$assertions = 0;
$store = [];
$queries = [];

function pagination_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fb_get(string $path, array $query = []): mixed
{
    global $store, $queries;
    $queries[] = ['path' => $path, 'query' => $query];
    $rows = is_array($store[$path] ?? null) ? $store[$path] : [];
    if ($query === []) {
        return $rows;
    }

    ksort($rows, SORT_STRING);
    $endAt = isset($query['endAt']) ? json_decode((string)$query['endAt'], true) : '';
    if (is_string($endAt) && $endAt !== '') {
        $rows = array_filter(
            $rows,
            static fn($_row, $key): bool => strcmp((string)$key, $endAt) <= 0,
            ARRAY_FILTER_USE_BOTH
        );
    }

    $limit = max(1, (int)($query['limitToLast'] ?? count($rows)));
    return array_slice($rows, -$limit, null, true);
}

require_once dirname(__DIR__) . '/api/lib/admin_pagination.php';

for ($i = 1; $i <= 25; $i++) {
    $id = sprintf('AM202608210000%02d', $i);
    $store['ADD_MONEY_REQUESTS'][$id] = [
        'request_id' => $id,
        'status' => $i % 2 === 0 ? 'ACTIVE' : 'REVIEW',
        'created_at' => $i,
    ];
}

$first = admin_firebase_cursor_page('ADD_MONEY_REQUESTS', 10);
pagination_expect(count($first['items']) === 10, 'First page must contain exactly ten rows');
pagination_expect(($first['items'][0]['request_id'] ?? '') === 'AM20260821000025', 'First page must be newest first');
pagination_expect(($first['items'][9]['request_id'] ?? '') === 'AM20260821000016', 'First page boundary is incorrect');
pagination_expect(!empty($first['pagination']['has_more']), 'First page must expose a continuation');

$second = admin_firebase_cursor_page(
    'ADD_MONEY_REQUESTS',
    10,
    (string)$first['pagination']['next_cursor']
);
pagination_expect(count($second['items']) === 10, 'Second page must contain exactly ten rows');
pagination_expect(($second['items'][0]['request_id'] ?? '') === 'AM20260821000015', 'Second page cursor did not continue correctly');
pagination_expect(($second['items'][9]['request_id'] ?? '') === 'AM20260821000006', 'Second page boundary is incorrect');

$third = admin_firebase_cursor_page(
    'ADD_MONEY_REQUESTS',
    10,
    (string)$second['pagination']['next_cursor']
);
pagination_expect(count($third['items']) === 5, 'Last page must contain the remaining five rows');
pagination_expect(empty($third['pagination']['has_more']), 'Last page must not expose another continuation');

$allIds = array_map(
    static fn(array $row): string => (string)$row['request_id'],
    array_merge($first['items'], $second['items'], $third['items'])
);
pagination_expect(count($allIds) === count(array_unique($allIds)), 'Cursor pages must not duplicate rows');

$filtered = admin_firebase_cursor_page(
    'ADD_MONEY_REQUESTS',
    10,
    '',
    static fn(array $row): bool => ($row['status'] ?? '') === 'ACTIVE'
);
pagination_expect(count($filtered['items']) === 10, 'Status filter must be applied before collecting the page');
pagination_expect(
    count(array_filter($filtered['items'], static fn(array $row): bool => ($row['status'] ?? '') !== 'ACTIVE')) === 0,
    'Filtered page contains a row from another status'
);

foreach ($queries as $query) {
    pagination_expect($query['query'] !== [], 'Pagination must not perform a full collection read');
    pagination_expect(
        json_decode((string)($query['query']['orderBy'] ?? ''), true) === '$key',
        'Firebase pagination must use key ordering'
    );
    pagination_expect((int)($query['query']['limitToLast'] ?? 0) <= 11, 'A Firebase page query exceeded eleven rows');
}

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$mfsPage = (string)file_get_contents($root . '/api/admin/mfs.php');
$mfsJs = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.js');
$addMoney = (string)file_get_contents($root . '/api/lib/add_money.php');
$support = (string)file_get_contents($root . '/api/lib/support.php');
$offers = (string)file_get_contents($root . '/api/admin/bundle/offers.php');
$users = (string)file_get_contents($root . '/api/admin/users/list.php');
$mfs = (string)file_get_contents($root . '/api/lib/mfs.php');

$sidebarDestinations = [
    'data-section="dashboardSection"',
    'data-section="addMoneySection"',
    'data-section="supportSection"',
    'data-section="topupSection"',
    'data-section="bundleSection"',
    'id="zpayAdminMfsNav"',
    'data-section="bundleOffersSection"',
    'data-section="usersSection"',
    'data-section="operatorsSection"',
    'data-section="zsky24Section"',
];
$lastSidebarPosition = -1;
foreach ($sidebarDestinations as $destination) {
    $position = strpos($dashboard, $destination);
    pagination_expect($position !== false, 'Missing MAIN sidebar destination: ' . $destination);
    pagination_expect($position > $lastSidebarPosition, 'MAIN sidebar destination order is incorrect at: ' . $destination);
    $lastSidebarPosition = $position;
}

foreach (['bundleOffersPrevBtn', 'bundleOffersNextBtn', 'addMoneyPrevBtn', 'addMoneyNextBtn', 'supportPrevBtn', 'supportNextBtn', 'usersPrevBtn', 'usersNextBtn'] as $id) {
    pagination_expect(str_contains($dashboard, 'id="' . $id . '"'), 'Missing Admin pagination control: ' . $id);
}
foreach (['ACTIVE', 'REVIEW', 'REJECTED', 'BLOCKED_INACTIVE', 'ALL'] as $status) {
    pagination_expect(str_contains($dashboard, 'data-user-status="' . $status . '"'), 'Missing Users status tab: ' . $status);
}
pagination_expect(str_contains($dashboardJs, "usersStatus: 'ACTIVE'"), 'Users default status must remain Active');
pagination_expect(str_contains($dashboardJs, 'status: state.usersStatus'), 'Users status must be sent to the server before pagination');
pagination_expect(str_contains($dashboard, 'id="usersRoleFilter"'), 'Users role filter control is missing');
pagination_expect(str_contains($dashboardJs, "role: document.getElementById('usersRoleFilter')?.value || ''"), 'Users role filter must be sent to the backend');
pagination_expect(str_contains($dashboardJs, "document.getElementById('usersRoleFilter')?.addEventListener('change'"), 'Users role changes must reset pagination');
pagination_expect(str_contains($users, "admin_firebase_cursor_page(\n        'USERS'"), 'Users endpoint must use bounded Firebase cursor pagination');
pagination_expect(!str_contains($users, 'admin_users_list_shallow_keys();'), 'Users endpoint must not inventory the full key tree for a page');
pagination_expect(str_contains($users, 'admin_users_list_account_status($user)'), 'Users status grouping must use the canonical non-empty status fallback');
pagination_expect(
    strpos($users, '$matchesUser') < strpos($users, "admin_firebase_cursor_page(\n        'USERS'"),
    'Users status/search/role predicate must be prepared before cursor pagination'
);
pagination_expect(str_contains($users, "if (\$roleFilter !== '' && \$role !== \$roleFilter)"), 'Users role filter must run inside the backend page predicate');

pagination_expect(str_contains($addMoney, "admin_firebase_cursor_page(\n        'ADD_MONEY_REQUESTS'"), 'Add Money must use bounded Firebase pagination');
pagination_expect(!str_contains($addMoney, "fb_get('ADD_MONEY_REQUESTS')"), 'Add Money Admin list must not fetch the full request tree');
pagination_expect(str_contains($support, "admin_firebase_cursor_page(\n        'SUPPORT_TICKETS'"), 'Support must use bounded Firebase pagination');
pagination_expect(!str_contains($support, "fb_get('SUPPORT_TICKETS')"), 'Support Admin list must not fetch the full ticket tree');
pagination_expect(str_contains($offers, "admin_firebase_cursor_page(\n    'BUNDLE_OFFERS'"), 'Bundle Offers must use bounded Firebase pagination');
pagination_expect(!str_contains($offers, "fb_get('BUNDLE_OFFERS')") && !str_contains($offers, 'bundle_admin_list_offers'), 'Bundle Offers endpoint must not load the full offer tree');

pagination_expect(str_contains($mfs, 'function mfs_read_bucket_page('), 'MFS bounded bucket pagination helper is missing');
foreach (['pending.php', 'processing.php', 'done.php'] as $endpoint) {
    $source = (string)file_get_contents($root . '/api/admin/mfs/' . $endpoint);
    pagination_expect(str_contains($source, 'mfs_read_bucket_page('), 'MFS endpoint is not using bounded pagination: ' . $endpoint);
    pagination_expect(!str_contains($source, "mfs_read_bucket('"), 'MFS endpoint still reads a complete bucket: ' . $endpoint);
}
foreach (['mfsPrevBtn', 'mfsNextBtn', 'mfsPaginationText'] as $id) {
    pagination_expect(str_contains($mfsPage, 'id="' . $id . '"'), 'Missing MFS pagination control: ' . $id);
}
pagination_expect(str_contains($mfsJs, 'pages:{') && str_contains($mfsJs, 'limit:10'), 'MFS must retain separate ten-row cursor state per tab');
pagination_expect(str_contains($dashboardJs, 'resetCursorPagination(state.addMoneyPagination)') && str_contains($dashboardJs, 'resetCursorPagination(state.supportPagination)'), 'Operational filters must reset pagination');

echo "Admin list pagination tests passed ({$assertions} assertions).\n";
