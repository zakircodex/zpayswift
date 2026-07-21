<?php
declare(strict_types=1);

$GLOBALS['fb_store'] = [];
$GLOBALS['fb_etags'] = [];
$GLOBALS['wallet_writes'] = [];
$GLOBALS['fail_put_once'] = [];
$GLOBALS['test_now'] = 1800000000;

function test_path_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn($p) => $p !== ''));
}

function test_store_get(string $path)
{
    $node = $GLOBALS['fb_store'];
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
    $parts = test_path_parts($path);
    $node =& $GLOBALS['fb_store'];
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $GLOBALS['fb_etags'][$path] = (int)($GLOBALS['fb_etags'][$path] ?? 0) + 1;
}

function test_store_patch(string $path, array $patch): void
{
    $current = test_store_get($path);
    if (!is_array($current)) {
        $current = [];
    }
    test_store_set($path, array_replace_recursive($current, $patch));
}

function test_store_delete(string $path): void
{
    $parts = test_path_parts($path);
    $last = array_pop($parts);
    $node =& $GLOBALS['fb_store'];
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            return;
        }
        $node =& $node[$part];
    }
    if ($last !== null) {
        unset($node[$last]);
    }
    $GLOBALS['fb_etags'][$path] = (int)($GLOBALS['fb_etags'][$path] ?? 0) + 1;
}

function fb_get(string $path) { return test_store_get($path); }
function fb_put(string $path, $data): bool
{
    if (!empty($GLOBALS['fail_put_once'][$path])) {
        unset($GLOBALS['fail_put_once'][$path]);
        return false;
    }
    test_store_set($path, $data);
    return true;
}
function fb_patch(string $path, array $patch): bool { test_store_patch($path, $patch); return true; }
function fb_delete(string $path): bool { test_store_delete($path); return true; }
function fb_get_with_etag(string $path): array
{
    return ['ok' => true, 'value' => test_store_get($path), 'etag' => 'E' . (string)($GLOBALS['fb_etags'][$path] ?? 0)];
}
function fb_put_if_match(string $path, $data, string $etag): array
{
    if ('E' . (string)($GLOBALS['fb_etags'][$path] ?? 0) !== $etag) {
        return ['ok' => false, 'status' => 412];
    }
    if (!empty($GLOBALS['fail_put_once'][$path])) {
        unset($GLOBALS['fail_put_once'][$path]);
        return ['ok' => false, 'status' => 500];
    }
    if (str_starts_with($path, 'USER_WALLETS/')) {
        $uid = explode('/', $path)[1] ?? '';
        $GLOBALS['wallet_writes'][$uid] = (int)($GLOBALS['wallet_writes'][$uid] ?? 0) + 1;
    }
    test_store_set($path, $data);
    return ['ok' => true, 'status' => 200, 'etag' => 'E' . (string)($GLOBALS['fb_etags'][$path] ?? 0)];
}

function now_ts(): int { return (int)$GLOBALS['test_now']; }
function month_key(?int $ts = null): string { return date('Y-m', $ts ?? now_ts()); }
function zpay_dash_clean_string($value, int $max = 120): string { return substr(trim((string)$value), 0, $max); }
function zpay_dash_mask_phone(string $phone): string { return $phone; }
function zpay_dash_mask_name(string $name): string { return $name; }
function zpay_dash_allowed_mobile_role(string $role): bool { return true; }
function auth_status_value($value): string { return strtoupper(trim((string)$value)); }
function system_log(string $type, string $ref, string $message, array $context = []): void {}
function fcm_send_to_user(...$args): array { return ['ok' => true]; }
function api_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    throw new RuntimeException($code . ':' . $message);
}

require_once __DIR__ . '/../api/lib/wallet.php';
require_once __DIR__ . '/../api/lib/mobile_transfer.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function put_wallet(string $uid, float $available, string $currency): void
{
    test_store_set('USER_WALLETS/' . $uid, [
        'uid' => $uid,
        'available_balance' => $available,
        'hold_balance' => 0.0,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'updated_at' => now_ts(),
    ]);
}

function wallet_writes(string $uid): int
{
    return (int)($GLOBALS['wallet_writes'][$uid] ?? 0);
}

function ledger_count(string $uid): int
{
    $rows = test_store_get('WALLET_LEDGER/' . $uid);
    if (!is_array($rows)) {
        return 0;
    }
    $count = 0;
    foreach ($rows as $monthRows) {
        $count += is_array($monthRows) ? count($monthRows) : 0;
    }
    return $count;
}

function notification_count(string $uid): int
{
    $rows = test_store_get('USER_NOTIFICATIONS/' . $uid);
    return is_array($rows) ? count($rows) : 0;
}

