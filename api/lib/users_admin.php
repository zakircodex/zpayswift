<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function admin_users_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function admin_users_role_default(string $role = 'USER'): array
{
    if (function_exists('role_default_settings')) {
        $row = role_default_settings($role);
        if (is_array($row)) {
            return $row;
        }
    }

    return [
        'commission_per_1000' => 0,
        'api_enabled' => false,
        'topup_enabled' => true,
        'bundle_enabled' => false,
        'min_amount' => 20,
        'max_amount' => 500,
        'updated_at' => admin_users_now(),
    ];
}

function admin_users_default_retailer_settings(): array
{
    $row = admin_users_role_default('RETAILER');

    $row['commission_per_1000'] = 20;
    $row['api_enabled'] = false;
    $row['topup_enabled'] = true;
    $row['bundle_enabled'] = true;
    $row['min_amount'] = (float)($row['min_amount'] ?? 20);
    $row['max_amount'] = (float)($row['max_amount'] ?? 2000);
    $row['updated_at'] = admin_users_now();

    return $row;
}

function admin_users_load_user(string $uid): array
{
    $row = fb_get('USERS/' . $uid);
    return is_array($row) ? $row : [];
}

function admin_users_load_wallet(string $uid): array
{
    $row = fb_get('USER_WALLETS/' . $uid);
    return is_array($row) ? $row : [];
}

function admin_users_load_role_settings(string $uid, ?string $role = null): array
{
    $row = fb_get('USER_ROLE_SETTINGS/' . $uid);
    if (is_array($row)) {
        return $row;
    }

    return admin_users_role_default($role ?: 'USER');
}

function admin_users_normalize_role(?string $role): string
{
    $role = strtoupper(trim((string)$role));
    return in_array($role, ['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'], true) ? $role : 'USER';
}

function admin_users_normalize_status(?string $status): string
{
    $status = strtoupper(trim((string)$status));
    return in_array($status, ['ACTIVE', 'INACTIVE', 'DISABLED'], true) ? $status : 'ACTIVE';
}

function admin_users_find_user_by_uid(string $uid): array
{
    $user = admin_users_load_user($uid);
    if (!$user) {
        return [];
    }

    $role = admin_users_normalize_role((string)($user['role'] ?? 'USER'));
    $wallet = admin_users_load_wallet($uid);
    $roleSettings = admin_users_load_role_settings($uid, $role);

    return [
        'uid' => (string)($user['uid'] ?? $uid),
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => $role,
        'status' => admin_users_normalize_status((string)($user['status'] ?? 'ACTIVE')),
        'created_at' => (int)($user['created_at'] ?? 0),
        'updated_at' => (int)($user['updated_at'] ?? 0),
        'last_login_at' => (int)($user['last_login_at'] ?? 0),
        'created_by_admin' => (bool)($user['created_by_admin'] ?? false),
        'parent_subadmin_uid' => (string)($user['parent_subadmin_uid'] ?? ''),
        'created_by_uid' => (string)($user['created_by_uid'] ?? ''),
        'created_by_role' => (string)($user['created_by_role'] ?? ''),
        'register_source' => (string)($user['register_source'] ?? ''),
        'converted_to_retailer_at' => (int)($user['converted_to_retailer_at'] ?? 0),
        'converted_to_retailer_by' => (string)($user['converted_to_retailer_by'] ?? ''),
        'wallet' => [
            'available_balance' => (float)($wallet['available_balance'] ?? 0),
            'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
            'total_topup_spent' => (float)($wallet['total_topup_spent'] ?? 0),
            'total_bundle_spent' => (float)($wallet['total_bundle_spent'] ?? 0),
            'total_refund' => (float)($wallet['total_refund'] ?? 0),
            'updated_at' => (int)($wallet['updated_at'] ?? 0),
        ],
        'role_settings' => [
            'commission_per_1000' => (float)($roleSettings['commission_per_1000'] ?? 0),
            'api_enabled' => (bool)($roleSettings['api_enabled'] ?? false),
            'topup_enabled' => (bool)($roleSettings['topup_enabled'] ?? false),
            'bundle_enabled' => (bool)($roleSettings['bundle_enabled'] ?? false),
            'min_amount' => (float)($roleSettings['min_amount'] ?? 0),
            'max_amount' => (float)($roleSettings['max_amount'] ?? 0),
            'updated_at' => (int)($roleSettings['updated_at'] ?? 0),
        ],
    ];
}

function admin_users_is_convertible_to_retailer(array $user): array
{
    if (!$user) {
        return [false, 'User not found'];
    }

    $role = admin_users_normalize_role((string)($user['role'] ?? 'USER'));
    $status = admin_users_normalize_status((string)($user['status'] ?? 'ACTIVE'));

    if ($status !== 'ACTIVE') {
        return [false, 'Only active user can be converted'];
    }

    if ($role === 'RETAILER') {
        return [false, 'User is already a retailer'];
    }

    if ($role !== 'USER') {
        return [false, 'Only normal user can be converted to retailer'];
    }

    return [true, 'OK'];
}

function admin_users_actor_can_access_user(array $user, string $actorUid, string $actorRole): bool
{
    $actorUid = trim($actorUid);
    $actorRole = admin_users_normalize_role($actorRole);

    if ($actorRole === 'ADMIN') {
        return true;
    }

    if ($actorRole === 'SUBADMIN') {
        $parentSubadminUid = trim((string)($user['parent_subadmin_uid'] ?? ''));
        return $parentSubadminUid !== '' && $parentSubadminUid === $actorUid;
    }

    return false;
}

