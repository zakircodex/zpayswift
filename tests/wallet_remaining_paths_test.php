<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

if (!defined('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS')) {
    define('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS', 60);
}

$store = [];
$versions = [];
$walletWriteCount = [];
$testNow = 1700000000;

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

function fb_get(string $path)
{
    return test_store_get($path);
}

function fb_put(string $path, $data): bool
{
    test_store_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    $current = test_store_get($path);
    if (!is_array($current)) {
        $current = [];
    }
    test_store_set($path, array_merge($current, $data));
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
    global $versions, $walletWriteCount;
    $expected = 'v' . (string)($versions[$path] ?? 0);
    if ($etag !== $expected) {
        return ['ok' => false, 'status' => 412];
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

function put_wallet(string $uid, float $available, float $hold = 0.0, string $currency = 'BDT'): void
{
    fb_put('USER_WALLETS/' . $uid, [
        'available_balance' => $available,
        'hold_balance' => $hold,
        'wallet_currency' => $currency,
        'currency' => $currency,
    ]);
}

function wallet_writes(string $uid): int
{
    global $walletWriteCount;
    return (int)($walletWriteCount['USER_WALLETS/' . $uid] ?? 0);
}

function ledger_count_for(string $uid): int
{
    $rows = fb_get('WALLET_LEDGER/' . $uid . '/' . month_key());
    return is_array($rows) ? count(array_filter($rows, static fn($row): bool => is_array($row))) : 0;
}

put_wallet('SA', 100.00);
put_wallet('TA', 10.00);

$transfer = wallet_financial_operation_begin(
    'ADMIN_ADD_RECOVERY',
    'SUBADMIN_BALANCE_TRANSFER',
    'REQUEST_FINAL',
    'TA',
    25.00,
    'BDT',
    ['actor_uid' => 'SA', 'target_uid' => 'TA']
);
assert_true(!empty($transfer['ok']), 'two-sided operation should claim');

$source = wallet_apply_available_delta_with_operation(
    $transfer['claim'],
    'SA',
    25.00,
    'DEBIT',
    'ADMIN_ADD_RECOVERY',
    'SUBADMIN_BALANCE_TRANSFER',
    'source debit',
    ['ledger_id' => wallet_financial_operation_side_ledger_id('ADMIN_ADD_RECOVERY', 'SUBADMIN_BALANCE_TRANSFER', 'source_debited')],
    'source_debited'
);
assert_true(!empty($source['ok']), 'source debit should apply');

$target = wallet_apply_available_delta_with_operation(
    $transfer['claim'],
    'TA',
    25.00,
    'CREDIT',
    'ADMIN_ADD_RECOVERY',
    'SUBADMIN_BALANCE_TRANSFER',
    'target credit',
    ['ledger_id' => wallet_financial_operation_side_ledger_id('ADMIN_ADD_RECOVERY', 'SUBADMIN_BALANCE_TRANSFER', 'target_credited')],
    'target_credited'
);
assert_true(!empty($target['ok']), 'target credit should apply');
assert_true((float)fb_get('USER_WALLETS/SA')['available_balance'] === 75.00, 'source debited exact amount');
assert_true((float)fb_get('USER_WALLETS/TA')['available_balance'] === 35.00, 'target credited exact amount');
assert_true(ledger_count_for('SA') === 1 && ledger_count_for('TA') === 1, 'two-sided operation should write deterministic ledgers once');

$sourceWrites = wallet_writes('SA');
$targetWrites = wallet_writes('TA');
$sourceReplay = wallet_apply_available_delta_with_operation($transfer['claim'], 'SA', 25.00, 'DEBIT', 'ADMIN_ADD_RECOVERY', 'SUBADMIN_BALANCE_TRANSFER', 'source debit', [], 'source_debited');
$targetReplay = wallet_apply_available_delta_with_operation($transfer['claim'], 'TA', 25.00, 'CREDIT', 'ADMIN_ADD_RECOVERY', 'SUBADMIN_BALANCE_TRANSFER', 'target credit', [], 'target_credited');
assert_true(!empty($sourceReplay['ok']) && !empty($targetReplay['ok']), 'side replay should be idempotent');
assert_true(wallet_writes('SA') === $sourceWrites && wallet_writes('TA') === $targetWrites, 'side replay must not write wallets again');

put_wallet('HU', 80.00, 0.00);
$holdClaim = wallet_financial_operation_begin('API_HOLD_RECOVERY', 'API_TOPUP_CREATE_HOLD', 'REQUEST_CREATE', 'HU', 30.00, 'BDT');
assert_true(!empty($holdClaim['ok']), 'create hold should claim');
$hold = wallet_hold_amount('HU', 30.00, 'API_HOLD_RECOVERY', 'API_TOPUP_HOLD', [
    'financial_operation' => $holdClaim['claim'],
    'ledger_extra' => [
        'ledger_id' => wallet_financial_operation_ledger_id('API_HOLD_RECOVERY', 'API_TOPUP_CREATE_HOLD'),
        'request_id' => 'REQ_PUBLIC',
        'ref_id' => 'REQ_PUBLIC',
    ],
]);
assert_true(!empty($hold['ok']), 'create hold should apply');
assert_true((float)fb_get('USER_WALLETS/HU')['available_balance'] === 50.00, 'hold debits available once');
assert_true((float)fb_get('USER_WALLETS/HU')['hold_balance'] === 30.00, 'hold balance increases once');
wallet_financial_operation_mark_failed($holdClaim['claim'], 'REQUEST_CREATE_FAILED', 'simulated request save failure', [
    'wallet_applied' => true,
    'ledger_written' => true,
]);
$holdWrites = wallet_writes('HU');
$retryClaim = wallet_financial_operation_begin('API_HOLD_RECOVERY', 'API_TOPUP_CREATE_HOLD', 'REQUEST_CREATE', 'HU', 30.00, 'BDT');
assert_true(!empty($retryClaim['ok']), 'failed create hold should reclaim');
$holdRetry = wallet_hold_amount('HU', 30.00, 'API_HOLD_RECOVERY', 'API_TOPUP_HOLD', ['financial_operation' => $retryClaim['claim']]);
assert_true(!empty($holdRetry['ok']), 'retry should repair/replay hold');
assert_true(wallet_writes('HU') === $holdWrites, 'retry must not repeat hold wallet mutation');
assert_true(ledger_count_for('HU') === 1, 'retry must not duplicate hold ledger');

put_wallet('AHU', 120.00, 0.00, 'MYR');
$androidHold = wallet_financial_operation_begin('ANDROID_TOPUP_CREATE_RECOVERY', 'ANDROID_TOPUP_CREATE_HOLD', 'REQUEST_CREATE', 'AHU', 18.75, 'MYR');
assert_true(!empty($androidHold['ok']), 'Android create hold should claim');
$androidHoldResult = wallet_hold_amount('AHU', 18.75, 'ANDROID_TOPUP_CREATE_RECOVERY', 'ANDROID_TOPUP_HOLD', [
    'financial_operation' => $androidHold['claim'],
    'ledger_extra' => [
        'ledger_id' => wallet_financial_operation_ledger_id('ANDROID_TOPUP_CREATE_RECOVERY', 'ANDROID_TOPUP_CREATE_HOLD'),
        'request_id' => 'TP_ANDROID',
        'ref_id' => 'TP_ANDROID',
        'wallet_currency' => 'MYR',
    ],
]);
assert_true(!empty($androidHoldResult['ok']), 'Android create hold should apply');
wallet_financial_operation_mark_failed($androidHold['claim'], 'REQUEST_CREATE_FAILED', 'simulated Android request save failure', [
    'wallet_applied' => true,
    'ledger_written' => true,
]);
$androidWrites = wallet_writes('AHU');
$androidRetry = wallet_financial_operation_begin('ANDROID_TOPUP_CREATE_RECOVERY', 'ANDROID_TOPUP_CREATE_HOLD', 'REQUEST_CREATE', 'AHU', 18.75, 'MYR');
assert_true(!empty($androidRetry['ok']), 'Android failed create hold should reclaim');
$androidReplay = wallet_hold_amount('AHU', 18.75, 'ANDROID_TOPUP_CREATE_RECOVERY', 'ANDROID_TOPUP_HOLD', ['financial_operation' => $androidRetry['claim']]);
assert_true(!empty($androidReplay['ok']), 'Android retry should replay hold evidence');
assert_true(wallet_writes('AHU') === $androidWrites, 'Android retry must not repeat hold mutation');
assert_true((float)fb_get('USER_WALLETS/AHU')['available_balance'] === 101.25, 'Android MYR hold remains exact');

put_wallet('AMU', 10.00, 0.00, 'BDT');
$approval = wallet_financial_operation_begin('ADD_MONEY_APPROVE_RECOVERY', 'ADD_MONEY_APPROVAL_CREDIT', 'REQUEST_FINAL', 'AMU', 40.00, 'BDT');
assert_true(!empty($approval['ok']), 'Add Money approval credit should claim');
$credit = wallet_credit_available('AMU', 40.00, 'ADD_MONEY_APPROVE_RECOVERY', 'ADD_MONEY', 'Manual add money approved', [
    'ledger_id' => wallet_financial_operation_ledger_id('ADD_MONEY_APPROVE_RECOVERY', 'ADD_MONEY_APPROVAL_CREDIT'),
    'request_id' => 'AM_REQ',
    'ref_id' => 'AM_REQ',
    'currency' => 'BDT',
    'wallet_currency' => 'BDT',
], [
    'financial_operation' => $approval['claim'],
]);
assert_true(!empty($credit['ok']), 'Add Money approval should credit wallet');
assert_true((float)fb_get('USER_WALLETS/AMU')['available_balance'] === 50.00, 'Add Money approval credits exact amount once');
wallet_financial_operation_mark_failed($approval['claim'], 'REQUEST_FINALIZATION_FAILED', 'simulated Add Money finalization failure', [
    'wallet_applied' => true,
    'ledger_written' => true,
]);
$creditWrites = wallet_writes('AMU');
$approvalRetry = wallet_financial_operation_begin('ADD_MONEY_APPROVE_RECOVERY', 'ADD_MONEY_APPROVAL_CREDIT', 'REQUEST_FINAL', 'AMU', 40.00, 'BDT');
assert_true(!empty($approvalRetry['ok']), 'Add Money finalization failure should reclaim');
$creditReplay = wallet_credit_available('AMU', 40.00, 'ADD_MONEY_APPROVE_RECOVERY', 'ADD_MONEY', 'Manual add money approved', [], [
    'financial_operation' => $approvalRetry['claim'],
]);
assert_true(!empty($creditReplay['ok']), 'Add Money retry should repair/replay credit evidence');
assert_true(wallet_writes('AMU') === $creditWrites, 'Add Money retry must not credit wallet again');
assert_true(ledger_count_for('AMU') === 1, 'Add Money retry must not duplicate credit ledger');
wallet_financial_operation_mark_completed($approvalRetry['claim'], [
    'wallet_applied' => true,
    'ledger_written' => true,
    'request_finalized' => true,
]);
$approvalDuplicate = wallet_financial_operation_begin('ADD_MONEY_APPROVE_RECOVERY', 'ADD_MONEY_APPROVAL_CREDIT', 'REQUEST_FINAL', 'AMU', 40.00, 'BDT');
assert_true(!empty($approvalDuplicate['duplicate']) && !empty($approvalDuplicate['completed']), 'Add Money duplicate approval should replay completed operation');

echo "wallet remaining path tests passed\n";
