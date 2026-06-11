<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function allowed_roles(): array
{
    return ['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'];
}

function normalize_role(mixed $role, string $default = 'USER'): string
{
    $role = strtoupper(trim((string)$role));
    if (!in_array($role, allowed_roles(), true)) {
        return $default;
    }
    return $role;
}

function is_valid_role(mixed $role): bool
{
    return in_array(strtoupper(trim((string)$role)), allowed_roles(), true);
}

function role_default_settings(string $role = 'USER'): array
{
    $role = strtoupper(trim($role));
    $now = function_exists('now_ts') ? (int)now_ts() : time();

    switch ($role) {
        case 'SUBADMIN':
            return [
                'commission_per_1000' => 18,
                'api_enabled' => true,
                'topup_enabled' => true,
                'bundle_enabled' => true,
                'min_amount' => 20,
                'max_amount' => 5000,
                'updated_at' => $now,
            ];

        case 'RETAILER':
            return [
                'commission_per_1000' => 18,
                'api_enabled' => false,
                'topup_enabled' => true,
                'bundle_enabled' => true,
                'min_amount' => 20,
                'max_amount' => 2000,
                'updated_at' => $now,
            ];

        case 'ADMIN':
            return [
                'commission_per_1000' => 0,
                'api_enabled' => true,
                'topup_enabled' => true,
                'bundle_enabled' => true,
                'min_amount' => 0,
                'max_amount' => 0,
                'updated_at' => $now,
            ];

        case 'USER':
        default:
            return [
                'commission_per_1000' => 0,
                'api_enabled' => false,
                'topup_enabled' => true,
                'bundle_enabled' => true,
                'min_amount' => 20,
                'max_amount' => 1000,
                'updated_at' => $now,
            ];
    }
}

function role_default_commission_per_1000(string $role = 'USER'): float
{
    $defaults = role_default_settings($role);
    return round(max(0, (float)($defaults['commission_per_1000'] ?? 0)), 2);
}

function role_settings_with_defaults(array $settings, string $role = 'USER'): array
{
    return array_replace(role_default_settings($role), $settings);
}

function normalize_role_settings(array $input, string $role = 'USER'): array
{
    $defaults = role_default_settings($role);

    $commission = (float)($input['commission_per_1000'] ?? $defaults['commission_per_1000']);
    $apiEnabled = (bool)($input['api_enabled'] ?? $defaults['api_enabled']);
    $topupEnabled = (bool)($input['topup_enabled'] ?? $defaults['topup_enabled']);
    $bundleEnabled = (bool)($input['bundle_enabled'] ?? $defaults['bundle_enabled']);
    $minAmount = (float)($input['min_amount'] ?? $defaults['min_amount']);
    $maxAmount = (float)($input['max_amount'] ?? $defaults['max_amount']);

    if ($commission < 0) {
        $commission = 0;
    }
    if ($minAmount < 0) {
        $minAmount = 0;
    }
    if ($maxAmount < 0) {
        $maxAmount = 0;
    }

    if ($role !== 'SUBADMIN') {
        $apiEnabled = false;
    }

    return [
        'commission_per_1000' => round($commission, 2),
        'api_enabled' => $apiEnabled,
        'topup_enabled' => $topupEnabled,
        'bundle_enabled' => $bundleEnabled,
        'min_amount' => round($minAmount, 2),
        'max_amount' => round($maxAmount, 2),
        'updated_at' => now_ts(),
    ];
}
