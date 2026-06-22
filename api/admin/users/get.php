<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

function admin_user_get_country_code(array $user, array $wallet = []): string
{
    return auth_pricing_country_from_user($user, $wallet);
}

api_require_method('GET');
auth_require_admin_session();

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', [], 422);
}

$user = fb_get('USERS/' . $uid);
$wallet = fb_get('USER_WALLETS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}
if (!is_array($wallet)) {
    $wallet = [];
}

$role = (string)($user['role'] ?? 'USER');
$walletDisplay = function_exists('mfs_wallet_display_payload') ? mfs_wallet_display_payload($user, $wallet) : [];

$roleSettings = fb_get('USER_ROLE_SETTINGS/' . $uid);
if (!is_array($roleSettings)) {
    $roleSettings = role_default_settings($role);
} elseif (function_exists('role_settings_with_defaults')) {
    $roleSettings = role_settings_with_defaults($roleSettings, $role);
}

api_response(true, 'SUCCESS', 'User loaded', [
    'uid' => (string)$uid,
    'name' => (string)($user['name'] ?? ''),
    'phone' => (string)($user['phone'] ?? ''),
    'email' => (string)($user['email'] ?? ''),
    'status' => (string)($user['status'] ?? ''),
    'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
    'review_required' => (bool)($user['review_required'] ?? $user['requires_admin_review'] ?? false),
    'role' => $role,
    'country_code' => admin_user_get_country_code($user, $wallet),
    'country' => admin_user_get_country_code($user, $wallet),
    'phone_country' => auth_phone_country_from_user($user),
    'pricing_country' => admin_user_get_country_code($user, $wallet),
    'market_country' => admin_user_get_country_code($user, $wallet),
    'service_country' => admin_user_get_country_code($user, $wallet),
    'ip_country' => (function ($value): string {
        $raw = strtoupper(trim((string)$value));
        $country = function_exists('market_iso_country_code') ? market_iso_country_code($raw) : $raw;
        return $country !== '' ? $country : ($raw === 'UNKNOWN' ? 'UNKNOWN' : '');
    })($user['ip_country'] ?? ''),
    'gps_country' => strtoupper(trim((string)($user['gps_country'] ?? ''))),
    'ip_source' => (string)($user['ip_source'] ?? ''),
    'gps_lat' => (float)($user['gps_lat'] ?? 0),
    'gps_lng' => (float)($user['gps_lng'] ?? 0),
    'gps_accuracy' => (float)($user['gps_accuracy'] ?? 0),
    'country_mismatch' => array_key_exists('country_mismatch', $user)
        ? (bool)$user['country_mismatch']
        : auth_phone_country_from_user($user) !== admin_user_get_country_code($user, $wallet),
    'vpn_suspected' => (bool)($user['vpn_suspected'] ?? false),
    'market_detection_source' => (string)($user['market_detection_source'] ?? ''),
    'account_review_reason' => (string)($user['account_review_reason'] ?? ''),
    'ip_risk_type' => (string)($user['ip_risk_type'] ?? ''),
    'ip_risk_score' => (int)($user['ip_risk_score'] ?? 0),
    'review_status' => (string)($user['review_status'] ?? ''),
    'approved_by_uid' => (string)($user['approved_by_uid'] ?? ''),
    'approved_at' => (int)($user['approved_at'] ?? 0),
    'rejected_by_uid' => (string)($user['rejected_by_uid'] ?? ''),
    'rejected_at' => (int)($user['rejected_at'] ?? 0),
    'created_ip' => (string)($user['created_ip'] ?? $user['registration_ip'] ?? ''),
    'registration_ip' => (string)($user['registration_ip'] ?? $user['created_ip'] ?? ''),
    'last_login_ip' => (string)($user['last_login_ip'] ?? ''),
    'browser_timezone' => (string)($user['browser_timezone'] ?? ''),
    'user_agent' => (string)($user['user_agent'] ?? ''),
    'created_at' => (int)($user['created_at'] ?? 0),
    'last_login_at' => (int)($user['last_login_at'] ?? 0),

    'wallet' => [
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
        'total_topup_spent' => (float)($wallet['total_topup_spent'] ?? 0),
        'total_bundle_spent' => (float)($wallet['total_bundle_spent'] ?? 0),
        'total_refund' => (float)($wallet['total_refund'] ?? 0),
        'updated_at' => (int)($wallet['updated_at'] ?? 0),
    ],

    'role_settings' => $roleSettings,
    'commission_per_1000' => (float)($roleSettings['commission_per_1000'] ?? 0),
    'api_enabled' => (bool)($roleSettings['api_enabled'] ?? false),
    'topup_enabled' => (bool)($roleSettings['topup_enabled'] ?? true),
    'bundle_enabled' => (bool)($roleSettings['bundle_enabled'] ?? true),
    'min_amount' => (float)($roleSettings['min_amount'] ?? 0),
    'max_amount' => (float)($roleSettings['max_amount'] ?? 0),
]);
