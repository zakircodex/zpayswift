<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

if (!defined('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS')) {
    define('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS', 60);
}

$store = [];
$versions = [];
$testNow = 1700000000;
$walletWriteCount = [];
$failNextLedgerPut = false;
$failNextDonePut = false;

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

function test_store_delete(string $path): void
{
    global $store, $versions;
    $parts = test_path_parts($path);
    if ($parts === []) {
        return;
    }
    $last = array_pop($parts);
    $node =& $store;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            return;
        }
        $node =& $node[$part];
    }
    unset($node[$last]);
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
    global $failNextLedgerPut, $failNextDonePut;
    if ($failNextLedgerPut && str_starts_with($path, 'WALLET_LEDGER/')) {
        $failNextLedgerPut = false;
        return false;
    }
    if ($failNextDonePut && str_starts_with($path, 'MFS_REQUESTS/DONE/')) {
        $failNextDonePut = false;
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
    test_store_delete($path);
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

function app_api_url(): string
{
    return 'https://example.test/api';
}

function fcm_send_to_user(string $uid, string $title, string $body, array $data = [], string $idempotencyKey = ''): array
{
    return ['ok' => true, 'sent' => 0, 'failed' => 0, 'code' => 'TEST_STUB'];
}

require_once dirname(__DIR__) . '/api/lib/mfs.php';

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

function put_wallet(string $uid, float $available, float $hold, string $currency = 'BDT'): void
{
    fb_put('USER_WALLETS/' . $uid, [
        'available_balance' => $available,
        'hold_balance' => $hold,
        'wallet_currency' => $currency,
        'currency' => $currency,
        'total_mfs_spent' => 0.00,
        'total_refund' => 0.00,
    ]);
}

function put_user(string $uid): void
{
    fb_put('USERS/' . $uid, [
        'uid' => $uid,
        'full_name' => 'Test User',
        'name' => 'Test User',
        'phone' => '60123456789',
    ]);
}

function put_mfs_request(string $requestId, string $uid, float $heldAmount, string $currency = 'BDT', string $bucket = 'PENDING'): void
{
    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'provider' => 'BKASH',
        'provider_name' => 'bKash',
        'service_type' => 'SEND_MONEY',
        'service_name' => 'Send Money',
        'receiver_number' => '01700000000',
        'number' => '01700000000',
        'country_code' => 'BD',
        'service_mode' => 'LOCAL',
        'wallet_currency' => $currency,
        'wallet_debit_currency' => $currency,
        'amount_bdt' => $currency === 'MYR' ? 350.00 : $heldAmount,
        'amount_rm' => $currency === 'MYR' ? $heldAmount : 0.00,
        'fee_amount' => 0.00,
        'total_debit' => $heldAmount,
        'total_pay' => $heldAmount,
        'wallet_debit' => $heldAmount,
        'held_amount' => $heldAmount,
        'wallet_hold_amount' => $heldAmount,
        'total_debit_text' => $currency === 'MYR' ? 'RM ' . number_format($heldAmount, 2, '.', '') : number_format($heldAmount, 2, '.', '') . ' BDT',
        'status' => $bucket === 'DONE' ? 'SUCCESSFUL' : 'PENDING',
        'public_status' => $bucket === 'DONE' ? 'SUCCESSFUL' : 'PENDING',
        'process_status' => $bucket === 'DONE' ? 'SUCCESSFUL' : 'PENDING',
        'hold_settlement_status' => 'PENDING',
        'created_at' => now_ts(),
        'updated_at' => now_ts(),
    ];
    fb_put('MFS_REQUESTS/' . strtoupper($bucket) . '/' . $requestId, $row);
}

function wallet_write_count(string $uid): int
{
    global $walletWriteCount;
    return (int)($walletWriteCount['USER_WALLETS/' . $uid] ?? 0);
}

function ledger_rows(string $uid, string $type = ''): array
{
    $rows = fb_get('WALLET_LEDGER/' . $uid . '/' . month_key());
    if (!is_array($rows)) {
        return [];
    }
    $items = array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    if ($type !== '') {
        $items = array_values(array_filter($items, static fn(array $row): bool => (string)($row['type'] ?? '') === $type));
    }
    return $items;
}

