<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$store = [];
$versions = [];
$failNextLedgerPut = false;

function test_path_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn($part): bool => $part !== ''));
}

function test_store_get(string $path)
{
    global $store;
    $node = $store;
    foreach (test_path_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function test_store_set(string $path, $value): void
{
    global $store, $versions;
    $parts = test_path_parts($path);
    $node =& $store;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $versions[$path] = ($versions[$path] ?? 0) + 1;
}

function test_store_patch(string $path, array $patch): void
{
    $current = test_store_get($path);
    if (!is_array($current)) {
        $current = [];
    }
    test_store_set($path, array_merge($current, $patch));
}

function fb_get(string $path)
{
    return test_store_get($path);
}

function fb_put(string $path, $data): bool
{
    global $failNextLedgerPut;
    if ($failNextLedgerPut && str_starts_with($path, 'WALLET_LEDGER/')) {
        $failNextLedgerPut = false;
        return false;
    }
    test_store_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    test_store_patch($path, $data);
    return true;
}

function fb_delete(string $path): bool
{
    test_store_set($path, null);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $versions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => 'v' . (string)($versions[$path] ?? 0),
        'value' => test_store_get($path),
        'error' => '',
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $versions, $failNextLedgerPut;
    $expected = 'v' . (string)($versions[$path] ?? 0);
    if ($etag !== $expected) {
        return ['ok' => false, 'status' => 412];
    }
    if ($failNextLedgerPut && str_starts_with($path, 'WALLET_LEDGER/')) {
        $failNextLedgerPut = false;
        return ['ok' => false, 'status' => 500];
    }
    test_store_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function now_ts(): int
{
    return 1700000000;
}

function month_key(?int $ts = null): string
{
    return date('Y-m', $ts ?? now_ts());
}

require_once dirname(__DIR__) . '/api/lib/wallet.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function ledger_count(string $uid): int
{
    $rows = fb_get('WALLET_LEDGER/' . $uid . '/' . month_key());
    return is_array($rows) ? count(array_filter($rows, static fn($row): bool => is_array($row))) : 0;
}

$uid = 'U_TEST';
fb_put('USER_WALLETS/' . $uid, [
    'available_balance' => 100.00,
    'hold_balance' => 25.00,
    'wallet_currency' => 'BDT',
    'currency' => 'BDT',
]);

$claim = wallet_financial_operation_begin('REQ1', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 25.00, 'BDT');
assert_true(!empty($claim['ok']), 'first refund claim should succeed');
$refund = wallet_refund_hold($uid, 25.00, 'REQ1', 'TOPUP_REFUND', ['financial_operation' => $claim['claim']]);
assert_true(!empty($refund['ok']), 'first refund should succeed');
wallet_financial_operation_mark_completed($claim['claim'], ['final_status' => 'FAILED']);

$dupe = wallet_financial_operation_begin('REQ1', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 25.00, 'BDT');
assert_true(!empty($dupe['duplicate']), 'duplicate refund claim should replay');
assert_true((float)fb_get('USER_WALLETS/' . $uid)['available_balance'] === 125.00, 'refund should credit once');
assert_true(ledger_count($uid) === 1, 'refund should write one ledger row');

$conflict = wallet_financial_operation_begin('REQ1', 'TOPUP_SUCCESS', 'REQUEST_FINAL', $uid, 25.00, 'BDT');
assert_true(!empty($conflict['duplicate']), 'opposite final operation should not mutate after completion');

$settleClaim = wallet_financial_operation_begin('REQ2', 'BUNDLE_SUCCESS', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
assert_true(!empty($settleClaim['ok']), 'first bundle success claim should succeed');
$settleDupe = wallet_financial_operation_begin('REQ2', 'BUNDLE_SUCCESS', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
assert_true(empty($settleDupe['ok']) && ($settleDupe['code'] ?? '') === 'FINANCIAL_OPERATION_IN_PROGRESS', 'concurrent same operation should not claim twice');
$settleConflict = wallet_financial_operation_begin('REQ2', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
assert_true(empty($settleConflict['ok']) && ($settleConflict['code'] ?? '') === 'FINANCIAL_OPERATION_CONFLICT', 'success/refund race should conflict');

$commissionClaim = wallet_financial_operation_begin('REQ3', 'BUNDLE_COMMISSION_CREDIT', 'BUNDLE_COMMISSION', $uid, 5.00, 'BDT');
assert_true(!empty($commissionClaim['ok']), 'commission claim should succeed');
$commission = wallet_credit_bundle_subadmin_profit($uid, 5.00, 'REQ3', [], ['financial_operation' => $commissionClaim['claim']]);
assert_true(!empty($commission['ok']), 'commission credit should succeed');
wallet_financial_operation_mark_completed($commissionClaim['claim'], ['final_status' => 'CREDITED']);
$commissionDupe = wallet_financial_operation_begin('REQ3', 'BUNDLE_COMMISSION_CREDIT', 'BUNDLE_COMMISSION', $uid, 5.00, 'BDT');
assert_true(!empty($commissionDupe['duplicate']), 'duplicate commission should replay');
assert_true((float)fb_get('USER_WALLETS/' . $uid)['available_balance'] === 130.00, 'commission should credit once');

$failNextLedgerPut = true;
$ledgerFailClaim = wallet_financial_operation_begin('REQ4', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
assert_true(!empty($ledgerFailClaim['ok']), 'ledger failure claim should start');
$ledgerFail = wallet_refund_hold($uid, 10.00, 'REQ4', 'BUNDLE_REFUND', ['financial_operation' => $ledgerFailClaim['claim']]);
assert_true(empty($ledgerFail['ok']) && ($ledgerFail['code'] ?? '') === 'LEDGER_WRITE_FAILED', 'ledger failure should be reported');
$retry = wallet_financial_operation_begin('REQ4', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
$retryRefund = wallet_refund_hold($uid, 10.00, 'REQ4', 'BUNDLE_REFUND', ['financial_operation' => $retry['claim'] ?? []]);
assert_true(!empty($retryRefund['ok']), 'wallet-applied ledger failure should repair ledger without another mutation');
assert_true((float)fb_get('USER_WALLETS/' . $uid)['available_balance'] === 140.00, 'ledger-failed refund should not repeat on retry');
assert_true(ledger_count($uid) === 3, 'ledger repair should write one deterministic refund row');

echo "wallet idempotency tests passed\n";