function execute_transfer(string $transferId, string $sender, string $receiver, float $amount, string $currency): array
{
    return zpay_transfer_execute_financial([
        'transfer_id' => $transferId,
        'sender_uid' => $sender,
        'receiver_uid' => $receiver,
        'sender_phone' => '60111111111',
        'receiver_phone' => '60122222222',
        'sender_name' => 'Sender',
        'receiver_name' => 'Receiver',
        'sender_role' => 'USER',
        'receiver_role' => 'USER',
        'amount' => $amount,
        'transfer_amount' => $amount,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'reference' => 'test transfer',
        'created_at' => now_ts(),
    ]);
}

put_wallet('S1', 100.00, 'MYR');
put_wallet('R1', 10.00, 'MYR');
$normal = execute_transfer('TR_NORMAL', 'S1', 'R1', 25.00, 'MYR');
assert_true(!empty($normal['ok']), 'normal transfer should succeed');
assert_true((float)fb_get('USER_WALLETS/S1')['available_balance'] === 75.00, 'sender debited exact MYR amount');
assert_true((float)fb_get('USER_WALLETS/R1')['available_balance'] === 35.00, 'receiver credited exact MYR amount');
assert_true(ledger_count('S1') === 1 && ledger_count('R1') === 1, 'normal transfer writes one ledger per side');
assert_true(notification_count('S1') === 1 && notification_count('R1') === 1, 'normal transfer writes one notification per side');

$writesS1 = wallet_writes('S1');
$writesR1 = wallet_writes('R1');
$dupe = execute_transfer('TR_NORMAL', 'S1', 'R1', 25.00, 'MYR');
assert_true(!empty($dupe['ok']), 'duplicate completed transfer should replay success');
assert_true(wallet_writes('S1') === $writesS1 && wallet_writes('R1') === $writesR1, 'duplicate completed transfer must not mutate wallets');
assert_true(notification_count('S1') === 1 && notification_count('R1') === 1, 'duplicate completed transfer must not duplicate notifications');

put_wallet('S2', 100.00, 'BDT');
put_wallet('R2', 5.00, 'BDT');
$GLOBALS['fail_put_once']['USER_WALLETS/R2'] = true;
$partial = execute_transfer('TR_PARTIAL', 'S2', 'R2', 40.00, 'BDT');
assert_true(empty($partial['ok']) && ($partial['code'] ?? '') === 'TRANSFER_RETRYABLE', 'receiver write failure should be retryable');
assert_true((float)fb_get('USER_WALLETS/S2')['available_balance'] === 60.00, 'sender debit applies once before retry');
$writesS2 = wallet_writes('S2');
$retry = execute_transfer('TR_PARTIAL', 'S2', 'R2', 40.00, 'BDT');
assert_true(!empty($retry['ok']), 'retry should complete missing receiver credit');
assert_true(wallet_writes('S2') === $writesS2, 'retry must not repeat sender debit');
assert_true((float)fb_get('USER_WALLETS/R2')['available_balance'] === 45.00, 'retry credits receiver exact BDT amount');
assert_true(ledger_count('S2') === 1 && ledger_count('R2') === 1, 'retry keeps deterministic ledgers one per side');

put_wallet('S3', 90.00, 'MYR');
put_wallet('R3', 10.00, 'MYR');
$GLOBALS['fail_put_once']['TRANSFERS/TR_FINAL'] = true;
$finalFail = execute_transfer('TR_FINAL', 'S3', 'R3', 30.00, 'MYR');
assert_true(empty($finalFail['ok']) && ($finalFail['code'] ?? '') === 'TRANSFER_INDEX_FAILED', 'finalization failure should be reported');
$writesS3 = wallet_writes('S3');
$writesR3 = wallet_writes('R3');
$finalRetry = execute_transfer('TR_FINAL', 'S3', 'R3', 30.00, 'MYR');
assert_true(!empty($finalRetry['ok']), 'finalization retry should succeed');
assert_true(wallet_writes('S3') === $writesS3 && wallet_writes('R3') === $writesR3, 'finalization retry must not repeat wallet mutations');
assert_true(is_array(fb_get('TRANSFERS/TR_FINAL')), 'finalization retry stores transfer row');

put_wallet('S4', 50.00, 'BDT');
put_wallet('R4', 0.00, 'BDT');
$active = wallet_financial_operation_begin('TR_LEASE', 'ZPAY_TRANSFER', 'REQUEST_FINAL', 'S4', 10.00, 'BDT');
assert_true(!empty($active['ok']), 'active transfer claim should start');
$blocked = execute_transfer('TR_LEASE', 'S4', 'R4', 10.00, 'BDT');
assert_true(empty($blocked['ok']) && ($blocked['code'] ?? '') === 'FINANCIAL_OPERATION_IN_PROGRESS', 'active lease cannot be stolen');
$GLOBALS['test_now'] += wallet_financial_operation_lease_seconds() + 1;
$leaseRetry = execute_transfer('TR_LEASE', 'S4', 'R4', 10.00, 'BDT');
assert_true(!empty($leaseRetry['ok']), 'expired transfer lease should be recoverable');
assert_true(!wallet_financial_operation_mark_applied($active['claim'], ['old_owner_test' => true]), 'old owner cannot update after takeover');

echo "transfer wallet recovery tests passed\n";