function admin_users_convert_to_retailer(string $uid, string $actorUid = '', string $actorRole = 'ADMIN'): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'User ID is required',
        ];
    }

    $actorUid = trim($actorUid);
    $actorRole = admin_users_normalize_role($actorRole);

    if (!in_array($actorRole, ['ADMIN', 'SUBADMIN'], true)) {
        return [
            'ok' => false,
            'code' => 'FORBIDDEN',
            'message' => 'Only ADMIN or SUBADMIN can convert user',
        ];
    }

    $user = admin_users_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'User not found',
        ];
    }

    if (!admin_users_actor_can_access_user($user, $actorUid, $actorRole)) {
        return [
            'ok' => false,
            'code' => 'FORBIDDEN',
            'message' => 'You can only convert your own users',
        ];
    }

    [$canConvert, $reason] = admin_users_is_convertible_to_retailer($user);
    if (!$canConvert) {
        return [
            'ok' => false,
            'code' => 'NOT_ALLOWED',
            'message' => $reason,
        ];
    }

    $now = admin_users_now();
    $retailerSettings = admin_users_default_retailer_settings();

    $userPatch = [
        'role' => 'RETAILER',
        'updated_at' => $now,
        'converted_to_retailer_at' => $now,
        'converted_to_retailer_by' => $actorUid,
    ];

    $settingsPatch = [
        'commission_per_1000' => (float)($retailerSettings['commission_per_1000'] ?? 0),
        'api_enabled' => false,
        'topup_enabled' => (bool)($retailerSettings['topup_enabled'] ?? true),
        'bundle_enabled' => (bool)($retailerSettings['bundle_enabled'] ?? false),
        'min_amount' => (float)($retailerSettings['min_amount'] ?? 20),
        'max_amount' => (float)($retailerSettings['max_amount'] ?? 1000),
        'updated_at' => $now,
    ];

    $okUser = fb_patch('USERS/' . $uid, $userPatch);
    if (!$okUser) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to update user role',
        ];
    }

    $okSettings = fb_patch('USER_ROLE_SETTINGS/' . $uid, $settingsPatch);
    if (!$okSettings) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to update retailer settings',
        ];
    }

    if (function_exists('system_log')) {
        system_log('USER_CONVERT_RETAILER', $uid, 'User converted to retailer', [
            'uid' => $uid,
            'actor_uid' => $actorUid,
            'actor_role' => $actorRole,
            'phone' => (string)($user['phone'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'User converted to retailer successfully',
        'data' => admin_users_find_user_by_uid($uid),
    ];
}

function admin_users_list_users(
    string $roleFilter = '',
    string $statusFilter = '',
    int $limit = 300,
    string $actorUid = '',
    string $actorRole = 'ADMIN'
): array {
    $all = fb_get('USERS');
    if (!is_array($all)) {
        return [];
    }

    $actorUid = trim($actorUid);
    $actorRole = admin_users_normalize_role($actorRole);
    $roleFilter = strtoupper(trim($roleFilter));
    $statusFilter = strtoupper(trim($statusFilter));

    $items = [];

    foreach ($all as $uid => $row) {
        if (!is_array($row)) {
            continue;
        }

        $role = admin_users_normalize_role((string)($row['role'] ?? 'USER'));
        $status = admin_users_normalize_status((string)($row['status'] ?? 'ACTIVE'));

        if ($roleFilter !== '' && $role !== $roleFilter) {
            continue;
        }

        if ($statusFilter !== '' && $status !== $statusFilter) {
            continue;
        }

        if (!admin_users_actor_can_access_user($row, $actorUid, $actorRole)) {
            continue;
        }

        $wallet = admin_users_load_wallet((string)$uid);
        $roleSettings = admin_users_load_role_settings((string)$uid, $role);

        $items[] = [
            'uid' => (string)($row['uid'] ?? $uid),
            'name' => (string)($row['name'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'role' => $role,
            'status' => $status,
            'created_at' => (int)($row['created_at'] ?? 0),
            'updated_at' => (int)($row['updated_at'] ?? 0),
            'last_login_at' => (int)($row['last_login_at'] ?? 0),
            'parent_subadmin_uid' => (string)($row['parent_subadmin_uid'] ?? ''),
            'created_by_uid' => (string)($row['created_by_uid'] ?? ''),
            'created_by_role' => (string)($row['created_by_role'] ?? ''),
            'register_source' => (string)($row['register_source'] ?? ''),
            'converted_to_retailer_at' => (int)($row['converted_to_retailer_at'] ?? 0),
            'converted_to_retailer_by' => (string)($row['converted_to_retailer_by'] ?? ''),
            'available_balance' => (float)($wallet['available_balance'] ?? 0),
            'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
            'topup_enabled' => (bool)($roleSettings['topup_enabled'] ?? false),
            'bundle_enabled' => (bool)($roleSettings['bundle_enabled'] ?? false),
            'api_enabled' => (bool)($roleSettings['api_enabled'] ?? false),
            'commission_per_1000' => (float)($roleSettings['commission_per_1000'] ?? 0),
            'min_amount' => (float)($roleSettings['min_amount'] ?? 0),
            'max_amount' => (float)($roleSettings['max_amount'] ?? 0),
            'can_convert_to_retailer' => ($role === 'USER' && $status === 'ACTIVE'),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['created_at'] ?? 0));
        $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['created_at'] ?? 0));
        return $bTime <=> $aTime;
    });

    if ($limit > 0 && count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }

    return array_values($items);
}