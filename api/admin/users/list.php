<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/admin_pagination.php';
require_once dirname(__DIR__, 2) . '/lib/admin_user_filters.php';

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

    if ($parentUid !== '') {
        $parent = is_array($users[$parentUid] ?? null)
            ? $users[$parentUid]
            : (array)(fb_get('USERS/' . $parentUid) ?: []);
        $parentWallet = is_array($wallets[$parentUid] ?? null)
            ? $wallets[$parentUid]
            : (array)(fb_get('USER_WALLETS/' . $parentUid) ?: []);

        if ($parent !== []) {
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

function admin_users_list_email_index_keys(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $legacyKey = str_replace(
        ['.', '#', '$', '[', ']', '/'],
        [',', '_', '_', '(', ')', '_'],
        $email
    );

    return array_values(array_unique([
        md5($email),
        $legacyKey,
    ]));
}

function admin_users_list_uid_from_index(mixed $row): string
{
    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function admin_users_list_lookup_search_uids(string $search): array
{
    $search = trim($search);
    if ($search === '') {
        return [];
    }

    $uids = [];

    $directUser = fb_get('USERS/' . $search);
    if (is_array($directUser)) {
        $uids[] = $search;
    }

    if (filter_var($search, FILTER_VALIDATE_EMAIL)) {
        foreach (admin_users_list_email_index_keys($search) as $emailKey) {
            $uid = admin_users_list_uid_from_index(fb_get('USER_INDEX/EMAIL/' . $emailKey));
            if ($uid !== '') {
                $uids[] = $uid;
            }
        }
    }

    foreach (['BD', 'MY'] as $country) {
        $phone = normalize_phone_by_country($search, $country);
        if ($phone === '') {
            continue;
        }

        $uid = auth_find_uid_by_phone_country($phone, $country);
        if ($uid !== '') {
            $uids[] = $uid;
        }
    }

    return array_values(array_unique(array_filter($uids)));
}

function admin_users_list_shallow_keys(): array
{
    $rows = fb_get('USERS', ['shallow' => 'true']);
    if (!is_array($rows)) {
        return [];
    }

    $keys = array_keys($rows);
    $keys = array_values(array_filter(array_map('strval', $keys), static fn(string $key): bool => $key !== ''));
    rsort($keys, SORT_STRING);

    return $keys;
}

function admin_users_list_multi_get(array $paths): array
{
    $paths = array_values(array_unique(array_filter(array_map(
        static fn($path): string => trim((string)$path, '/'),
        $paths
    ))));

    if ($paths === []) {
        return [];
    }

    $multi = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($paths as $path) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => fb_build_url($path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        curl_multi_add_handle($multi, $ch);
        $handles[spl_object_id($ch)] = [$path, $ch];
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as [$path, $ch]) {
        $raw = curl_multi_getcontent($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode >= 200 && $statusCode < 300 && is_string($raw) && $raw !== '' && $raw !== 'null') {
            $decoded = json_decode($raw, true);
            $results[$path] = $decoded;
        } else {
            $results[$path] = null;
        }

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);

    return $results;
}

function admin_users_list_make_item(string $uid, array $user, array $wallet): array
{
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'ACTIVE'))) ?: 'ACTIVE';
    $accountStatus = admin_users_list_account_status($user);
    $country = admin_users_list_country_code($user, $wallet, [], []);
    $currency = admin_users_list_currency($user, $wallet, $country);
    $availableBalance = (float)($wallet['available_balance'] ?? 0);
    $holdBalance = (float)($wallet['hold_balance'] ?? 0);
    $phoneCountry = auth_phone_country_from_user($user);

    return [
        'uid' => $uid,
        'name' => trim((string)($user['name'] ?? '')),
        'phone' => trim((string)($user['phone'] ?? '')),
        'email' => trim((string)($user['email'] ?? '')),
        'role' => $role,
        'status' => $status,
        'account_status' => $accountStatus,
        'review_required' => (bool)($user['review_required'] ?? $user['requires_admin_review'] ?? ($accountStatus === 'REVIEW')),
        'phone_country' => $phoneCountry,
        'pricing_country' => $country,
        'market_country' => $country,
        'service_country' => $country,
        'country_code' => $country,
        'country' => $country,
        'ip_country' => (function ($value): string {
            $raw = strtoupper(trim((string)$value));
            $country = function_exists('market_iso_country_code') ? market_iso_country_code($raw) : $raw;
            return $country !== '' ? $country : ($raw === 'UNKNOWN' ? 'UNKNOWN' : '');
        })($user['ip_country'] ?? ''),
        'ip_source' => (string)($user['ip_source'] ?? ''),
        'gps_country' => strtoupper(trim((string)($user['gps_country'] ?? ''))),
        'country_mismatch' => array_key_exists('country_mismatch', $user)
            ? (bool)$user['country_mismatch']
            : market_gps_ip_country_mismatch(
                $user['gps_country'] ?? '',
                $user['ip_country'] ?? ''
            ),
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

api_require_method('GET');
auth_require_admin_session();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$cursor = trim((string)($_GET['cursor'] ?? ''));
$searchRaw = trim((string)($_GET['search'] ?? ''));
$search = strtolower($searchRaw);
$roleFilter = strtoupper(trim((string)($_GET['role'] ?? '')));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'ACTIVE')));
$statusFilter = $statusFilter === '' ? 'ACTIVE' : $statusFilter;
$allowedStatuses = ['ACTIVE', 'REVIEW', 'REJECTED', 'BLOCKED_INACTIVE', 'ALL'];
$allowedRoles = ['', 'USER', 'RETAILER', 'SUBADMIN', 'ADMIN'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid user status filter', [
        'allowed_status' => $allowedStatuses,
    ], 422);
}
if (!in_array($roleFilter, $allowedRoles, true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid user role filter', [
        'allowed_role' => $allowedRoles,
    ], 422);
}

