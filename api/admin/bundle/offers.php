<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/bundle.php';

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

/*
|--------------------------------------------------------------------------
| First expire old offers in Firebase, if bundle.php supports it.
|--------------------------------------------------------------------------
| এটা delete না, শুধু expired status করবে।
*/
if (function_exists('bundle_expire_old_offers')) {
    bundle_expire_old_offers();
}

$includeInactiveRaw = strtolower(trim((string)($_GET['include_inactive'] ?? '1')));
$includeInactive = !in_array($includeInactiveRaw, ['0', 'false', 'no', 'off'], true);

$includeDeletedRaw = strtolower(trim((string)($_GET['include_deleted'] ?? '0')));
$includeDeleted = in_array($includeDeletedRaw, ['1', 'true', 'yes', 'on'], true);

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
$allowedStatusFilters = ['', 'ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'];

if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid status filter', [
        'allowed_status' => ['ACTIVE', 'INACTIVE', 'EXPIRED', 'DELETED'],
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Load offers
|--------------------------------------------------------------------------
*/
$items = [];

if (function_exists('bundle_admin_list_offers')) {
    $items = bundle_admin_list_offers(true);
} else {
    $raw = fb_get('BUNDLE_OFFERS');
    if (is_array($raw)) {
        foreach ($raw as $offerId => $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['offer_id'] = (string)($row['offer_id'] ?? $offerId);
            $items[] = $row;
        }
    }
}

if (!is_array($items)) {
    $items = [];
}

$now = admin_bundle_offer_now();

$summary = [
    'total' => 0,
    'visible_total' => 0,
    'active' => 0,
    'inactive' => 0,
    'expired' => 0,
    'deleted' => 0,
];

$visibleItems = [];

foreach ($items as $row) {
    if (!is_array($row)) {
        continue;
    }

    $row = admin_bundle_offer_with_normal_fields($row, $now);

    $offerId = trim((string)($row['offer_id'] ?? $row['id'] ?? ''));
    if ($offerId === '') {
        continue;
    }

    $row['offer_id'] = $offerId;

    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));

    $summary['total']++;

    if ($status === 'DELETED') {
        $summary['deleted']++;
    } elseif ($status === 'EXPIRED') {
        $summary['expired']++;
    } elseif ($status === 'ACTIVE') {
        $summary['active']++;
    } else {
        $summary['inactive']++;
    }

    /*
     * Deleted offer normal list এ show করবে না।
     * শুধু include_deleted=1 অথবা status=DELETED দিলে দেখা যাবে।
     */
    if ($status === 'DELETED' && !$includeDeleted && $statusFilter !== 'DELETED') {
        continue;
    }

    /*
     * include_inactive=0 হলে only ACTIVE offer show করবে।
     * Expired offer edit করার জন্য default include_inactive=1 রাখা হয়েছে।
     */
    if (!$includeInactive && $status !== 'ACTIVE') {
        continue;
    }

    if ($statusFilter !== '' && $status !== $statusFilter) {
        continue;
    }

    $visibleItems[] = $row;
}

usort($visibleItems, static function (array $a, array $b): int {
    $aStatus = strtoupper(trim((string)($a['status'] ?? '')));
    $bStatus = strtoupper(trim((string)($b['status'] ?? '')));

    $rank = [
        'ACTIVE' => 1,
        'INACTIVE' => 2,
        'EXPIRED' => 3,
        'DELETED' => 4,
    ];

    $aRank = $rank[$aStatus] ?? 9;
    $bRank = $rank[$bStatus] ?? 9;

    if ($aRank !== $bRank) {
        return $aRank <=> $bRank;
    }

    $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['created_at'] ?? 0));
    $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['created_at'] ?? 0));

    return $bTime <=> $aTime;
});

$summary['visible_total'] = count($visibleItems);

api_response(true, 'SUCCESS', 'Bundle offers loaded', [
    'items' => array_values($visibleItems),
    'summary' => $summary,
    'filters' => [
        'include_inactive' => $includeInactive,
        'include_deleted' => $includeDeleted,
        'status' => $statusFilter,
    ],
]);