<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

function admin_users_list_currency(array $user, array $wallet, string $country): string
{
    $country = auth_normalize_country_code($country);
    if ($country !== '') {
        return $country === 'MY' ? 'MYR' : 'BDT';
    }

    foreach ([
        $wallet['wallet_currency'] ?? '',
        $wallet['currency'] ?? '',
        $user['wallet_currency'] ?? '',
        $user['currency'] ?? '',
    ] as $candidate) {
        $currency = strtoupper(trim((string)$candidate));

        if (in_array($currency, ['MYR', 'RM', 'RINGGIT'], true)) {
            return 'MYR';
        }

        if (in_array($currency, ['BDT', 'TK', 'TAKA'], true)) {
            return 'BDT';
        }
    }

    return 'BDT';
}

function admin_users_list_country_code(
    array $user,
    array $wallet,
    array $users,
    array $wallets
): string {
    foreach ([
        $user['pricing_country'] ?? '',
        $user['market_country'] ?? '',
        $user['service_country'] ?? '',
    ] as $candidate) {
        $country = auth_normalize_country_code((string)$candidate);
        if ($country !== '') {
            return $country;
        }
    }

    $currency = admin_users_list_currency($user, $wallet, '');
    if ($currency === 'MYR') {
        return 'MY';
    }

    foreach ([
        $user['ip_country'] ?? '',
        $user['registration_country'] ?? '',
        $user['created_ip_country'] ?? '',
    ] as $candidate) {
        $country = auth_normalize_country_code((string)$candidate);
        if ($country !== '') {
            return $country;
        }
    }

    $parentUid = trim((string)($user['parent_subadmin_uid'] ?? ''));
    if (
        $parentUid === ''
        && strtoupper(trim((string)($user['created_by_role'] ?? ''))) === 'SUBADMIN'
    ) {
        $parentUid = trim((string)($user['created_by_uid'] ?? ''));
    }

    if ($parentUid !== '' && is_array($users[$parentUid] ?? null)) {
        $parent = $users[$parentUid];
        $parentWallet = is_array($wallets[$parentUid] ?? null) ? $wallets[$parentUid] : [];

        foreach ([
            $parent['pricing_country'] ?? '',
            $parent['market_country'] ?? '',
            $parent['service_country'] ?? '',
            $parent['country_code'] ?? '',
            $parent['country'] ?? '',
        ] as $candidate) {
            $country = auth_normalize_country_code((string)$candidate);
            if ($country !== '') {
                return $country;
            }
        }

        if (admin_users_list_currency($parent, $parentWallet, '') === 'MYR') {
            return 'MY';
        }
    }

    foreach ([
        $user['country_code'] ?? '',
        $user['country'] ?? '',
        $user['user_country'] ?? '',
    ] as $candidate) {
        $country = auth_normalize_country_code((string)$candidate);
        if ($country !== '') {
            return $country;
        }
    }

    return 'BD';
}

api_require_method('GET');
auth_require_admin_session();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$search = strtolower(trim((string)($_GET['search'] ?? '')));
$roleFilter = strtoupper(trim((string)($_GET['role'] ?? '')));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));

$users = fb_get('USERS');
$wallets = fb_get('USER_WALLETS');

$users = is_array($users) ? $users : [];
$wallets = is_array($wallets) ? $wallets : [];
$items = [];
$totalAvailableBalance = 0.0;

foreach ($users as $uid => $user) {
    if (!is_array($user)) {
        continue;
    }

    $uid = (string)$uid;
    $wallet = is_array($wallets[$uid] ?? null) ? $wallets[$uid] : [];
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'ACTIVE')));
    $accountStatus = strtoupper(trim((string)($user['account_status'] ?? $status)));

    if ($roleFilter !== '' && $role !== $roleFilter) {
        continue;
    }

    if ($statusFilter !== '' && $status !== $statusFilter) {
        continue;
    }

    $name = trim((string)($user['name'] ?? ''));
    $phone = trim((string)($user['phone'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));

    if ($search !== '') {
        $haystack = strtolower(implode(' ', [$uid, $name, $phone, $email, $role, $status]));
        if (!str_contains($haystack, $search)) {
            continue;
        }
    }

    $country = admin_users_list_country_code($user, $wallet, $users, $wallets);
    $currency = admin_users_list_currency($user, $wallet, $country);
    $availableBalance = (float)($wallet['available_balance'] ?? 0);
    $holdBalance = (float)($wallet['hold_balance'] ?? 0);
    $phoneCountry = auth_phone_country_from_user($user);

    $totalAvailableBalance += $availableBalance;

    $items[] = [
        'uid' => $uid,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        'account_status' => $accountStatus,
        'phone_country' => $phoneCountry,
        'pricing_country' => $country,
        'market_country' => $country,
        'service_country' => $country,
        'country_code' => $country,
        'country' => $country,
        'ip_country' => function_exists('market_iso_country_code')
            ? market_iso_country_code($user['ip_country'] ?? '')
            : strtoupper(trim((string)($user['ip_country'] ?? ''))),
        'gps_country' => strtoupper(trim((string)($user['gps_country'] ?? ''))),
        'country_mismatch' => array_key_exists('country_mismatch', $user)
            ? (bool)$user['country_mismatch']
            : $phoneCountry !== $country,
        'vpn_suspected' => (bool)($user['vpn_suspected'] ?? false),
        'market_detection_source' => (string)($user['market_detection_source'] ?? ''),
        'account_review_reason' => (string)($user['account_review_reason'] ?? ''),
        'available_balance' => $availableBalance,
        'hold_balance' => $holdBalance,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'display_currency' => $currency,
        'display_available_balance' => $availableBalance,
        'display_hold_balance' => $holdBalance,
        'created_at' => (int)($user['created_at'] ?? 0),
        'last_login_at' => (int)($user['last_login_at'] ?? 0),
        'last_login' => (int)($user['last_login_at'] ?? 0),
    ];
}

usort($items, static function (array $a, array $b): int {
    return (int)$b['created_at'] <=> (int)$a['created_at'];
});

$total = count($items);
$totalPages = max(1, (int)ceil($total / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$pageItems = array_values(array_slice($items, $offset, $limit));

api_response(true, 'SUCCESS', 'User list loaded', [
    'items' => $pageItems,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
        'has_more' => $page < $totalPages,
    ],
    'summary' => [
        'total_users' => $total,
        'total_available_balance' => round($totalAvailableBalance, 2),
    ],
]);
