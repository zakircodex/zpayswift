<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_settlement_creator_ad_payout_unit_micros(): int
{
    return 10000; // BDT 0.01 (one paisa).
}

function znews_settlement_creator_ad_payout_cap_micros(): int
{
    $configured = defined('ZNEWS_CREATOR_AD_PAYOUT_CAP_BDT_MICROS')
        ? (int)constant('ZNEWS_CREATOR_AD_PAYOUT_CAP_BDT_MICROS')
        : 30000;

    return max(
        znews_settlement_creator_ad_payout_unit_micros(),
        min(30000, $configured)
    );
}

function znews_settlement_apply_bdt_creator_ad_policy(int $uncappedMicros): int
{
    $unit = znews_settlement_creator_ad_payout_unit_micros();
    $creator = min(max(0, $uncappedMicros), znews_settlement_creator_ad_payout_cap_micros());

    return $creator - ($creator % $unit);
}
