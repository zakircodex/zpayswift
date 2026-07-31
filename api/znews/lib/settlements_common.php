<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/ad_impressions.php';
require_once __DIR__ . '/settlement_payout_policy.php';

function znews_settlement_creator_share_bps(): int
{
    return 5000;
}

function znews_settlement_platform_share_bps(): int
{
    return 10000 - znews_settlement_creator_share_bps();
}

function znews_settlement_creator_ad_payout_policy(): array
{
    $unit = znews_settlement_creator_ad_payout_unit_micros();
    $maximum = znews_settlement_creator_ad_payout_cap_micros();

    return [
        'calculation_source' => 'PROVIDER_REPORTED_REVENUE',
        'currency' => 'BDT',
        'base_creator_share_bps' => znews_settlement_creator_share_bps(),
        'base_creator_share_percent' => intdiv(znews_settlement_creator_share_bps(), 100),
        'payout_unit_micros' => $unit,
        'payout_unit' => znews_settlement_decimal($unit),
        'maximum_per_verified_ad_micros' => $maximum,
        'maximum_per_verified_ad' => znews_settlement_decimal($maximum),
        'client_submitted_value_allowed' => false,
    ];
}

function znews_settlement_lease_seconds(): int
{
    $value = defined('ZNEWS_SETTLEMENT_LEASE_SECONDS')
        ? (int)constant('ZNEWS_SETTLEMENT_LEASE_SECONDS')
        : 180;

    return max(60, min(900, $value));
}

function znews_settlement_scan_limit(): int
{
    $value = defined('ZNEWS_SETTLEMENT_SCAN_LIMIT')
        ? (int)constant('ZNEWS_SETTLEMENT_SCAN_LIMIT')
        : 5000;

    return max(100, min(20000, $value));
}

function znews_settlement_allocation(int $grossMicros, string $currency = ''): array
{
    if ($grossMicros < 0) {
        api_response(false, 'ZNEWS_SETTLEMENT_AMOUNT_INVALID', 'Invalid settlement amount.', [], 422);
    }

    $currency = znews_ad_currency($currency);
    $creatorUncapped = intdiv($grossMicros * znews_settlement_creator_share_bps(), 10000);
    $creator = $creatorUncapped;
    $payoutUnit = 1;
    $payoutCap = 0;
    if ($currency === 'BDT') {
        $payoutUnit = znews_settlement_creator_ad_payout_unit_micros();
        $payoutCap = znews_settlement_creator_ad_payout_cap_micros();
        $creator = znews_settlement_apply_bdt_creator_ad_policy($creator);
    }
    $platform = $grossMicros - $creator;

    return [
        'gross_micros' => $grossMicros,
        'creator_share_bps' => znews_settlement_creator_share_bps(),
        'platform_share_bps' => znews_settlement_platform_share_bps(),
        'creator_uncapped_micros' => $creatorUncapped,
        'creator_payout_unit_micros' => $payoutUnit,
        'creator_payout_cap_micros' => $payoutCap,
        'creator_payout_capped' => $creator < $creatorUncapped,
        'creator_micros' => $creator,
        'platform_micros' => $platform,
    ];
}

function znews_settlement_decimal(int $micros): string
{
    $negative = $micros < 0;
    $value = abs($micros);
    $whole = intdiv($value, 1000000);
    $fraction = str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    $fraction = rtrim($fraction, '0');

    return ($negative ? '-' : '')
        . $whole
        . ($fraction === '' ? '' : '.' . $fraction);
}

function znews_settlement_id(string $impressionId): string
{
    $impressionId = znews_firebase_key($impressionId, 'impression_id');

    return 'ZST' . strtoupper(substr(hash('sha256', 'AD_IMPRESSION|' . $impressionId), 0, 29));
}

function znews_settlement_path(string $settlementId): string
{
    return 'ZNEWS_SETTLEMENTS/' . znews_firebase_key($settlementId, 'settlement_id');
}

function znews_settlement_item_path(string $settlementId, string $impressionId): string
{
    return 'ZNEWS_SETTLEMENT_ITEMS/'
        . znews_firebase_key($settlementId, 'settlement_id')
        . '/'
        . znews_firebase_key($impressionId, 'impression_id');
}

