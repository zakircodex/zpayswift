<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

function admin_users_list_country_code(array $user): string
{
    $country = strtoupper(trim((string)($user['country_code'] ?? $user['country'] ?? $user['user_country'] ?? '')));
    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',
        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
    ];

    return $map[$country] ?? '';
}

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
    $walletDisplay = function_exists('mfs_wallet_display_payload') ? mfs_wallet_display_payload($user, $wallet) : [];
    $role = (string)($user['role'] ?? 'USER');

    $roleSettings = fb_get('USER_ROLE_SETTINGS/' . $uid);
    if (!is_array($roleSettings)) {
        $roleSettings = role_default_settings($role);
    } elseif (function_exists('role_settings_with_defaults')) {
        $roleSettings = role_settings_with_defaults($roleSettings, $role);
    }

    $items[] = [
        'uid' => (string)$uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
        'role' => $role,
        'country_code' => admin_users_list_country_code($user),
        'country' => admin_users_list_country_code($user),

        'available_balance' => (float)($wallet['available_balance'] ?? 0),
        'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
        'currency' => (string)($walletDisplay['currency'] ?? $wallet['currency'] ?? $wallet['wallet_currency'] ?? 'BDT'),
        'wallet_currency' => (string)($walletDisplay['wallet_currency'] ?? $wallet['wallet_currency'] ?? $wallet['currency'] ?? 'BDT'),
        'display_currency' => (string)($walletDisplay['display_currency'] ?? $wallet['currency'] ?? 'BDT'),
        'display_available_balance' => (float)($walletDisplay['display_available_balance'] ?? $wallet['available_balance'] ?? 0),
        'display_hold_balance' => (float)($walletDisplay['display_hold_balance'] ?? $wallet['hold_balance'] ?? 0),
        'available_balance_bdt' => (float)($walletDisplay['available_balance_bdt'] ?? $wallet['available_balance'] ?? 0),
        'hold_balance_bdt' => (float)($walletDisplay['hold_balance_bdt'] ?? $wallet['hold_balance'] ?? 0),
        'available_balance_myr' => (float)($walletDisplay['available_balance_myr'] ?? 0),
        'hold_balance_myr' => (float)($walletDisplay['hold_balance_myr'] ?? 0),
        'rate_myr_bdt' => (float)($walletDisplay['rate_myr_bdt'] ?? 0),
        'conversion_note' => (string)($walletDisplay['conversion_note'] ?? ''),
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
