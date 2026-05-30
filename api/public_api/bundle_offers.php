<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/subadmin_api.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

api_require_method('GET');

$auth = subapi_authenticate_request();

$uid = trim((string)($auth['uid'] ?? ''));
$user = (array)($auth['user'] ?? []);
$roleSettings = (array)($auth['role_settings'] ?? []);

if ($uid === '') {
    api_response(false, 'AUTH_ERROR', 'Invalid authenticated API user', [], 401);
}

$bundleEnabled = (bool)($roleSettings['bundle_enabled'] ?? false);
if (!$bundleEnabled) {
    api_response(false, 'BUNDLE_DISABLED', 'Bundle is disabled for this account', [], 403);
}

$operatorFilter = strtoupper(trim((string)($_GET['operator'] ?? '')));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'ACTIVE')));

$items = [];

if (function_exists('bundle_list_visible_offers_for_user')) {
    $items = bundle_list_visible_offers_for_user($uid);
} elseif (function_exists('bundle_admin_list_offers')) {
    $items = bundle_admin_list_offers(false);
} else {
    api_response(false, 'SERVER_ERROR', 'Bundle offer helper missing', [], 500);
}

$out = [];

foreach ($items as $row) {
    if (!is_array($row)) {
        continue;
    }

    $status = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
    $operator = strtoupper(trim((string)($row['operator'] ?? '')));

    if ($statusFilter !== '' && $statusFilter !== 'ALL' && $status !== $statusFilter) {
        continue;
    }

    if ($operatorFilter !== '' && $operator !== $operatorFilter) {
        continue;
    }

    $expiresAt = (int)($row['expires_at'] ?? 0);
    $now = function_exists('now_ts') ? now_ts() : time();

    if ($expiresAt > 0 && $expiresAt <= $now) {
        continue;
    }

    if (empty($row['active']) || $status !== 'ACTIVE') {
        continue;
    }

    $amount = round((float)($row['amount'] ?? 0), 2);
    $adminCommission = round((float)($row['admin_commission'] ?? 0), 2);
    $userCommission = round((float)($row['user_commission'] ?? 0), 2);
    $subadminProfit = round((float)($row['subadmin_profit'] ?? 0), 2);
    $netCost = round((float)($row['net_cost_after_commission'] ?? $amount), 2);

    $out[] = [
        'offer_id' => (string)($row['offer_id'] ?? ''),
        'operator' => $operator,
        'bundle_name' => (string)($row['bundle_name'] ?? $row['name'] ?? ''),
        'name' => (string)($row['name'] ?? $row['bundle_name'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'amount' => $amount,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'net_cost_after_commission' => $netCost,
        'duration_value' => (float)($row['duration_value'] ?? 0),
        'duration_unit' => (string)($row['duration_unit'] ?? ''),
        'duration_seconds' => (int)($row['duration_seconds'] ?? 0),
        'expires_at' => $expiresAt,
        'status' => $status,
        'active' => true,
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

usort($out, static function (array $a, array $b): int {
    return (int)($b['updated_at'] ?? $b['created_at'] ?? 0) <=> (int)($a['updated_at'] ?? $a['created_at'] ?? 0);
});

api_response(true, 'SUCCESS', 'Bundle offers loaded successfully', [
    'uid' => $uid,
    'total' => count($out),
    'items' => array_values($out),
]);