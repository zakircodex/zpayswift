<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$users = fb_get('USERS');
$wallets = fb_get('USER_WALLETS');

if (!is_array($users)) {
    $users = [];
}
if (!is_array($wallets)) {
    $wallets = [];
}

$items = [];

foreach ($users as $uid => $user) {
    if (!is_array($user)) {
        continue;
    }

    $wallet = is_array($wallets[$uid] ?? null) ? $wallets[$uid] : [];
    $role = (string)($user['role'] ?? 'USER');

    $roleSettings = fb_get('USER_ROLE_SETTINGS/' . $uid);
    if (!is_array($roleSettings)) {
        $roleSettings = role_default_settings($role);
    }

    $items[] = [
        'uid' => (string)$uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
        'role' => $role,

        'available_balance' => (float)($wallet['available_balance'] ?? 0),
        'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
        'created_at' => (int)($user['created_at'] ?? 0),

        'role_settings' => $roleSettings,
        'commission_per_1000' => (float)($roleSettings['commission_per_1000'] ?? 0),
        'api_enabled' => (bool)($roleSettings['api_enabled'] ?? false),
        'topup_enabled' => (bool)($roleSettings['topup_enabled'] ?? true),
        'bundle_enabled' => (bool)($roleSettings['bundle_enabled'] ?? true),
        'min_amount' => (float)($roleSettings['min_amount'] ?? 0),
        'max_amount' => (float)($roleSettings['max_amount'] ?? 0),
    ];
}

usort($items, static function (array $a, array $b): int {
    return (int)$b['created_at'] <=> (int)$a['created_at'];
});

api_response(true, 'SUCCESS', 'User list loaded', [
    'items' => $items,
]);