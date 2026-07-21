<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

if (!defined('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS')) {
    define('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS', 60);
}

$store = [];
$versions = [];
$failNextLedgerPut = false;
$testNow = 1700000000;
$walletWriteCount = [];

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
    global $versions, $walletWriteCount, $failNextLedgerPut;
    $expected = 'v' . (string)($versions[$path] ?? 0);
    if ($etag !== $expected) {
        return ['ok' => false, 'status' => 412];
    }
    if ($failNextLedgerPut && str_starts_with($path, 'WALLET_LEDGER/')) {
        $failNextLedgerPut = false;
        return ['ok' => false, 'status' => 500];
    }
    if (str_starts_with($path, 'USER_WALLETS/')) {
        $walletWriteCount[$path] = ($walletWriteCount[$path] ?? 0) + 1;
    }
    test_store_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function now_ts(): int
{
    global $testNow;
    return $testNow;
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

function op_path(string $requestId, string $scope = 'REQUEST_FINAL'): string
{
    return 'WALLET_FINANCIAL_OPERATIONS/' . hash('sha256', $requestId) . '/' . $scope;
}

function put_wallet(string $uid, float $available, float $hold): void
{
    fb_put('USER_WALLETS/' . $uid, [
        'available_balance' => $available,
        'hold_balance' => $hold,
        'wallet_currency' => 'BDT',
        'currency' => 'BDT',
    ]);
}

function wallet_write_count(string $uid): int
{
    global $walletWriteCount;
    return (int)($walletWriteCount['USER_WALLETS/' . $uid] ?? 0);
}

function ledger_count(string $uid): int
{
    $rows = fb_get('WALLET_LEDGER/' . $uid . '/' . month_key());
    return is_array($rows) ? count(array_filter($rows, static fn($row): bool => is_array($row))) : 0;
}

$uid = 'U_RECOVERY';
put_wallet($uid, 100.00, 50.00);

$active = wallet_financial_operation_begin('REC_ACTIVE', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 5.00, 'BDT');
assert_true(!empty($active['ok']), 'active claim should start');
$activeDupe = wallet_financial_operation_begin('REC_ACTIVE', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 5.00, 'BDT');
assert_true(empty($activeDupe['ok']) && ($activeDupe['code'] ?? '') === 'FINANCIAL_OPERATION_IN_PROGRESS', 'active CLAIMED should not be stolen');

$testNow += 61;
$reclaimed = wallet_financial_operation_begin('REC_ACTIVE', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 5.00, 'BDT');
assert_true(!empty($reclaimed['ok']), 'expired CLAIMED should be reclaimed');
assert_true((int)($reclaimed['claim']['attempt_count'] ?? 0) === 2, 'reclaim should increment attempt count');
assert_true(!wallet_financial_operation_mark_applied($active['claim'], ['old_owner_test' => true]), 'old owner should not update after takeover');
$writesBeforeOldOwnerMutation = wallet_write_count($uid);
$oldOwnerMutation = wallet_refund_hold($uid, 5.00, 'REC_ACTIVE', 'TOPUP_REFUND', [
    'financial_operation' => $active['claim'],
]);
assert_true(empty($oldOwnerMutation['ok']) && ($oldOwnerMutation['code'] ?? '') === 'FINANCIAL_OPERATION_OWNER_MISMATCH', 'old owner must not mutate wallet after takeover');
assert_true(wallet_write_count($uid) === $writesBeforeOldOwnerMutation, 'old owner rejection must happen before wallet write');
assert_true(wallet_financial_operation_mark_applied($reclaimed['claim'], ['new_owner_test' => true]), 'new owner should update after takeover');
wallet_financial_operation_mark_completed($reclaimed['claim'], ['final_status' => 'FAILED']);
$completedDupe = wallet_financial_operation_begin('REC_ACTIVE', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 5.00, 'BDT');
assert_true(!empty($completedDupe['duplicate']), 'COMPLETED operation should not be reclaimed');

$failNextLedgerPut = true;
$ledgerClaim = wallet_financial_operation_begin('REC_LEDGER', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
assert_true(!empty($ledgerClaim['ok']), 'ledger repair claim should start');
$ledgerFail = wallet_refund_hold($uid, 10.00, 'REC_LEDGER', 'BUNDLE_REFUND', ['financial_operation' => $ledgerClaim['claim']]);
assert_true(empty($ledgerFail['ok']) && ($ledgerFail['code'] ?? '') === 'LEDGER_WRITE_FAILED', 'ledger failure should be reported after wallet apply');
$walletAfterLedgerFailure = fb_get('USER_WALLETS/' . $uid);
assert_true((float)$walletAfterLedgerFailure['available_balance'] === 110.00, 'ledger failure should apply wallet once');
$writesAfterLedgerFailure = wallet_write_count($uid);
$testNow += 1;
$ledgerRetry = wallet_financial_operation_begin('REC_LEDGER', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 10.00, 'BDT');
assert_true(!empty($ledgerRetry['ok']), 'FAILED_RETRYABLE wallet-applied operation should reclaim');
$ledgerRepair = wallet_refund_hold($uid, 10.00, 'REC_LEDGER', 'BUNDLE_REFUND', ['financial_operation' => $ledgerRetry['claim']]);
assert_true(!empty($ledgerRepair['ok']), 'retry should repair deterministic ledger');
assert_true(wallet_write_count($uid) === $writesAfterLedgerFailure, 'ledger repair must not write wallet again');
assert_true((float)fb_get('USER_WALLETS/' . $uid)['available_balance'] === 110.00, 'ledger repair must not refund twice');
assert_true(ledger_count($uid) === 1, 'ledger repair should write one row');

$conflictClaim = wallet_financial_operation_begin('REC_CONFLICT', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 7.00, 'BDT');
assert_true(!empty($conflictClaim['ok']), 'conflict claim should start');
$failNextLedgerPut = true;
$conflictApply = wallet_refund_hold($uid, 7.00, 'REC_CONFLICT', 'BUNDLE_REFUND', ['financial_operation' => $conflictClaim['claim']]);
assert_true(empty($conflictApply['ok']), 'conflict setup should fail ledger write');
$ledgerId = wallet_financial_operation_ledger_id('REC_CONFLICT', 'BUNDLE_REFUND');
fb_put('WALLET_LEDGER/' . $uid . '/' . month_key() . '/' . $ledgerId, [
    'ledger_id' => $ledgerId,
    'uid' => $uid,
    'type' => 'BUNDLE_REFUND',
    'direction' => 'RELEASE_HOLD',
    'amount' => 999.00,
    'currency' => 'BDT',
    'ref_id' => 'REC_CONFLICT',
    'created_at' => now_ts(),
]);
$conflictRetry = wallet_financial_operation_begin('REC_CONFLICT', 'BUNDLE_REFUND', 'REQUEST_FINAL', $uid, 7.00, 'BDT');
$conflictRepair = wallet_refund_hold($uid, 7.00, 'REC_CONFLICT', 'BUNDLE_REFUND', ['financial_operation' => $conflictRetry['claim']]);
assert_true(empty($conflictRepair['ok']) && ($conflictRepair['code'] ?? '') === 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED', 'conflicting ledger should require reconciliation');
assert_true((string)(fb_get(op_path('REC_CONFLICT'))['status'] ?? '') === 'RECONCILIATION_REQUIRED', 'conflict should mark operation reconciliation required');

$finalClaim = wallet_financial_operation_begin('REC_FINAL', 'TOPUP_SUCCESS', 'REQUEST_FINAL', $uid, 8.00, 'BDT');
assert_true(!empty($finalClaim['ok']), 'finalization claim should start');
$settle = wallet_settle_hold($uid, 8.00, 'REC_FINAL', 'TOPUP_SETTLE', ['financial_operation' => $finalClaim['claim']]);
assert_true(!empty($settle['ok']), 'settlement should apply');
wallet_financial_operation_mark_failed($finalClaim['claim'], 'REQUEST_FINALIZATION_FAILED', 'simulated done-bucket failure', [
    'wallet_applied' => true,
    'ledger_written' => true,
    'request_finalized' => false,
]);
$writesBeforeFinalRetry = wallet_write_count($uid);
$finalRetry = wallet_financial_operation_begin('REC_FINAL', 'TOPUP_SUCCESS', 'REQUEST_FINAL', $uid, 8.00, 'BDT');
$settleRetry = wallet_settle_hold($uid, 8.00, 'REC_FINAL', 'TOPUP_SETTLE', ['financial_operation' => $finalRetry['claim']]);
assert_true(!empty($settleRetry['ok']), 'finalization retry should reuse wallet/ledger evidence');
assert_true(wallet_write_count($uid) === $writesBeforeFinalRetry, 'finalization retry must not settle wallet again');
wallet_financial_operation_mark_applied($finalRetry['claim'], [
    'wallet_applied' => true,
    'ledger_written' => true,
    'request_finalized' => true,
]);
wallet_financial_operation_mark_completed($finalRetry['claim'], ['final_status' => 'SUCCESS']);
assert_true((string)(fb_get(op_path('REC_FINAL'))['status'] ?? '') === 'COMPLETED', 'finalization retry should complete operation');

put_wallet('U_BIND_OTHER', 50.00, 10.00);
$bindingClaim = wallet_financial_operation_begin('REC_BINDING', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 6.00, 'BDT');
assert_true(!empty($bindingClaim['ok']), 'binding claim should start');
$wrongUid = wallet_refund_hold('U_BIND_OTHER', 6.00, 'REC_BINDING', 'TOPUP_REFUND', [
    'financial_operation' => $bindingClaim['claim'],
]);
assert_true(empty($wrongUid['ok']) && ($wrongUid['code'] ?? '') === 'FINANCIAL_OPERATION_BINDING_CONFLICT', 'wrong wallet UID must be rejected');
$wrongAmount = wallet_refund_hold($uid, 6.50, 'REC_BINDING', 'TOPUP_REFUND', [
    'financial_operation' => $bindingClaim['claim'],
]);
assert_true(empty($wrongAmount['ok']) && ($wrongAmount['code'] ?? '') === 'FINANCIAL_OPERATION_BINDING_CONFLICT', 'wrong wallet amount must be rejected');
$wrongReference = wallet_refund_hold($uid, 6.00, 'REC_BINDING_OTHER', 'TOPUP_REFUND', [
    'financial_operation' => $bindingClaim['claim'],
]);
assert_true(empty($wrongReference['ok']) && ($wrongReference['code'] ?? '') === 'FINANCIAL_OPERATION_BINDING_CONFLICT', 'wrong request reference must be rejected');

$currencyClaim = wallet_financial_operation_begin('REC_BINDING_CURRENCY', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 6.00, 'MYR');
assert_true(!empty($currencyClaim['ok']), 'currency binding claim should start');
$wrongCurrency = wallet_refund_hold($uid, 6.00, 'REC_BINDING_CURRENCY', 'TOPUP_REFUND', [
    'financial_operation' => $currencyClaim['claim'],
]);
assert_true(empty($wrongCurrency['ok']) && ($wrongCurrency['code'] ?? '') === 'FINANCIAL_OPERATION_BINDING_CONFLICT', 'wrong wallet currency must be rejected');

$legacyClaimPath = op_path('REC_LEGACY_CLAIM');
fb_put($legacyClaimPath, [
    'request_id' => 'REC_LEGACY_CLAIM',
    'operation_type' => 'TOPUP_REFUND',
    'scope' => 'REQUEST_FINAL',
    'status' => 'CLAIMED',
    'uid' => $uid,
    'amount' => 3.00,
    'currency' => 'BDT',
    'created_at' => now_ts() - 1000,
]);
$legacyClaim = wallet_financial_operation_begin('REC_LEGACY_CLAIM', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 3.00, 'BDT');
assert_true(empty($legacyClaim['ok']) && ($legacyClaim['code'] ?? '') === 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED', 'legacy CLAIMED without lease should be conservative');

$legacyCompletedPath = op_path('REC_LEGACY_DONE');
fb_put($legacyCompletedPath, [
    'request_id' => 'REC_LEGACY_DONE',
    'operation_type' => 'TOPUP_REFUND',
    'scope' => 'REQUEST_FINAL',
    'status' => 'COMPLETED',
    'uid' => $uid,
    'amount' => 3.00,
    'currency' => 'BDT',
    'created_at' => now_ts() - 1000,
]);
$legacyDone = wallet_financial_operation_begin('REC_LEGACY_DONE', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 3.00, 'BDT');
assert_true(!empty($legacyDone['duplicate']), 'legacy COMPLETED should replay as duplicate');

$legacyAppliedPath = op_path('REC_LEGACY_APPLIED');
$legacyLedgerId = wallet_financial_operation_ledger_id('REC_LEGACY_APPLIED', 'TOPUP_REFUND');
fb_put($legacyAppliedPath, [
    'request_id' => 'REC_LEGACY_APPLIED',
    'operation_type' => 'TOPUP_REFUND',
    'scope' => 'REQUEST_FINAL',
    'status' => 'APPLIED',
    'uid' => $uid,
    'amount' => 4.00,
    'currency' => 'BDT',
    'wallet_applied' => true,
    'ledger_id' => $legacyLedgerId,
    'ledger_row' => [
        'ledger_id' => $legacyLedgerId,
        'type' => 'TOPUP_REFUND',
        'direction' => 'RELEASE_HOLD',
        'amount' => 4.00,
        'currency' => 'BDT',
        'wallet_currency' => 'BDT',
        'before_available' => 117.00,
        'after_available' => 121.00,
        'before_hold' => 32.00,
        'after_hold' => 28.00,
        'ref_id' => 'REC_LEGACY_APPLIED',
        'created_at' => now_ts() - 1000,
    ],
    'claimed_at' => now_ts() - 1000,
]);
$legacyApplied = wallet_financial_operation_begin('REC_LEGACY_APPLIED', 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, 4.00, 'BDT');
assert_true(!empty($legacyApplied['ok']), 'legacy APPLIED should enter repair path');
$writesBeforeLegacyRepair = wallet_write_count($uid);
$legacyRepair = wallet_refund_hold($uid, 4.00, 'REC_LEGACY_APPLIED', 'TOPUP_REFUND', ['financial_operation' => $legacyApplied['claim']]);
assert_true(!empty($legacyRepair['ok']), 'legacy APPLIED should repair ledger without wallet mutation');
assert_true(wallet_write_count($uid) === $writesBeforeLegacyRepair, 'legacy APPLIED repair must not write wallet');

echo "wallet recovery tests passed\n";
