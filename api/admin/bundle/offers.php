<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/bundle.php';
require_once dirname(__DIR__, 2) . '/lib/admin_pagination.php';

api_require_method('GET');
auth_require_admin_session(true);

/*
|--------------------------------------------------------------------------
| Bundle Offers List
|--------------------------------------------------------------------------
| কাজ:
| 1) Expired offer auto-expire করবে, delete করবে না।
| 2) Deleted offer normal list এ show করবে না।
| 3) Expired offer list এ থাকবে, যাতে admin edit করে আবার ACTIVE করতে পারে।
| 4) status filter support: ACTIVE / INACTIVE / EXPIRED / DELETED
| 5) include_deleted=1 দিলে only admin/debug/delete-management use case এ deleted দেখা যাবে।
*/

function admin_bundle_offer_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null) {
        return $default;
    }

    $s = strtoupper(trim((string)$value));

    if (in_array($s, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true)) {
        return true;
    }

    if (in_array($s, ['0', 'FALSE', 'NO', 'OFF', 'INACTIVE', 'DISABLED'], true)) {
        return false;
    }

    return $default;
}

function admin_bundle_offer_now(): int
{
    if (function_exists('bundle_now')) {
        return (int)bundle_now();
    }

    if (function_exists('now_ts')) {
        return (int)now_ts();
    }

    return time();
}

function admin_bundle_offer_is_deleted(array $row): bool
{
    $status = strtoupper(trim((string)($row['status'] ?? '')));

    if (in_array($status, ['DELETED', 'REMOVED', 'TRASHED'], true)) {
        return true;
    }

    if (admin_bundle_offer_bool($row['deleted'] ?? false, false)) {
        return true;
    }

    if (admin_bundle_offer_bool($row['is_deleted'] ?? false, false)) {
        return true;
    }

    if ((int)($row['deleted_at'] ?? 0) > 0) {
        return true;
    }

    return false;
}

function admin_bundle_offer_normal_status(array $row, int $now): string
{
    if (admin_bundle_offer_is_deleted($row)) {
        return 'DELETED';
    }

    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
    $active = array_key_exists('active', $row)
        ? admin_bundle_offer_bool($row['active'], true)
        : true;

    $expiredFlag = admin_bundle_offer_bool($row['expired'] ?? false, false);

    $expiresAt = (int)(
        $row['expires_at']
        ?? $row['expire_at']
        ?? 0
    );

    if ($status === 'EXPIRED' || $expiredFlag || ($expiresAt > 0 && $expiresAt <= $now)) {
        return 'EXPIRED';
    }

    if ($status === 'INACTIVE' || !$active) {
        return 'INACTIVE';
    }

    if ($status === '') {
        return $active ? 'ACTIVE' : 'INACTIVE';
    }

    return $status;
}

function admin_bundle_offer_with_normal_fields(array $row, int $now): array
{
    $status = admin_bundle_offer_normal_status($row, $now);

    $row['status'] = $status;
    $row['active'] = $status === 'ACTIVE';
    $row['expired'] = $status === 'EXPIRED';
    $row['deleted'] = $status === 'DELETED';

    return $row;
}

$includeInactiveRaw = strtolower(trim((string)($_GET['include_inactive'] ?? '1')));
$includeInactive = !in_array($includeInactiveRaw, ['0', 'false', 'no', 'off'], true);

$includeDeletedRaw = strtolower(trim((string)($_GET['include_deleted'] ?? '0')));
$includeDeleted = in_array($includeDeletedRaw, ['1', 'true', 'yes', 'on'], true);

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
$searchQuery = strtolower(trim((string)($_GET['query'] ?? '')));
$cursor = trim((string)($_GET['cursor'] ?? ''));
$pageNumber = max(1, (int)($_GET['page'] ?? 1));
$allowedStatusFilters = ['', 'ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'];

if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid status filter', [
        'allowed_status' => ['ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'],
    ], 422);
}

$now = admin_bundle_offer_now();
$page = admin_firebase_cursor_page(
    'BUNDLE_OFFERS',
    10,
    $cursor,
    static function (array $row, string $offerId) use (
        $now,
        $includeInactive,
        $includeDeleted,
        $statusFilter,
        $searchQuery
    ): bool {
        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
        $row = admin_bundle_offer_with_normal_fields($row, $now);
        $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));

        if ($status === 'DELETED' && !$includeDeleted && $statusFilter !== 'DELETED') {
            return false;
        }
        if (!$includeInactive && $status !== 'ACTIVE') {
            return false;
        }
        if ($statusFilter !== '' && $status !== $statusFilter) {
            return false;
        }
        if ($searchQuery !== '') {
            $haystack = strtolower(implode(' ', [
                $row['offer_id'] ?? '',
                $row['bundle_name'] ?? '',
                $row['package_name'] ?? '',
                $row['plan_name'] ?? '',
                $row['name'] ?? '',
                $row['operator'] ?? '',
                $row['status'] ?? '',
            ]));
            if (!str_contains($haystack, $searchQuery)) {
                return false;
            }
        }

        return true;
    },
    static function (array $row, string $offerId) use ($now): array {
        $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
        return admin_bundle_offer_with_normal_fields($row, $now);
    }
);

$visibleItems = (array)($page['items'] ?? []);
$pagination = (array)($page['pagination'] ?? []);
$pagination['page'] = $pageNumber;
$summary = [
    'total' => count($visibleItems),
    'visible_total' => count($visibleItems),
    'active' => count(array_filter($visibleItems, static fn(array $row): bool => ($row['status'] ?? '') === 'ACTIVE')),
    'inactive' => count(array_filter($visibleItems, static fn(array $row): bool => ($row['status'] ?? '') === 'INACTIVE')),
    'expired' => count(array_filter($visibleItems, static fn(array $row): bool => ($row['status'] ?? '') === 'EXPIRED')),
    'deleted' => count(array_filter($visibleItems, static fn(array $row): bool => ($row['status'] ?? '') === 'DELETED')),
    'bounded' => true,
];

api_response(true, 'SUCCESS', 'Bundle offers loaded', [
    'items' => array_values($visibleItems),
    'pagination' => $pagination,
    'summary' => $summary,
    'filters' => [
        'include_inactive' => $includeInactive,
        'include_deleted' => $includeDeleted,
        'status' => $statusFilter,
        'query' => $searchQuery,
    ],
]);
