<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
define('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS', 60);

$store = [];
$versions = [];
$failEtagReads = [];
$walletWrites = [];
$testNow = 1700000000;

function test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function test_get(string $path)
{
    global $store;
    $node = $store;
    foreach (test_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function test_set(string $path, $value): void
{
    global $store, $versions;
    $node =& $store;
    foreach (test_parts($path) as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
}

function test_delete(string $path): void
{
    global $store, $versions;
    $parts = test_parts($path);
    $last = array_pop($parts);
    $node =& $store;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            return;
        }
        $node =& $node[$part];
    }
    if ($last !== null) {
        unset($node[$last]);
    }
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
}

function fb_get(string $path)
{
    return test_get($path);
}

function fb_put(string $path, $data): bool
{
    test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $patch): bool
{
    $current = test_get($path);
    test_set($path, array_merge(is_array($current) ? $current : [], $patch));
    return true;
}

function fb_delete(string $path): bool
{
    test_delete($path);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $versions, $failEtagReads;
    if (!empty($failEtagReads[$path])) {
        return ['ok' => false, 'status' => 500, 'etag' => '', 'value' => null];
    }
    return [
        'ok' => true,
        'status' => 200,
        'etag' => 'E' . (string)($versions[$path] ?? 0),
        'value' => test_get($path),
    ];
}

function fb_put_if_match(string $path, $data, string $etag): array
{
    global $versions, $walletWrites;
    if ($etag !== 'E' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    if (str_starts_with($path, 'USER_WALLETS/')) {
        $walletWrites[$path] = (int)($walletWrites[$path] ?? 0) + 1;
    }
    test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function fb_delete_if_match(string $path, string $etag): array
{
    global $versions;
    if ($etag !== 'E' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    test_delete($path);
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

require_once dirname(__DIR__) . '/api/lib/add_money.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function put_wallet(string $uid, float $available, string $currency = 'BDT'): void
{
    test_set('USER_WALLETS/' . $uid, [
        'available_balance' => $available,
        'hold_balance' => 0.0,
        'currency' => $currency,
        'wallet_currency' => $currency,
    ]);
}

function wallet_write_count(string $uid): int
{
    global $walletWrites;
    return (int)($walletWrites['USER_WALLETS/' . $uid] ?? 0);
}

$stalePath = 'ADD_MONEY_TXN_IDS/BKASH/stale';
test_set($stalePath, [
    'uid' => 'UID_OLD',
    'request_id' => 'REQ_OLD',
    'status' => 'RESERVED',
    'created_at' => $testNow - 700,
    'updated_at' => $testNow - 700,
]);
$failEtagReads['ADD_MONEY_REQUESTS/REQ_OLD'] = true;
$unverifiedTakeover = add_money_unique_index_claim($stalePath, 'UID_NEW', 'REQ_NEW', ['status' => 'RESERVED'], true);
assert_true(empty($unverifiedTakeover['ok']) && ($unverifiedTakeover['code'] ?? '') === 'INDEX_REQUEST_CHECK_FAILED', 'request read failure must not be treated as an absent request');
unset($failEtagReads['ADD_MONEY_REQUESTS/REQ_OLD']);
$verifiedTakeover = add_money_unique_index_claim($stalePath, 'UID_NEW', 'REQ_NEW', ['status' => 'RESERVED'], true);
assert_true(!empty($verifiedTakeover['ok']) && !empty($verifiedTakeover['claimed']), 'confirmed orphan stale index should be reclaimed through CAS');
$duplicateTakeover = add_money_unique_index_claim($stalePath, 'UID_NEW', 'REQ_NEW', ['status' => 'RESERVED'], true);
assert_true(!empty($duplicateTakeover['ok']) && !empty($duplicateTakeover['duplicate']), 'same Add Money index owner retry should be idempotent');
$otherTakeover = add_money_unique_index_claim($stalePath, 'UID_OTHER', 'REQ_OTHER', ['status' => 'RESERVED'], true);
assert_true(empty($otherTakeover['ok']) && !empty($otherTakeover['conflict']), 'different Add Money request must not steal a live unique index');

test_set('ADD_MONEY_REQUESTS/REQ_FINAL', [
    'request_id' => 'REQ_FINAL',
    'uid' => 'UID_FINAL',
    'status' => 'APPROVING',
    'amount' => 25.00,
    'currency' => 'BDT',
]);
$finalize = add_money_finalize_request('REQ_FINAL', 'APPROVING', ['status' => 'APPROVED', 'ledger_id' => 'LED_FINAL']);
assert_true(!empty($finalize['ok']), 'Add Money request finalization should use checked CAS');
assert_true((string)(test_get('ADD_MONEY_REQUESTS/REQ_FINAL')['status'] ?? '') === 'APPROVED', 'root Add Money request should be finalized');
assert_true((string)(test_get('ADD_MONEY_BY_USER/UID_FINAL/REQ_FINAL')['status'] ?? '') === 'APPROVED', 'user Add Money mirror should be repaired deterministically');

put_wallet('UID_REPAIR', 10.00);
$repairRef = 'ADD_MONEY_APPROVE:' . hash('sha256', 'REQ_REPAIR');
$repairOperation = wallet_financial_operation_begin($repairRef, 'ADD_MONEY_APPROVAL_CREDIT', 'REQUEST_FINAL', 'UID_REPAIR', 40.00, 'BDT', [
    'request_id' => 'REQ_REPAIR',
]);
assert_true(!empty($repairOperation['ok']), 'Add Money recovery operation should claim');
$repairCredit = wallet_credit_available('UID_REPAIR', 40.00, $repairRef, 'ADD_MONEY', 'Manual add money approved', [
    'ledger_id' => wallet_financial_operation_ledger_id($repairRef, 'ADD_MONEY_APPROVAL_CREDIT'),
    'request_id' => 'REQ_REPAIR',
    'ref_id' => 'REQ_REPAIR',
    'currency' => 'BDT',
], ['financial_operation' => $repairOperation['claim']]);
assert_true(!empty($repairCredit['ok']), 'Add Money recovery setup should credit once');
wallet_financial_operation_mark_failed($repairOperation['claim'], 'REQUEST_FINALIZATION_FAILED', 'simulated finalization failure', [
    'wallet_applied' => true,
    'ledger_written' => true,
    'request_finalized' => false,
]);
$approvedRow = [
    'request_id' => 'REQ_REPAIR',
    'uid' => 'UID_REPAIR',
    'status' => 'APPROVED',
    'amount' => 40.00,
    'currency' => 'BDT',
    'ledger_id' => (string)($repairCredit['ledger_id'] ?? ''),
];
test_set('ADD_MONEY_REQUESTS/REQ_REPAIR', $approvedRow);
$writesBeforeRepair = wallet_write_count('UID_REPAIR');
assert_true(add_money_repair_approved_operation($approvedRow), 'approved Add Money operation should repair ledger/finalization from evidence');
assert_true(wallet_write_count('UID_REPAIR') === $writesBeforeRepair, 'approved operation repair must not credit wallet again');
assert_true((string)(test_get(wallet_financial_operation_scope_path($repairRef, 'REQUEST_FINAL'))['status'] ?? '') === 'COMPLETED', 'repaired Add Money operation should complete');

put_wallet('UID_AMBIGUOUS', 5.00);
$ambiguousRef = 'ADD_MONEY_APPROVE:' . hash('sha256', 'REQ_AMBIGUOUS');
$ambiguousOperation = wallet_financial_operation_begin($ambiguousRef, 'ADD_MONEY_APPROVAL_CREDIT', 'REQUEST_FINAL', 'UID_AMBIGUOUS', 15.00, 'BDT', [
    'request_id' => 'REQ_AMBIGUOUS',
]);
assert_true(!empty($ambiguousOperation['ok']), 'ambiguous operation setup should claim');
$testNow += 61;
$ambiguousRow = [
    'request_id' => 'REQ_AMBIGUOUS',
    'uid' => 'UID_AMBIGUOUS',
    'status' => 'APPROVED',
    'amount' => 15.00,
    'currency' => 'BDT',
];
test_set('ADD_MONEY_REQUESTS/REQ_AMBIGUOUS', $ambiguousRow);
$ambiguousWrites = wallet_write_count('UID_AMBIGUOUS');
assert_true(!add_money_repair_approved_operation($ambiguousRow), 'approved request without wallet evidence must not be auto-completed');
assert_true(wallet_write_count('UID_AMBIGUOUS') === $ambiguousWrites, 'ambiguous approved request must not mutate wallet');
assert_true((string)(test_get(wallet_financial_operation_scope_path($ambiguousRef, 'REQUEST_FINAL'))['status'] ?? '') === 'RECONCILIATION_REQUIRED', 'ambiguous approved request should require reconciliation');

echo "add money safety tests passed\n";
