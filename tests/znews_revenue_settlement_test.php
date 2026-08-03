<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_settlement_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_settlement_test_read(string $path): string
{
    znews_settlement_test_expect(is_file($path), 'missing file: ' . $path);
    $source = file_get_contents($path);
    znews_settlement_test_expect($source !== false, 'unable to read: ' . $path);
    return (string)$source;
}

$files = [
    'api/znews/lib/settlements.php',
    'api/znews/lib/settlements_common.php',
    'api/znews/lib/settlement_payout_policy.php',
    'api/znews/lib/settlements_balances.php',
    'api/znews/lib/settlements_service.php',
    'api/znews/lib/settlements_access.php',
    'api/znews/lib/settlements_admin.php',
    'api/admin/znews/ads/settlements/queue.php',
    'api/admin/znews/ads/settlements/details.php',
    'api/admin/znews/ads/settlements/settle.php',
    'api/znews/balance/summary.php',
    'api/znews/balance/ledger.php',
];

foreach ($files as $relative) {
    $source = znews_settlement_test_read($root . '/' . $relative);
    znews_settlement_test_expect(
        str_contains($source, 'declare(strict_types=1);'),
        "{$relative} missing strict types"
    );
    znews_settlement_test_expect(
        !str_contains($source, 'lib/wallet.php')
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/')
        && !str_contains($source, 'wallet_credit_available')
        && !str_contains($source, 'wallet_debit_available'),
        "{$relative} touches existing main wallet business logic"
    );
}

$common = znews_settlement_test_read($root . '/api/znews/lib/settlements_common.php');
$payoutPolicy = znews_settlement_test_read($root . '/api/znews/lib/settlement_payout_policy.php');
znews_settlement_test_expect(str_contains($common, 'return 5000;'), 'creator base share is not fixed at 50 percent');
znews_settlement_test_expect(str_contains($common, '10000 - znews_settlement_creator_share_bps()'), 'base platform share metadata is missing');
znews_settlement_test_expect(str_contains($common, 'intdiv($grossMicros * znews_settlement_creator_share_bps(), 10000)'), 'integer-micros allocation missing');
znews_settlement_test_expect(str_contains($payoutPolicy, 'min(30000, $configured)'), 'BDT creator payout can exceed 0.03 per verified ad');
znews_settlement_test_expect(str_contains($payoutPolicy, 'return $creator - ($creator % $unit);'), 'BDT creator payout is not rounded down to whole paisa');
znews_settlement_test_expect(str_contains($common, '$platform = $grossMicros - $creator'), 'rounding-safe platform remainder missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_SETTLEMENTS/'), 'settlement namespace missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_SETTLEMENT_ITEMS/'), 'settlement item namespace missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_CREATOR_BALANCES/'), 'creator balance namespace missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_PLATFORM_BALANCES/'), 'platform balance namespace missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_CREATOR_LEDGER/'), 'creator ledger namespace missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_PLATFORM_LEDGER/'), 'platform ledger namespace missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_IMPRESSION_SETTLEMENTS/'), 'impression settlement index missing');
znews_settlement_test_expect(str_contains($common, 'ZNEWS_SETTLEMENT_ADMIN_IDEMPOTENCY/'), 'admin idempotency namespace missing');
znews_settlement_test_expect(str_contains($common, "'main_wallet_transfer_enabled' => false"), 'main wallet transfer is incorrectly enabled');

$balances = znews_settlement_test_read($root . '/api/znews/lib/settlements_balances.php');
znews_settlement_test_expect(str_contains($balances, 'applied_settlements'), 'balance exact-once event ledger missing');
znews_settlement_test_expect(str_contains($balances, 'fb_get_with_etag') && str_contains($balances, 'fb_put_if_match'), 'balance CAS protection missing');
znews_settlement_test_expect(str_contains($balances, 'ZNEWS_SETTLEMENT_BALANCE_EVENT_CONFLICT'), 'balance replay conflict protection missing');