function znews_settlement_impression_index_path(string $impressionId): string
{
    return 'ZNEWS_IMPRESSION_SETTLEMENTS/'
        . znews_firebase_key($impressionId, 'impression_id');
}

function znews_settlement_creator_balance_path(string $uid, string $currency): string
{
    return 'ZNEWS_CREATOR_BALANCES/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_ad_currency($currency);
}

function znews_settlement_platform_balance_path(string $currency): string
{
    return 'ZNEWS_PLATFORM_BALANCES/' . znews_ad_currency($currency);
}

function znews_settlement_creator_ledger_path(
    string $uid,
    string $currency,
    string $settlementId
): string {
    return 'ZNEWS_CREATOR_LEDGER/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_ad_currency($currency)
        . '/'
        . znews_firebase_key($settlementId, 'settlement_id');
}

function znews_settlement_platform_ledger_path(
    string $currency,
    string $settlementId
): string {
    return 'ZNEWS_PLATFORM_LEDGER/'
        . znews_ad_currency($currency)
        . '/'
        . znews_firebase_key($settlementId, 'settlement_id');
}

function znews_settlement_creator_index_path(string $uid, string $settlementId): string
{
    return 'ZNEWS_CREATOR_SETTLEMENTS/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_firebase_key($settlementId, 'settlement_id');
}

function znews_settlement_admin_request_path(string $adminUid, string $key): string
{
    return 'ZNEWS_SETTLEMENT_ADMIN_IDEMPOTENCY/'
        . znews_firebase_key($adminUid, 'admin_uid')
        . '/'
        . hash('sha256', znews_idempotency_key($key));
}

function znews_settlement_public(array $row): array
{
    $gross = max(0, (int)($row['gross_revenue_micros'] ?? 0));
    $creator = max(0, (int)($row['creator_amount_micros'] ?? 0));
    $platform = max(0, (int)($row['platform_amount_micros'] ?? 0));

    return [
        'settlement_id' => trim((string)($row['settlement_id'] ?? '')),
        'impression_id' => trim((string)($row['impression_id'] ?? '')),
        'post_id' => trim((string)($row['post_id'] ?? '')),
        'currency' => strtoupper(trim((string)($row['currency'] ?? ''))),
        'status' => strtoupper(trim((string)($row['status'] ?? 'SETTLING'))),
        'gross_revenue_micros' => $gross,
        'gross_revenue' => znews_settlement_decimal($gross),
        'creator_share_bps' => (int)($row['creator_share_bps'] ?? 5000),
        'creator_uncapped_micros' => max(0, (int)($row['creator_uncapped_micros'] ?? $creator)),
        'creator_payout_unit_micros' => max(0, (int)($row['creator_payout_unit_micros'] ?? 0)),
        'creator_payout_cap_micros' => max(0, (int)($row['creator_payout_cap_micros'] ?? 0)),
        'creator_payout_capped' => !empty($row['creator_payout_capped']),
        'creator_amount_micros' => $creator,
        'creator_amount' => znews_settlement_decimal($creator),
        'platform_share_bps' => (int)($row['platform_share_bps'] ?? 5000),
        'platform_amount_micros' => $platform,
        'platform_amount' => znews_settlement_decimal($platform),
        'znews_balance_status' => strtoupper(trim((string)($row['znews_balance_status'] ?? 'PENDING'))),
        'main_wallet_transfer_status' => strtoupper(trim((string)($row['main_wallet_transfer_status'] ?? 'NOT_TRANSFERRED'))),
        'created_at' => max(0, (int)($row['created_at'] ?? 0)),
        'settled_at' => max(0, (int)($row['settled_at'] ?? 0)),
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
    ];
}

function znews_settlement_balance_public(array $row): array
{
    $available = max(0, (int)($row['available_micros'] ?? 0));
    $total = max(0, (int)($row['total_settled_micros'] ?? 0));

    return [
        'currency' => strtoupper(trim((string)($row['currency'] ?? ''))),
        'available_micros' => $available,
        'available' => znews_settlement_decimal($available),
        'total_settled_micros' => $total,
        'total_settled' => znews_settlement_decimal($total),
        'main_wallet_transfer_enabled' => false,
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
    ];
}