function history_count(string $uid): int
{
    $rows = fb_get('MFS_HISTORY/' . $uid . '/' . month_key());
    return is_array($rows) ? count(array_filter($rows, static fn($row): bool => is_array($row))) : 0;
}

function notification_count(string $uid): int
{
    $rows = fb_get('USER_NOTIFICATIONS/' . $uid);
    return is_array($rows) ? count(array_filter($rows, static fn($row): bool => is_array($row))) : 0;
}

$uid = 'U_MFS';
put_user($uid);

put_wallet($uid, 100.00, 25.00, 'BDT');
put_mfs_request('MFS_SUCCESS_ONCE', $uid, 25.00, 'BDT');
$success = mfs_mark_success('MFS_SUCCESS_ONCE', 'ok', 'TRX1');
assert_true(!empty($success['ok']), 'MFS success should finalize');
$wallet = fb_get('USER_WALLETS/' . $uid);
assert_true((float)$wallet['hold_balance'] === 0.00, 'MFS success should settle hold once');
assert_true((float)$wallet['total_mfs_spent'] === 25.00, 'MFS success should increment total_mfs_spent once');
assert_true(count(ledger_rows($uid, 'MFS_SUCCESS')) === 1, 'MFS success should write one deterministic ledger');
assert_true((string)(fb_get(op_path('MFS_SUCCESS_ONCE'))['status'] ?? '') === 'COMPLETED', 'MFS success operation should complete');
$writesAfterSuccess = wallet_write_count($uid);
$historyAfterSuccess = history_count($uid);
$notificationsAfterSuccess = notification_count($uid);
$dupeSuccess = mfs_mark_success('MFS_SUCCESS_ONCE', 'ok again', 'TRX2');
assert_true(empty($dupeSuccess['ok']) && ($dupeSuccess['code'] ?? '') === 'ALREADY_COMPLETED', 'duplicate success should return existing completed state');
assert_true(wallet_write_count($uid) === $writesAfterSuccess, 'duplicate success must not write wallet');
assert_true(count(ledger_rows($uid, 'MFS_SUCCESS')) === 1, 'duplicate success must not duplicate ledger');
assert_true(history_count($uid) === $historyAfterSuccess, 'duplicate success must not duplicate history');
assert_true(notification_count($uid) === $notificationsAfterSuccess, 'duplicate success must not duplicate notification');

put_wallet($uid, 50.00, 11.25, 'MYR');
put_mfs_request('MFS_FAIL_ONCE', $uid, 11.25, 'MYR');
$fail = mfs_mark_failed('MFS_FAIL_ONCE', 'failed');
assert_true(!empty($fail['ok']), 'MFS failure should finalize');
$wallet = fb_get('USER_WALLETS/' . $uid);
assert_true((float)$wallet['available_balance'] === 61.25, 'MFS MYR failure should refund exact original MYR debit');
assert_true((float)$wallet['hold_balance'] === 0.00, 'MFS MYR failure should clear hold');
assert_true((float)$wallet['total_refund'] === 11.25, 'MFS MYR failure should increment total_refund once');
assert_true(count(ledger_rows($uid, 'MFS_FAILED_RELEASE')) === 1, 'MFS failure should write one deterministic refund ledger');
$writesAfterFail = wallet_write_count($uid);
$historyAfterFail = history_count($uid);
$notificationsAfterFail = notification_count($uid);
$dupeFail = mfs_mark_failed('MFS_FAIL_ONCE', 'failed again');
assert_true(empty($dupeFail['ok']) && ($dupeFail['code'] ?? '') === 'ALREADY_COMPLETED', 'duplicate failure should return existing completed state');
assert_true(wallet_write_count($uid) === $writesAfterFail, 'duplicate failure must not write wallet');
assert_true(count(ledger_rows($uid, 'MFS_FAILED_RELEASE')) === 1, 'duplicate failure must not duplicate ledger');
assert_true(history_count($uid) === $historyAfterFail, 'duplicate failure must not duplicate history');
assert_true(notification_count($uid) === $notificationsAfterFail, 'duplicate failure must not duplicate notification');

