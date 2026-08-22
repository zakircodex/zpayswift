<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$assertions = 0;
$fixture = [];
$queries = [];
$versions = [];

function four_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function four_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function four_get(string $path): mixed
{
    global $fixture;
    $node = $fixture;
    foreach (four_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function four_set(string $path, mixed $value): void
{
    global $fixture, $versions;
    $node =& $fixture;
    foreach (four_parts($path) as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    if (str_starts_with(trim($path, '/'), 'MFS_STATUS_COUNTERS')) {
        $versions['MFS_STATUS_COUNTERS'] = ($versions['MFS_STATUS_COUNTERS'] ?? 0) + 1;
    }
}

function four_delete(string $path): void
{
    global $fixture, $versions;
    $parts = four_parts($path);
    $last = array_pop($parts);
    if ($last === null) {
        return;
    }
    $node =& $fixture;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            return;
        }
        $node =& $node[$part];
    }
    unset($node[$last]);
    if (str_starts_with(trim($path, '/'), 'MFS_STATUS_COUNTERS')) {
        $versions['MFS_STATUS_COUNTERS'] = ($versions['MFS_STATUS_COUNTERS'] ?? 0) + 1;
    }
}

function fb_get(string $path, array $query = []): mixed
{
    global $queries;
    $queries[] = ['path' => $path, 'query' => $query];
    $rows = four_get($path);
    if (!is_array($rows) || $query === []) {
        return $rows;
    }
    if (!empty($query['shallow'])) {
        return array_fill_keys(array_keys($rows), true);
    }
    ksort($rows, SORT_STRING);
    $endAt = isset($query['endAt']) ? json_decode((string)$query['endAt'], true) : '';
    if (is_string($endAt) && $endAt !== '') {
        $rows = array_filter($rows, static fn($_row, $key): bool => strcmp((string)$key, $endAt) <= 0, ARRAY_FILTER_USE_BOTH);
    }
    $limit = max(1, (int)($query['limitToLast'] ?? count($rows)));
    return array_slice($rows, -$limit, null, true);
}

function fb_patch(string $path, array $data): bool
{
    if ($path === '') {
        foreach ($data as $updatePath => $value) {
            $value === null ? four_delete((string)$updatePath) : four_set((string)$updatePath, $value);
        }
        return true;
    }
    $current = four_get($path);
    four_set($path, array_merge(is_array($current) ? $current : [], $data));
    return true;
}

function fb_put(string $path, mixed $data): bool
{
    four_set($path, $data);
    return true;
}

function fb_delete(string $path): bool
{
    four_delete($path);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $versions;
    return ['ok' => true, 'status' => 200, 'etag' => 'v' . (int)($versions[$path] ?? 0), 'value' => four_get($path)];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $versions;
    if ($etag !== 'v' . (int)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    four_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

$root = dirname(__DIR__);
require_once $root . '/api/lib/helpers.php';
require_once $root . '/api/lib/admin_pagination.php';
require_once $root . '/api/lib/admin_user_filters.php';
require_once $root . '/api/lib/admin_topup.php';
require_once $root . '/api/lib/mfs.php';

for ($i = 1; $i <= 25; $i++) {
    $id = sprintf('TP202608230000%02d', $i);
    four_set('TOPUP_REQUESTS/DONE/' . $id, [
        'request_id' => $id,
        'status' => 'SUCCESS',
        'created_at' => $i,
        'uid' => 'USER_' . $i,
    ]);
}
$topupFirst = admin_topup_read_bucket_page('DONE', [], '', 10);
$topupSecond = admin_topup_read_bucket_page('DONE', [], (string)$topupFirst['pagination']['next_cursor'], 10);
four_expect(count($topupFirst['items']) === 10 && count($topupSecond['items']) === 10, 'Top-Up DONE pages must contain at most ten rows');
four_expect(($topupFirst['items'][0]['request_id'] ?? '') === 'TP20260823000025', 'Top-Up DONE must be newest first');
four_expect(($topupSecond['items'][0]['request_id'] ?? '') === 'TP20260823000015', 'Top-Up DONE next cursor is incorrect');

for ($i = 1; $i <= 12; $i++) {
    four_set('MFS_REQUESTS/PENDING/MFP' . $i, ['request_id' => 'MFP' . $i, 'status' => 'PENDING']);
}
for ($i = 1; $i <= 2; $i++) {
    four_set('MFS_REQUESTS/PROCESSING/MFR' . $i, ['request_id' => 'MFR' . $i, 'status' => 'PROCESSING']);
}
for ($i = 1; $i <= 27; $i++) {
    four_set('MFS_REQUESTS/DONE/MFS' . $i, ['request_id' => 'MFS' . $i, 'status' => 'SUCCESSFUL']);
}
for ($i = 1; $i <= 14; $i++) {
    four_set('MFS_REQUESTS/DONE/MFF' . $i, ['request_id' => 'MFF' . $i, 'status' => 'FAILED']);
}

$rebuilt = mfs_status_counts();
four_expect(!empty($rebuilt['ok']), 'Historical MFS counter initialization failed');
four_expect(($rebuilt['counts'] ?? []) === ['pending' => 12, 'processing' => 2, 'done' => 27, 'failed' => 14], 'MFS summary counts are not exact');

$pending = (array)four_get('MFS_REQUESTS/PENDING/MFP1');
$pending['_bucket'] = 'PENDING';
$pending['status'] = 'PROCESSING';
four_expect(mfs_move_request_bucket('MFP1', 'PENDING', 'PROCESSING', $pending), 'MFS processing transition failed');
$afterProcessing = mfs_status_counts_snapshot();
four_expect($afterProcessing === ['pending' => 11, 'processing' => 3, 'done' => 27, 'failed' => 14], 'MFS processing transition did not update counts');
four_expect(mfs_move_request_bucket('MFP1', 'PROCESSING', 'PROCESSING', $pending), 'MFS processing replay failed');
four_expect(mfs_status_counts_snapshot() === $afterProcessing, 'MFS status retry double-counted a request');

$processing = (array)four_get('MFS_REQUESTS/PROCESSING/MFR1');
$processing['_bucket'] = 'PROCESSING';
$processing['status'] = 'SUCCESSFUL';
four_expect(mfs_move_request_bucket('MFR1', 'PROCESSING', 'DONE', $processing), 'MFS successful transition failed');
four_expect(mfs_status_counts_snapshot() === ['pending' => 11, 'processing' => 2, 'done' => 28, 'failed' => 14], 'MFS successful transition did not update exact counts');

$failed = (array)four_get('MFS_REQUESTS/PENDING/MFP2');
$failed['_bucket'] = 'PENDING';
$failed['status'] = 'FAILED';
four_expect(mfs_move_request_bucket('MFP2', 'PENDING', 'DONE', $failed), 'MFS failed transition failed');
$afterFailed = ['pending' => 10, 'processing' => 2, 'done' => 28, 'failed' => 15];
four_expect(mfs_status_counts_snapshot() === $afterFailed, 'MFS failed transition did not update exact counts');
four_expect(mfs_move_request_bucket('MFP2', 'DONE', 'DONE', $failed), 'MFS failed replay did not remain idempotent');
four_expect(mfs_status_counts_snapshot() === $afterFailed, 'MFS failed replay double-counted a request');

$mfsPage = mfs_read_bucket_page('PENDING', [], '', 10);
four_expect(count($mfsPage['items']) === 10, 'MFS list page must remain capped at ten');

$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$supportCss = (string)file_get_contents($root . '/api/admin/assets/admin-support.css');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$topupDone = (string)file_get_contents($root . '/api/admin/topup/done.php');
$users = (string)file_get_contents($root . '/api/admin/users/list.php');
$mfsJs = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.js');

foreach (['tickets', 'contact', 'categories'] as $tab) {
    four_expect(str_contains($dashboard, 'data-support-tab="' . $tab . '"'), 'Missing Support tab: ' . $tab);
    four_expect(str_contains($dashboard, 'data-support-panel="' . $tab . '"'), 'Missing Support panel: ' . $tab);
}
four_expect(str_contains($dashboard, 'id="supportTicketsTab"') && str_contains($dashboard, 'aria-selected="true"'), 'Tickets must be the default Support tab');
four_expect(str_contains($dashboardJs, "setSupportAdminTab('tickets')"), 'Support default tab wiring is missing');
four_expect(str_contains($dashboardJs, "openSupportCategoryModal('')"), 'Existing Add Category action changed');
four_expect(str_contains($supportCss, '[data-support-panel][hidden]'), 'Inactive Support panels are not hidden');

foreach (['topupPrevBtn', 'topupNextBtn', 'topupPaginationText'] as $id) {
    four_expect(str_contains($dashboard, 'id="' . $id . '"'), 'Missing Top-Up pagination control: ' . $id);
}
four_expect(str_contains($topupDone, "admin_topup_read_bucket_page('DONE'"), 'Top-Up DONE endpoint is not bounded');
four_expect(!str_contains($topupDone, "admin_topup_read_bucket('DONE')"), 'Top-Up DONE still loads the full bucket');
four_expect(str_contains($dashboardJs, 'topupPagination: {') && str_contains($dashboardJs, 'currentTopupPagination()'), 'Top-Up tabs do not keep separate cursor state');

foreach (['ACTIVE', 'REVIEW', 'REJECTED', 'BLOCKED_INACTIVE', 'ALL'] as $status) {
    four_expect(str_contains($dashboard, 'data-user-status="' . $status . '"'), 'Missing Users status filter: ' . $status);
}
four_expect(str_contains($dashboardJs, 'usersRequestSerial'), 'Users filter responses are not protected from stale request races');
four_expect(strpos($users, '$matchesUser') < strpos($users, "admin_firebase_cursor_page(\n        'USERS'"), 'Users filters must run before pagination');
four_expect(str_contains($proxy, "'role' => trim((string)(\$_GET['role'] ?? ''))") && str_contains($proxy, "'status' => trim((string)(\$_GET['status'] ?? ''))"), 'Users proxy does not preserve role/status filters');
four_expect(admin_users_list_matches(['role' => 'USER', 'status' => 'REVIEW'], 'U_REVIEW', 'USER', 'REVIEW', 'review'), 'Review + USER + search filter failed');
four_expect(!admin_users_list_matches(['role' => 'ADMIN', 'status' => 'ACTIVE'], 'A1', 'USER', 'ALL', ''), 'Role filter admitted an ADMIN as USER');
four_expect(admin_users_list_matches(['role' => 'ADMIN', 'status' => 'REJECTED'], 'A2', 'ADMIN', 'ALL', ''), 'All + ADMIN filter failed');
four_expect(admin_users_list_matches(['role' => 'USER', 'status' => 'INACTIVE', 'account_status' => 'ACTIVE'], 'U_INACTIVE', '', 'BLOCKED_INACTIVE', ''), 'Inactive status was hidden by a stale ACTIVE account_status');

four_expect(str_contains($mfsJs, "get('mfs_status_counts'"), 'MFS summary does not use the exact counter endpoint');
four_expect(!str_contains($mfsJs, "String(count)+'+'"), 'MFS summary still renders approximate counts');
four_expect(str_contains($proxy, "case 'mfs_status_counts':"), 'MFS summary proxy action is missing');

foreach ($queries as $query) {
    if (($query['path'] ?? '') !== 'TOPUP_REQUESTS/DONE') {
        continue;
    }
    four_expect(($query['query'] ?? []) !== [], 'Top-Up DONE performed a full-root read');
    four_expect((int)($query['query']['limitToLast'] ?? 0) <= 11, 'Top-Up DONE query exceeded the bounded candidate size');
}

echo "Admin four-issue regression tests passed ({$assertions} assertions).\n";
