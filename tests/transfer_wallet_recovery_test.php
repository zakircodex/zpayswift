<?php
declare(strict_types=1);

$GLOBALS['fb_store'] = [];
$GLOBALS['fb_etags'] = [];
$GLOBALS['wallet_writes'] = [];
$GLOBALS['fail_put_once'] = [];
$GLOBALS['fail_put_if_match_status_once'] = [];
$GLOBALS['fcm_fail_once'] = false;
$GLOBALS['fcm_calls'] = 0;
$GLOBALS['fcm_sleep_microseconds'] = 0;
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
    $nextStatus = is_array($data) ? strtoupper(trim((string)($data['status'] ?? ''))) : '';
    if ($nextStatus !== '' && !empty($GLOBALS['fail_put_if_match_status_once'][$path][$nextStatus])) {
        unset($GLOBALS['fail_put_if_match_status_once'][$path][$nextStatus]);
        return ['ok' => false, 'status' => 500];
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
function app_api_url(string $path = ''): string
{
    return 'https://zpayswift.com/api' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
function fcm_send_to_user(...$args): array
{
    $GLOBALS['fcm_calls'] = (int)($GLOBALS['fcm_calls'] ?? 0) + 1;
    if ((int)($GLOBALS['fcm_sleep_microseconds'] ?? 0) > 0) {
        usleep((int)$GLOBALS['fcm_sleep_microseconds']);
    }
    if (!empty($GLOBALS['fcm_fail_once'])) {
        $GLOBALS['fcm_fail_once'] = false;
        return ['ok' => false, 'code' => 'FCM_TIMEOUT', 'sent' => 0, 'failed' => 1];
    }
    return ['ok' => true, 'sent' => 1];
}
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

function execute_transfer_financial(string $transferId, string $sender, string $receiver, float $amount, string $currency): array
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

function execute_transfer(string $transferId, string $sender, string $receiver, float $amount, string $currency): array
{
    $result = execute_transfer_financial($transferId, $sender, $receiver, $amount, $currency);
    if (!empty($result['ok']) && is_array($result['transfer'] ?? null)) {
        zpay_transfer_run_post_response_tasks((array)$result['transfer']);
    }
    return $result;
}

$trackingUrl = zpay_transfer_receipt_url('PUBLIC_TRACKING_TOKEN');
assert_true($trackingUrl === 'https://zpayswift.com/api/transfer/receipt.php?t=PUBLIC_TRACKING_TOKEN', 'tracking URL must use the canonical public API origin');
$trackingParts = parse_url($trackingUrl);
parse_str((string)($trackingParts['query'] ?? ''), $trackingQuery);
assert_true(($trackingParts['host'] ?? '') === 'zpayswift.com' && array_keys($trackingQuery) === ['t'], 'tracking URL must contain only the public receipt token');

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
assert_true(!empty($finalFail['ok']) && ($finalFail['code'] ?? '') === 'TRANSFER_SUCCESS', 'post-commit finalization failure should return safe success');
assert_true(!empty($finalFail['finalization_pending']) || !empty($finalFail['transfer']['finalization_pending']), 'post-commit finalization failure should retain repair evidence');
assert_true((float)fb_get('USER_WALLETS/S3')['available_balance'] === 60.00, 'post-commit failure debits sender once');
assert_true((float)fb_get('USER_WALLETS/R3')['available_balance'] === 40.00, 'post-commit failure credits receiver once');
$pendingReplay = zpay_transfer_replay_preview_result('TR_FINAL', [
    'sender_uid' => 'S3',
    'receiver_uid' => 'R3',
    'sender_phone' => '60111111111',
    'receiver_phone' => '60122222222',
    'sender_name' => 'Sender',
    'receiver_name' => 'Receiver',
    'amount' => 30.00,
    'currency' => 'MYR',
]);
assert_true(!empty($pendingReplay['ok']) && ($pendingReplay['code'] ?? '') === 'TRANSFER_SUCCESS', 'committed preview replay should return safe success');

test_store_set(wallet_financial_operation_scope_path('TR_PRECOMMIT', 'REQUEST_FINAL'), [
    'request_id' => 'TR_PRECOMMIT',
    'status' => 'CLAIMED',
    'sender_debited' => false,
    'receiver_credited' => false,
]);
$preCommitReplay = zpay_transfer_replay_preview_result('TR_PRECOMMIT', [
    'sender_uid' => 'S3',
    'receiver_uid' => 'R3',
    'amount' => 30.00,
    'currency' => 'MYR',
]);
assert_true(empty($preCommitReplay['ok']) && ($preCommitReplay['code'] ?? '') === 'TRANSFER_PROCESSING', 'pre-commit processing state must not be replayed as success');

test_store_set(wallet_financial_operation_scope_path('TR_WALLETS_COMMITTED', 'REQUEST_FINAL'), [
    'request_id' => 'TR_WALLETS_COMMITTED',
    'status' => 'FAILED_RETRYABLE',
    'sender_debited' => true,
    'receiver_credited' => true,
    'sender_ledger_written' => false,
    'receiver_ledger_written' => false,
]);
$walletCommittedReplay = zpay_transfer_replay_preview_result('TR_WALLETS_COMMITTED', [
    'sender_uid' => 'S3',
    'receiver_uid' => 'R3',
    'amount' => 30.00,
    'currency' => 'MYR',
]);
assert_true(!empty($walletCommittedReplay['ok']) && !empty($walletCommittedReplay['finalization_pending']), 'both wallet mutations must replay as safe success while ledger/finalization repair remains pending');
$writesS3 = wallet_writes('S3');
$writesR3 = wallet_writes('R3');
$finalRetry = execute_transfer('TR_FINAL', 'S3', 'R3', 30.00, 'MYR');
assert_true(!empty($finalRetry['ok']), 'finalization retry should succeed');
assert_true(wallet_writes('S3') === $writesS3 && wallet_writes('R3') === $writesR3, 'finalization retry must not repeat wallet mutations');
assert_true(is_array(fb_get('TRANSFERS/TR_FINAL')), 'finalization retry stores transfer row');

$previewReplayRow = [
    'sender_uid' => 'S3',
    'receiver_uid' => 'R3',
    'transfer_id' => 'TR_FINAL',
    'amount' => 30.00,
    'currency' => 'MYR',
    'expires_at' => now_ts() + 300,
];
test_store_set('TRANSFER_PREVIEWS/USED_PREVIEW', array_merge($previewReplayRow, ['status' => 'USED', 'used' => true]));
$usedClaim = zpay_transfer_claim_preview_token('USED_PREVIEW', 'S3');
assert_true(!empty($usedClaim['ok']) && !empty($usedClaim['duplicate']) && ($usedClaim['transfer_id'] ?? '') === 'TR_FINAL', 'USED preview must bind replay to the original transfer ID');
$usedReplay = zpay_transfer_replay_preview_result((string)$usedClaim['transfer_id'], (array)$usedClaim['preview']);
assert_true(!empty($usedReplay['ok']) && ($usedReplay['transfer']['transfer_id'] ?? '') === 'TR_FINAL', 'USED preview must replay the original successful transfer');

test_store_set('TRANSFER_PREVIEWS/PROCESSING_PREVIEW', array_merge($previewReplayRow, ['status' => 'PROCESSING', 'used' => false]));
$processingClaim = zpay_transfer_claim_preview_token('PROCESSING_PREVIEW', 'S3');
assert_true(!empty($processingClaim['ok']) && !empty($processingClaim['resume']) && ($processingClaim['transfer_id'] ?? '') === 'TR_FINAL', 'PROCESSING preview must resume the original transfer ID');
$processingReplay = zpay_transfer_replay_preview_result((string)$processingClaim['transfer_id'], (array)$processingClaim['preview']);
assert_true(!empty($processingReplay['ok']) && ($processingReplay['transfer']['transfer_id'] ?? '') === 'TR_FINAL', 'PROCESSING preview with committed evidence must replay success');

put_wallet('S5', 80.00, 'MYR');
put_wallet('R5', 20.00, 'MYR');
$GLOBALS['fail_put_if_match_status_once'][wallet_financial_operation_scope_path('TR_MARK', 'REQUEST_FINAL')]['COMPLETED'] = true;
$markFail = execute_transfer('TR_MARK', 'S5', 'R5', 15.00, 'MYR');
assert_true(!empty($markFail['ok']) && ($markFail['code'] ?? '') === 'TRANSFER_SUCCESS', 'operation completion mark failure should still return safe success');
assert_true(!empty($markFail['finalization_pending']) || !empty($markFail['transfer']['finalization_pending']), 'operation completion mark failure should be marked pending');
assert_true((float)fb_get('USER_WALLETS/S5')['available_balance'] === 65.00, 'mark failure debits sender once');
assert_true((float)fb_get('USER_WALLETS/R5')['available_balance'] === 35.00, 'mark failure credits receiver once');

put_wallet('S6', 70.00, 'BDT');
put_wallet('R6', 3.00, 'BDT');
$GLOBALS['fcm_fail_once'] = true;
$fcmFail = execute_transfer('TR_FCM', 'S6', 'R6', 12.00, 'BDT');
assert_true(!empty($fcmFail['ok']) && ($fcmFail['code'] ?? '') === 'TRANSFER_SUCCESS', 'FCM failure must not fail a committed transfer');
assert_true((float)fb_get('USER_WALLETS/S6')['available_balance'] === 58.00, 'FCM failure debits sender once');
assert_true((float)fb_get('USER_WALLETS/R6')['available_balance'] === 15.00, 'FCM failure credits receiver once');
assert_true(notification_count('S6') === 1 && notification_count('R6') === 1, 'FCM failure still records in-app notifications');

put_wallet('S7', 75.00, 'MYR');
put_wallet('R7', 5.00, 'MYR');
$fcmCallsBeforeDeferredTransfer = (int)$GLOBALS['fcm_calls'];
$GLOBALS['fcm_sleep_microseconds'] = 250000;
$deferredStartedAt = microtime(true);
$deferredResult = execute_transfer_financial('TR_DEFERRED_NOTIFY', 'S7', 'R7', 10.00, 'MYR');
$deferredElapsed = microtime(true) - $deferredStartedAt;
assert_true(!empty($deferredResult['ok']), 'financial result must succeed before deferred notification work');
assert_true($deferredElapsed < 0.20, 'financial result must not wait for slow FCM delivery');
assert_true((int)$GLOBALS['fcm_calls'] === $fcmCallsBeforeDeferredTransfer, 'FCM must not run inside the financial response path');
zpay_transfer_run_post_response_tasks((array)$deferredResult['transfer']);
$GLOBALS['fcm_sleep_microseconds'] = 0;
assert_true((int)$GLOBALS['fcm_calls'] === $fcmCallsBeforeDeferredTransfer + 1, 'deferred notification phase should perform the receiver push attempt');

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
