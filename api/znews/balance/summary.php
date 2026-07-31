<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/transfers.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$rows = fb_get('ZNEWS_CREATOR_BALANCES/' . $uid);
$balances = [];

if (is_array($rows)) {
    foreach ($rows as $currency => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['currency'] = (string)($row['currency'] ?? $currency);
        $public = znews_settlement_balance_public($row);
        $reserved = max(0, (int)($row['reserved_micros'] ?? 0));
        $transferred = max(0, (int)($row['transferred_micros'] ?? 0));
        $balances[] = array_merge($public, [
            'reserved_micros' => $reserved,
            'reserved' => znews_transfer_micros_to_decimal($reserved),
            'transferred_micros' => $transferred,
            'transferred' => znews_transfer_micros_to_decimal($transferred),
            'main_wallet_transfer_enabled' => true,
            'minimum_bdt_micros' => znews_transfer_threshold_bdt_micros(),
            'minimum_bdt' => '200',
        ]);
    }
}

usort($balances, static fn(array $a, array $b): int =>
    strcmp((string)$a['currency'], (string)$b['currency'])
);

api_response(
    true,
    'ZNEWS_BALANCE_SUMMARY_OK',
    'Z Sky 24 balance loaded.',
    [
        'creator_share_percent' => 50,
        'platform_share_percent' => 50,
        'creator_ad_payout_policy' => znews_settlement_creator_ad_payout_policy(),
        'main_wallet_transfer_enabled' => true,
        'minimum_bdt_micros' => znews_transfer_threshold_bdt_micros(),
        'minimum_bdt' => '200',
        'transfer_requires_admin_approval' => true,
        'balances' => $balances,
    ]
);
