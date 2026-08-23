<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function mfs_my_fee_tier_boundaries(): array
{
    return [
        'TIER1' => ['tier_id' => 'TIER1', 'min_bdt' => 500.00, 'max_bdt' => 50000.00],
        'TIER2' => ['tier_id' => 'TIER2', 'min_bdt' => 50000.01, 'max_bdt' => 70000.00],
        'TIER3' => ['tier_id' => 'TIER3', 'min_bdt' => 70000.01, 'max_bdt' => 100000.00],
    ];
}

function mfs_default_my_fee_tiers(): array
{
    return [
        'TIER1' => ['USER' => 5.00, 'RETAILER' => 2.00, 'SUBADMIN' => 2.00, 'ADMIN' => 0.00],
        'TIER2' => ['USER' => 7.00, 'RETAILER' => 3.00, 'SUBADMIN' => 3.00, 'ADMIN' => 0.00],
        'TIER3' => ['USER' => 10.00, 'RETAILER' => 4.00, 'SUBADMIN' => 4.00, 'ADMIN' => 0.00],
    ];
}

function mfs_normalize_my_fee_role(string $role): string
{
    $role = strtoupper(trim($role));
    if ($role === 'PARTNER') {
        $role = 'SUBADMIN';
    }

    return in_array($role, ['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'], true) ? $role : 'USER';
}

function mfs_my_fee_tier_source(array $config): array
{
    foreach ([
        $config['TIERS'] ?? null,
        $config['tiers'] ?? null,
        $config['MY']['TIERS'] ?? null,
        $config['MY']['tiers'] ?? null,
        $config['fees']['MY']['TIERS'] ?? null,
        $config['fees']['MY']['tiers'] ?? null,
    ] as $candidate) {
        if (is_array($candidate)) {
            return $candidate;
        }
    }

    return $config;
}

function mfs_my_fee_tier_numeric_value(array $row, string $role, float $default): float
{
    $value = $row[$role] ?? $row[strtolower($role)] ?? null;
    if (is_array($value)) {
        $value = $value['fee_rm'] ?? $value['fee'] ?? $value['amount'] ?? $value['rm'] ?? null;
    }

    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
        return round($default, 2);
    }

    return round((float)$value, 2);
}

function mfs_normalize_my_fee_tiers(array $config = []): array
{
    $source = mfs_my_fee_tier_source($config);
    $defaults = mfs_default_my_fee_tiers();
    $boundaries = mfs_my_fee_tier_boundaries();
    $normalized = [];

    foreach ($boundaries as $tierId => $boundary) {
        $row = $source[$tierId]
            ?? $source[strtolower($tierId)]
            ?? $source[str_replace('TIER', 'TIER_', $tierId)]
            ?? [];
        $row = is_array($row) ? $row : [];

        $normalized[$tierId] = $boundary;
        foreach (['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'] as $role) {
            $normalized[$tierId][$role] = mfs_my_fee_tier_numeric_value(
                $row,
                $role,
                (float)$defaults[$tierId][$role]
            );
        }
    }

    return $normalized;
}

function mfs_my_fee_tier_storage(array $config = []): array
{
    $normalized = mfs_normalize_my_fee_tiers($config);
    $storage = [];

    foreach ($normalized as $tierId => $row) {
        $storage[$tierId] = [
            'USER' => (float)$row['USER'],
            'RETAILER' => (float)$row['RETAILER'],
            'SUBADMIN' => (float)$row['SUBADMIN'],
            'ADMIN' => (float)$row['ADMIN'],
        ];
    }

    return $storage;
}

function mfs_resolve_my_fee_tier(float $amountBdt, string $canonicalRole, array $canonicalFeeConfig = []): array
{
    $amountBdt = round($amountBdt, 2);
    $role = mfs_normalize_my_fee_role($canonicalRole);
    $tiers = mfs_normalize_my_fee_tiers($canonicalFeeConfig);

    foreach ($tiers as $tierId => $row) {
        if ($amountBdt < (float)$row['min_bdt'] || $amountBdt > (float)$row['max_bdt']) {
            continue;
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'fee_rm' => (float)$row[$role],
            'tier_id' => $tierId,
            'min_bdt' => (float)$row['min_bdt'],
            'max_bdt' => (float)$row['max_bdt'],
            'role' => $role,
        ];
    }

    return [
        'ok' => false,
        'code' => 'MFS_AMOUNT_OUT_OF_RANGE',
        'message' => 'Amount must be between BDT 500.00 and BDT 100,000.00',
        'data' => [
            'minimum_amount_bdt' => 500.00,
            'maximum_amount_bdt' => 100000.00,
        ],
    ];
}