$service = znews_settlement_test_read($root . '/api/znews/lib/settlements_service.php');
znews_settlement_test_expect(str_contains($service, "'VERIFIED'") && str_contains($service, 'verification_status'), 'verified impression gate missing');
znews_settlement_test_expect(str_contains($service, 'expectedUpdatedAt'), 'settlement version check missing');
znews_settlement_test_expect(str_contains($service, 'ZNEWS_SETTLEMENT_CREATOR_MISSING'), 'missing creator guard missing');
znews_settlement_test_expect(str_contains($service, 'ZNEWS_SETTLEMENT_ALLOCATION_CONFLICT'), 'stored allocation conflict protection missing');
znews_settlement_test_expect(substr_count($service, 'ZNEWS_SETTLEMENT_ALREADY_COMPLETED') >= 2, 'settled-impression replay protection missing');
znews_settlement_test_expect(substr_count($service, "'idempotent_replay' => true") >= 2, 'settlement replay is not idempotent');
znews_settlement_test_expect(str_contains($service, "'settlement_status'] = 'SETTLING'"), 'settlement claim state missing');
znews_settlement_test_expect(str_contains($service, "'settlement_status'] = 'SETTLED'"), 'settlement final state missing');
znews_settlement_test_expect(str_contains($service, "'znews_balance_status'] = 'CREDITED'"), 'separate Z News balance credit marker missing');
znews_settlement_test_expect(str_contains($service, "'main_wallet_credit_status'] = 'NOT_CREDITED'"), 'main wallet credit incorrectly enabled');
znews_settlement_test_expect(str_contains($service, "'credit_status'] = 'NOT_CREDITED'"), 'legacy/main wallet credit state changed');
znews_settlement_test_expect(str_contains($service, "'transfer_status'] = 'NOT_REQUESTED'"), 'wallet transfer incorrectly started');
znews_settlement_test_expect(str_contains($service, 'znews_settlement_apply_creator_balance'), 'creator balance allocation missing');
znews_settlement_test_expect(str_contains($service, 'znews_settlement_apply_platform_balance'), 'platform balance allocation missing');
znews_settlement_test_expect(str_contains($service, 'reconciliation_required'), 'partial failure reconciliation missing');
znews_settlement_test_expect(str_contains($service, 'fb_put_if_match'), 'impression optimistic concurrency missing');

$admin = znews_settlement_test_read($root . '/api/znews/lib/settlements_admin.php');
znews_settlement_test_expect(str_contains($admin, 'payload_hash'), 'admin idempotency payload hash missing');
znews_settlement_test_expect(str_contains($admin, 'lease_expires_at'), 'admin processing lease missing');
znews_settlement_test_expect(str_contains($admin, 'ZNEWS_IDEMPOTENCY_CONFLICT'), 'admin idempotency conflict missing');

foreach (['queue.php', 'details.php'] as $name) {
    $source = znews_settlement_test_read(
        $root . '/api/admin/znews/ads/settlements/' . $name
    );
    znews_settlement_test_expect(
        str_contains($source, 'auth_require_admin_session(true)'),
        "{$name} lacks admin session protection"
    );
    znews_settlement_test_expect(
        str_contains($source, "api_require_method('GET')"),
        "{$name} is not GET-only"
    );
}

$settleEndpoint = znews_settlement_test_read(
    $root . '/api/admin/znews/ads/settlements/settle.php'
);
znews_settlement_test_expect(str_contains($settleEndpoint, "api_require_method('POST')"), 'settle endpoint is not POST-only');
znews_settlement_test_expect(str_contains($settleEndpoint, 'auth_require_admin_session(true)'), 'settle endpoint lacks admin session');
znews_settlement_test_expect(str_contains($settleEndpoint, 'expected_updated_at'), 'settle endpoint lacks version protection');
znews_settlement_test_expect(str_contains($settleEndpoint, 'znews_idempotency_key'), 'settle endpoint lacks idempotency');

foreach (['summary.php', 'ledger.php'] as $name) {
    $source = znews_settlement_test_read($root . '/api/znews/balance/' . $name);
    znews_settlement_test_expect(str_contains($source, 'api_require_app_key();'), "{$name} lacks app key");
    znews_settlement_test_expect(str_contains($source, 'znews_require_creator(true)'), "{$name} lacks creator authentication");
    znews_settlement_test_expect(!str_contains($source, "['uid'] ?? \$_GET"), "{$name} appears to trust client UID");
}

echo "Z News revenue settlement tests passed ({$assertions} assertions).\n";