put_wallet($uid, 80.00, 20.00, 'BDT');
put_mfs_request('MFS_RACE', $uid, 20.00, 'BDT');
$raceSuccess = mfs_mark_success('MFS_RACE', 'ok', 'TRX3');
assert_true(!empty($raceSuccess['ok']), 'race setup success should finalize');
$raceFailure = mfs_mark_failed('MFS_RACE', 'late failure');
assert_true(empty($raceFailure['ok']) && ($raceFailure['code'] ?? '') === 'ALREADY_COMPLETED', 'failure after success must not refund');
$wallet = fb_get('USER_WALLETS/' . $uid);
assert_true((float)$wallet['available_balance'] === 80.00, 'failure after success must not change available balance');
assert_true((float)$wallet['total_mfs_spent'] === 20.00, 'failure after success must not alter settlement total twice');

put_wallet($uid, 90.00, 10.00, 'BDT');
put_mfs_request('MFS_FINAL_RETRY', $uid, 10.00, 'BDT');
$failNextDonePut = true;
$finalFail = mfs_mark_success('MFS_FINAL_RETRY', 'ok', 'TRX4');
assert_true(empty($finalFail['ok']) && ($finalFail['code'] ?? '') === 'REQUEST_FINALIZATION_FAILED', 'DONE write failure should be reported');
$wallet = fb_get('USER_WALLETS/' . $uid);
assert_true((float)$wallet['hold_balance'] === 0.00, 'finalization failure occurs after wallet settlement');
assert_true((string)(fb_get(op_path('MFS_FINAL_RETRY'))['status'] ?? '') === 'FAILED_RETRYABLE', 'finalization failure should remain retryable');
$writesBeforeRetry = wallet_write_count($uid);
$finalRetry = mfs_mark_success('MFS_FINAL_RETRY', 'ok', 'TRX4');
assert_true(!empty($finalRetry['ok']), 'finalization retry should succeed');
assert_true(wallet_write_count($uid) === $writesBeforeRetry, 'finalization retry must not settle wallet again');
assert_true(count(ledger_rows($uid, 'MFS_SUCCESS')) === 3, 'finalization retry must reuse deterministic ledger only');

put_wallet($uid, 70.00, 7.00, 'BDT');
put_mfs_request('MFS_LEDGER_RETRY', $uid, 7.00, 'BDT');
$failNextLedgerPut = true;
$ledgerFail = mfs_mark_failed('MFS_LEDGER_RETRY', 'failed');
assert_true(empty($ledgerFail['ok']) && ($ledgerFail['code'] ?? '') === 'LEDGER_WRITE_FAILED', 'ledger failure should be reported');
$wallet = fb_get('USER_WALLETS/' . $uid);
assert_true((float)$wallet['available_balance'] === 77.00, 'wallet should refund once before ledger failure report');
$writesBeforeLedgerRetry = wallet_write_count($uid);
$ledgerRetry = mfs_mark_failed('MFS_LEDGER_RETRY', 'failed');
assert_true(!empty($ledgerRetry['ok']), 'ledger retry should finalize');
assert_true(wallet_write_count($uid) === $writesBeforeLedgerRetry, 'ledger retry must not refund wallet again');
assert_true((float)fb_get('USER_WALLETS/' . $uid)['available_balance'] === 77.00, 'ledger retry must preserve exact refunded balance');

put_wallet($uid, 60.00, 6.00, 'BDT');
put_mfs_request('MFS_LEASE', $uid, 6.00, 'BDT');
$active = wallet_financial_operation_begin('MFS_LEASE', 'MFS_SUCCESS', 'REQUEST_FINAL', $uid, 6.00, 'BDT');
assert_true(!empty($active['ok']), 'manual active MFS claim should start');
$inProgress = mfs_mark_success('MFS_LEASE', 'ok', 'TRX5');
assert_true(empty($inProgress['ok']) && ($inProgress['code'] ?? '') === 'FINANCIAL_OPERATION_IN_PROGRESS', 'active MFS claim cannot be stolen');
$testNow += 61;
$leaseRetry = mfs_mark_success('MFS_LEASE', 'ok', 'TRX5');
assert_true(!empty($leaseRetry['ok']), 'expired MFS claim should recover');
assert_true(!wallet_financial_operation_mark_applied($active['claim'], ['old_owner_test' => true]), 'old MFS claim owner cannot update after takeover');

assert_true(history_count($uid) >= 5, 'MFS history should use deterministic request ids');
assert_true(notification_count($uid) >= 5, 'MFS notifications should use existing idempotent notification rows');

echo "mfs wallet recovery tests passed\n";