$items = [];
$totalAvailableBalance = 0.0;
$searchMode = $search !== '' ? 'bounded_name_scan' : 'cursor';
$directSearchKeys = $search !== '' ? admin_users_list_lookup_search_uids($searchRaw) : [];

$matchesUser = static function (array $user, string $uid) use ($roleFilter, $statusFilter, $search): bool {
    return admin_users_list_matches($user, $uid, $roleFilter, $statusFilter, $search);
};

if ($directSearchKeys !== []) {
    rsort($directSearchKeys, SORT_STRING);
    $directRows = admin_users_list_multi_get(array_map(
        static fn(string $uid): string => 'USERS/' . $uid,
        $directSearchKeys
    ));
    $pageUserRows = [];
    foreach ($directSearchKeys as $uid) {
        $row = $directRows['USERS/' . $uid] ?? null;
        if (is_array($row) && $matchesUser($row, $uid)) {
            $row['_admin_uid'] = $uid;
            $pageUserRows[] = $row;
        }
    }
    $pageData = [
        'items' => array_slice($pageUserRows, 0, $limit),
        'pagination' => [
            'limit' => $limit,
            'count' => min($limit, count($pageUserRows)),
            'has_more' => count($pageUserRows) > $limit,
            'cursor' => '',
            'next_cursor' => '',
            'scanned' => count($directSearchKeys),
            'scan_limited' => false,
        ],
    ];
    $searchMode = 'index';
} else {
    $pageData = admin_firebase_cursor_page(
        'USERS',
        $limit,
        $cursor,
        $matchesUser,
        static function (array $user, string $uid): array {
            $user['_admin_uid'] = $uid;
            return $user;
        }
    );
}

$pageUserRows = (array)($pageData['items'] ?? []);
$pageKeys = array_values(array_filter(array_map(
    static fn(array $row): string => trim((string)($row['_admin_uid'] ?? $row['uid'] ?? '')),
    $pageUserRows
)));
$pageWallets = admin_users_list_multi_get(array_map(
    static fn(string $uid): string => 'USER_WALLETS/' . $uid,
    $pageKeys
));

foreach ($pageUserRows as $user) {
    $uid = trim((string)($user['_admin_uid'] ?? $user['uid'] ?? ''));
    if ($uid === '') {
        continue;
    }
    $walletPath = 'USER_WALLETS/' . $uid;
    unset($user['_admin_uid']);
    $wallet = is_array($pageWallets[$walletPath] ?? null) ? $pageWallets[$walletPath] : [];
    $item = admin_users_list_make_item($uid, $user, $wallet);
    $totalAvailableBalance += (float)$item['available_balance'];
    $items[] = $item;
}

$pagination = (array)($pageData['pagination'] ?? []);
$pagination['page'] = $page;
$pagination['total'] = null;
$pagination['total_pages'] = null;

api_response(true, 'SUCCESS', 'User list loaded', [
    'items' => array_values($items),
    'pagination' => $pagination,
    'summary' => [
        'total_users' => null,
        'page_count' => count($items),
        'total_available_balance' => round($totalAvailableBalance, 2),
        'search_mode' => $searchMode,
        'search_limited' => !empty($pagination['scan_limited']),
        'status' => $statusFilter,
    ],
]);
