<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_transfer_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_transfer_test_read(string $path): string
{
    znews_transfer_test_expect(is_file($path), 'missing file: ' . $path);
    $source = file_get_contents($path);
    znews_transfer_test_expect($source !== false, 'unable to read: ' . $path);
    return (string)$source;
}

$files = [
    'api/znews/lib/transfers.php',
    'api/znews/lib/transfers_common.php',
    'api/znews/lib/transfers_rates.php',
    'api/znews/lib/transfers_balances.php',
    'api/znews/lib/transfers_service.php',
    'api/znews/lib/transfers_access.php',
    'api/znews/lib/transfers_wallet.php',
    'api/znews/lib/transfers_admin.php',
    'api/znews/lib/transfers_admin_claims.php',
    'api/znews/lib/transfers_admin_approve.php',
    'api/znews/lib/transfers_admin_reject.php',
    'api/znews/transfers/preview.php',
    'api/znews/transfers/create.php',
    'api/znews/transfers/list.php',
    'api/znews/transfers/details.php',
    'api/admin/znews/transfers/queue.php',
    'api/admin/znews/transfers/details.php',
    'api/admin/znews/transfers/approve.php',
    'api/admin/znews/transfers/reject.php',
    'api/admin/znews/transfers/rates/list.php',
    'api/admin/znews/transfers/rates/update.php',
    'api/znews/balance/summary.php',
];

foreach ($files as $relative) {
    $source = znews_transfer_test_read($root . '/' . $relative);
    znews_transfer_test_expect(
        str_contains($source, 'declare(strict_types=1);'),
        "{$relative} missing strict types"
    );
}

$common = znews_transfer_test_read($root . '/api/znews/lib/transfers_common.php');
znews_transfer_test_expect(str_contains($common, 'return 500 * 1000000;'), 'BDT 500 threshold missing');
znews_transfer_test_expect(str_contains($common, 'ZNEWS_TRANSFER_REQUESTS/'), 'transfer request namespace missing');
znews_transfer_test_expect(str_contains($common, 'ZNEWS_USER_TRANSFER_REQUESTS/'), 'user transfer index missing');
znews_transfer_test_expect(str_contains($common, 'ZNEWS_TRANSFER_REVIEW_QUEUE/'), 'admin queue namespace missing');
znews_transfer_test_expect(str_contains($common, 'ZNEWS_TRANSFER_IDEMPOTENCY/'), 'creator idempotency namespace missing');
znews_transfer_test_expect(str_contains($common, 'ZNEWS_TRANSFER_ADMIN_IDEMPOTENCY/'), 'admin idempotency namespace missing');
znews_transfer_test_expect(str_contains($common, 'znews_transfer_safe_multiply_rate'), 'safe source conversion missing');
znews_transfer_test_expect(str_contains($common, 'znews_transfer_safe_divide_rate'), 'safe MYR conversion missing');
znews_transfer_test_expect(str_contains($common, 'znews_transfer_round_micros_to_minor'), 'wallet minor rounding missing');

$rates = znews_transfer_test_read($root . '/api/znews/lib/transfers_rates.php');
znews_transfer_test_expect(str_contains($rates, "'BDT'") && str_contains($rates, '1000000'), 'fixed BDT conversion missing');
znews_transfer_test_expect(str_contains($rates, 'zpay_myr_to_bdt_rate(true)'), 'existing MYR/BDT rate is not reused');
znews_transfer_test_expect(str_contains($rates, 'ZNEWS_TRANSFER_RATES'), 'non-BDT rate configuration missing');
znews_transfer_test_expect(str_contains($rates, 'fb_get_with_etag') && str_contains($rates, 'fb_put_if_match'), 'rate update CAS missing');
znews_transfer_test_expect(str_contains($common, 'ZNEWS_TRANSFER_RATE_ADMIN_IDEMPOTENCY/'), 'rate admin idempotency missing');

$balances = znews_transfer_test_read($root . '/api/znews/lib/transfers_balances.php');
znews_transfer_test_expect(str_contains($balances, 'transfer_events'), 'balance exact-once event ledger missing');
znews_transfer_test_expect(str_contains($balances, "'RESERVE'") && str_contains($balances, "'RELEASE'") && str_contains($balances, "'CONSUME'"), 'balance transition states missing');
znews_transfer_test_expect(str_contains($balances, 'reserved_micros'), 'reserved balance tracking missing');
znews_transfer_test_expect(str_contains($balances, 'transferred_micros'), 'transferred balance tracking missing');
znews_transfer_test_expect(str_contains($balances, 'fb_get_with_etag') && str_contains($balances, 'fb_put_if_match'), 'balance CAS missing');

$service = znews_transfer_test_read($root . '/api/znews/lib/transfers_service.php');
znews_transfer_test_expect(str_contains($service, 'wallet_account_currency($user, $wallet)'), 'destination currency is not server-resolved');
znews_transfer_test_expect(str_contains($service, 'ZNEWS_TRANSFER_MINIMUM_NOT_MET'), 'minimum threshold enforcement missing');
znews_transfer_test_expect(str_contains($service, 'znews_transfer_reserve_balance'), 'request does not reserve source balance');
znews_transfer_test_expect(str_contains($service, 'source_to_bdt_rate_micros'), 'source rate snapshot missing');
znews_transfer_test_expect(str_contains($service, 'myr_to_bdt_rate_micros'), 'MYR rate snapshot missing');
znews_transfer_test_expect(!str_contains($service, "['uid'] ?? \$body"), 'request appears to trust client UID');

$wallet = znews_transfer_test_read($root . '/api/znews/lib/transfers_wallet.php');
znews_transfer_test_expect(str_contains($wallet, 'wallet_financial_operation_begin'), 'official wallet financial operation is not used');
znews_transfer_test_expect(str_contains($wallet, 'wallet_credit_available'), 'official wallet credit helper is not used');
znews_transfer_test_expect(str_contains($wallet, 'wallet_financial_operation_side_ledger_id'), 'deterministic wallet ledger id missing');
znews_transfer_test_expect(str_contains($wallet, 'wallet_store_transfer_records'), 'main wallet history integration missing');
znews_transfer_test_expect(str_contains($wallet, 'wallet_financial_operation_mark_completed'), 'wallet operation completion missing');
znews_transfer_test_expect(str_contains($wallet, 'LEDGER_EVIDENCE_MISSING'), 'wallet ledger evidence check missing');
znews_transfer_test_expect(!str_contains($wallet, "fb_put_if_match('USER_WALLETS/"), 'adapter directly mutates main wallet');
znews_transfer_test_expect(str_contains($wallet, 'ZNEWS_BALANCE_TRANSFER'), 'wallet ledger type missing');

$admin = znews_transfer_test_read($root . '/api/znews/lib/transfers_admin_claims.php')
    . znews_transfer_test_read($root . '/api/znews/lib/transfers_admin_approve.php')
    . znews_transfer_test_read($root . '/api/znews/lib/transfers_admin_reject.php');
$creditPos = strpos($admin, 'znews_transfer_wallet_credit');
$consumePos = strpos($admin, 'znews_transfer_consume_balance');
znews_transfer_test_expect($creditPos !== false && $consumePos !== false && $creditPos < $consumePos, 'source balance is consumed before wallet credit');
znews_transfer_test_expect(str_contains($admin, 'znews_transfer_release_balance'), 'reject flow does not release balance');
znews_transfer_test_expect(str_contains($admin, "'status'] = 'APPROVED'"), 'approved terminal state missing');
znews_transfer_test_expect(str_contains($admin, "'status'] = 'REJECTED'"), 'rejected terminal state missing');
znews_transfer_test_expect(str_contains($admin, "'status' => 'RECONCILIATION_REQUIRED'"), 'partial failure reconciliation missing');
znews_transfer_test_expect(str_contains($admin, 'expectedUpdatedAt'), 'admin version protection missing');
znews_transfer_test_expect(str_contains($admin, 'payload_hash'), 'admin idempotency payload hash missing');

foreach (['preview.php', 'create.php', 'list.php', 'details.php'] as $name) {
    $source = znews_transfer_test_read($root . '/api/znews/transfers/' . $name);
    znews_transfer_test_expect(str_contains($source, 'api_require_app_key();'), "{$name} lacks app key");
    znews_transfer_test_expect(str_contains($source, 'znews_require_creator(true)'), "{$name} lacks creator session");
}

foreach (['queue.php', 'details.php'] as $name) {
    $source = znews_transfer_test_read($root . '/api/admin/znews/transfers/' . $name);
    znews_transfer_test_expect(str_contains($source, 'auth_require_admin_session(true)'), "{$name} lacks admin session");
    znews_transfer_test_expect(str_contains($source, "api_require_method('GET')"), "{$name} is not GET-only");
}

foreach (['approve.php', 'reject.php'] as $name) {
    $source = znews_transfer_test_read($root . '/api/admin/znews/transfers/' . $name);
    znews_transfer_test_expect(str_contains($source, 'auth_require_admin_session(true)'), "{$name} lacks admin session");
    znews_transfer_test_expect(str_contains($source, "api_require_method('POST')"), "{$name} is not POST-only");
    znews_transfer_test_expect(str_contains($source, 'expected_updated_at'), "{$name} lacks version protection");
    znews_transfer_test_expect(str_contains($source, 'znews_idempotency_key'), "{$name} lacks idempotency");
}

$rateUpdate = znews_transfer_test_read($root . '/api/admin/znews/transfers/rates/update.php');
znews_transfer_test_expect(str_contains($rateUpdate, 'auth_require_admin_session(true)'), 'rate update lacks admin session');
znews_transfer_test_expect(str_contains($rateUpdate, 'expected_updated_at'), 'rate update lacks version protection');

$summary = znews_transfer_test_read($root . '/api/znews/balance/summary.php');
znews_transfer_test_expect(str_contains($summary, "'main_wallet_transfer_enabled' => true"), 'balance summary does not enable transfer');
znews_transfer_test_expect(str_contains($summary, "'minimum_bdt' => '500'"), 'balance summary threshold missing');
znews_transfer_test_expect(str_contains($summary, "'transfer_requires_admin_approval' => true"), 'approval requirement missing');

echo "Z News main-wallet transfer tests passed ({$assertions} assertions).\n";
