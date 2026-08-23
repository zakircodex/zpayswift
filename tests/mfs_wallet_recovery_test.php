<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

if (!defined('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS')) {
    define('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS', 60);
}
if (!defined('MFS_MAX_AMOUNT_BDT')) {
    define('MFS_MAX_AMOUNT_BDT', 50000.00);
}

$store = [];
$versions = [];
$testNow = 1700000000;
$walletWriteCount = [];
$failNextLedgerPut = false;
$failNextDonePut = false;
$failNextPendingPut = false;

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
    global $failNextLedgerPut, $failNextDonePut, $failNextPendingPut;
    if ($failNextLedgerPut && str_starts_with($path, 'WALLET_LEDGER/')) {
        $failNextLedgerPut = false;
        return false;
    }
    if ($failNextDonePut && str_starts_with($path, 'MFS_REQUESTS/DONE/')) {
        $failNextDonePut = false;
        return false;
    }
    if ($failNextPendingPut && str_starts_with($path, 'MFS_REQUESTS/PENDING/')) {
        $failNextPendingPut = false;
        return false;
    }
    test_store_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    global $failNextDonePut, $failNextPendingPut;
    if ($path === '') {
        foreach (array_keys($data) as $updatePath) {
            if ($failNextDonePut && str_starts_with((string)$updatePath, 'MFS_REQUESTS/DONE/')) {
                $failNextDonePut = false;
                return false;
            }
            if ($failNextPendingPut && str_starts_with((string)$updatePath, 'MFS_REQUESTS/PENDING/')) {
                $failNextPendingPut = false;
                return false;
            }
        }
        foreach ($data as $updatePath => $value) {
            if ($value === null) {
                test_store_delete((string)$updatePath);
            } else {
                test_store_set((string)$updatePath, $value);
            }
        }
        return true;
    }
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

function put_mfs_create_user(
    string $uid,
    string $country,
    string $currency,
    string $role = 'USER',
    string $phoneCountry = ''
): void
{
    fb_put('USERS/' . $uid, [
        'uid' => $uid,
        'full_name' => 'MFS Create Test',
        'name' => 'MFS Create Test',
        'phone' => $country === 'MY' ? '60123456789' : '01700000001',
        'role' => $role,
        'status' => 'ACTIVE',
        'pricing_country' => $country,
        'phone_country' => $phoneCountry !== '' ? $phoneCountry : $country,
        'wallet_currency' => $currency,
        'pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
    ]);
    put_wallet($uid, 10000.00, 0.00, $currency);
}

function mfs_create_body(string $provider, string $idempotencyKey, float $amountBdt = 620.00): array
{
    return [
        'provider' => $provider,
        'service_type' => 'SEND_MONEY',
        'account_type' => 'PERSONAL',
        'receiver_number' => '01700000000',
        'amount_bdt' => $amountBdt,
        'currency' => 'BDT',
        'pin' => '1234',
        'idempotency_key' => $idempotencyKey,
    ];
}

fb_put('MFS_SETTINGS', [
    'rate_myr_bdt' => 31.00,
    'fees' => [
        'MY' => [
            'TIERS' => mfs_my_fee_tier_storage(),
        ],
    ],
]);
mfs_config(true);
$publicFeeSettings = mfs_public_settings();
assert_true(!isset($publicFeeSettings['fees']['MY']['TIERS']), 'Public MFS settings must not expose the Admin tier matrix');

$roleCases = [
    ['MFS_TIER_USER', 'USER', 50000.01, 7.00],
    ['MFS_TIER_RETAILER', 'RETAILER', 70000.01, 4.00],
    ['MFS_TIER_SUBADMIN', 'SUBADMIN', 50001.00, 3.00],
    ['MFS_TIER_ADMIN', 'ADMIN', 100000.00, 0.00],
];
foreach ($roleCases as [$tierUid, $role, $amountBdt, $expectedFee]) {
    put_mfs_create_user($tierUid, 'MY', 'MYR', $role, 'BD');
    $tierPreview = mfs_preview_payload($tierUid, array_merge(
        mfs_create_body('BKASH', 'PREVIEW_' . $tierUid, $amountBdt),
        [
            'role' => 'USER',
            'fee' => 0,
            'tier' => 'TIER1',
            'pricing_country' => 'BD',
            'wallet_currency' => 'BDT',
            'rate' => 1,
        ]
    ));
    assert_true(!empty($tierPreview['ok']), "{$role} tier preview should succeed");
    assert_true((float)($tierPreview['data']['fee_rm'] ?? -1) === $expectedFee, "{$role} fee must come from canonical account role and BDT tier");
    assert_true((string)($tierPreview['data']['country_code'] ?? '') === 'MY', "{$role} phone_country/client input must not override pricing_country");
}

$untrustedAmountsPreview = mfs_preview_payload('MFS_TIER_USER', array_merge(
    mfs_create_body('BKASH', 'PREVIEW_UNTRUSTED_AMOUNTS', 50000.01),
    ['amount_rm' => 1.00, 'amount_myr' => 1.00, 'currency' => 'BDT', 'rate' => 1]
));
assert_true(!empty($untrustedAmountsPreview['ok']), 'Canonical-rate preview should accept a valid BDT service amount');
assert_true((float)$untrustedAmountsPreview['data']['amount_rm'] === round(50000.01 / 31.00, 2), 'Client MYR amount/rate must not override the canonical rate conversion');
assert_true((float)$untrustedAmountsPreview['data']['fee_rm'] === 7.00, 'Tier fee must use the canonical BDT service amount');

put_mfs_create_user('MFS_TIER_BD_MARKET', 'BD', 'BDT', 'USER', 'MY');
$bdMarketPreview = mfs_preview_payload('MFS_TIER_BD_MARKET', mfs_create_body('BKASH', 'PREVIEW_BD_MARKET', 70000.01));
assert_true(!empty($bdMarketPreview['ok']), 'BD market preview should remain available at the new maximum');
assert_true((string)($bdMarketPreview['data']['country_code'] ?? '') === 'BD', 'phone_country must not switch a BD pricing account into MY fees');
assert_true((float)($bdMarketPreview['data']['fee_rm'] ?? -1) === 0.00, 'BD local fee rules must not receive an MY fee');

$maximumPreview = mfs_preview_payload('MFS_TIER_USER', mfs_create_body('BKASH', 'PREVIEW_MAX', 100000.00));
assert_true(!empty($maximumPreview['ok']), 'BDT 100,000 must be accepted');
$overMaximumPreview = mfs_preview_payload('MFS_TIER_USER', mfs_create_body('BKASH', 'PREVIEW_OVER_MAX', 100000.01));
assert_true(empty($overMaximumPreview['ok']) && (float)($overMaximumPreview['data']['maximum_amount_bdt'] ?? 0) === 100000.00, 'Amount above BDT 100,000 must be rejected');

put_mfs_create_user('MFS_TIER_PREVIEW_SUBMIT', 'MY', 'MYR', 'USER');
$tierSubmitBody = mfs_create_body('BKASH', 'MFS_TIER_PREVIEW_SUBMIT_ONCE', 50000.01);
$tierSubmitPreview = mfs_preview_payload('MFS_TIER_PREVIEW_SUBMIT', $tierSubmitBody);
$tierSubmit = mfs_create_request('MFS_TIER_PREVIEW_SUBMIT', $tierSubmitBody, 'ADMIN_PANEL', 'PANEL', [
    'uid' => 'ADMIN_TEST',
    'role' => 'ADMIN',
    'skip_pin_validation' => true,
]);
assert_true(!empty($tierSubmitPreview['ok']) && !empty($tierSubmit['ok']), 'Tier 2 preview and submit must both succeed');
assert_true((float)$tierSubmitPreview['data']['fee_rm'] === (float)$tierSubmit['data']['fee_rm'], 'Preview and submit must calculate the same tier fee');
assert_true((float)$tierSubmit['data']['fee_rm'] === 7.00, 'Admin-created request must use the target USER role, not the Admin actor role');

$retailerSubmit = mfs_create_request(
    'MFS_TIER_RETAILER',
    mfs_create_body('NAGAD', 'MFS_TIER_RETAILER_SUBMIT', 50000.01),
    'USER_PANEL',
    'PANEL',
    ['uid' => 'MFS_TIER_RETAILER', 'role' => 'RETAILER']
);
assert_true(!empty($retailerSubmit['ok']) && (float)$retailerSubmit['data']['fee_rm'] === 3.00, 'Retailer submit must use the canonical Tier 2 RETAILER fee');

$subadminSubmit = mfs_create_request(
    'MFS_TIER_SUBADMIN',
    mfs_create_body('BKASH', 'MFS_TIER_SUBADMIN_SUBMIT', 70000.01),
    'SUBADMIN_PANEL',
    'PANEL',
    ['uid' => 'MFS_TIER_SUBADMIN', 'role' => 'SUBADMIN']
);
assert_true(!empty($subadminSubmit['ok']) && (float)$subadminSubmit['data']['fee_rm'] === 4.00, 'Subadmin submit must use the canonical Tier 3 SUBADMIN fee');

put_mfs_create_user('MFS_TIER_SNAPSHOT', 'MY', 'MYR', 'USER');
$snapshotBody = mfs_create_body('BKASH', 'MFS_TIER_SNAPSHOT_ONCE', 70000.01);
$snapshotPreview = mfs_preview_payload('MFS_TIER_SNAPSHOT', $snapshotBody);
assert_true(!empty($snapshotPreview['ok']) && (float)$snapshotPreview['data']['fee_rm'] === 10.00, 'Snapshot setup must use the original Tier 3 fee');
$snapshotStartingBalance = (float)fb_get('USER_WALLETS/MFS_TIER_SNAPSHOT')['available_balance'];
$changedTiers = mfs_my_fee_tier_storage();
$changedTiers['TIER3']['USER'] = 25.00;
fb_put('MFS_SETTINGS/fees/MY/TIERS', $changedTiers);
mfs_config(true);
$snapshotCreate = mfs_create_request('MFS_TIER_SNAPSHOT', $snapshotBody, 'USER_API', 'PANEL', [
    'uid' => 'MFS_TIER_SNAPSHOT',
    'role' => 'USER',
    'preview_data' => (array)$snapshotPreview['data'],
]);
assert_true(!empty($snapshotCreate['ok']), 'Request creation from the original preview snapshot must succeed after a fee update');
assert_true((float)$snapshotCreate['data']['fee_rm'] === 10.00, 'Existing preview/request snapshot must not be repriced after an Admin fee change');
$snapshotRequestId = (string)$snapshotCreate['data']['request_id'];
$snapshotHeld = (float)$snapshotCreate['data']['total_debit'];
$snapshotFailure = mfs_mark_failed($snapshotRequestId, 'test refund');
assert_true(!empty($snapshotFailure['ok']), 'Snapshot request failure must finalize');
$snapshotWallet = (array)fb_get('USER_WALLETS/MFS_TIER_SNAPSHOT');
assert_true((float)$snapshotWallet['available_balance'] === $snapshotStartingBalance, 'Failed request must refund the exact original tiered MYR debit');
assert_true((float)$snapshotWallet['hold_balance'] === 0.00 && $snapshotHeld > 0, 'Failed request must release the exact original hold');

fb_put('MFS_SETTINGS/fees/MY/TIERS', mfs_my_fee_tier_storage());
mfs_config(true);

function mfs_confirm_from_preview(string $uid, string $provider, string $idempotencyKey): array
{
    $body = mfs_create_body($provider, $idempotencyKey);
    $preview = mfs_preview_payload($uid, $body);
    if (empty($preview['ok'])) {
        return ['preview' => $preview, 'result' => $preview, 'preview_hash' => ''];
    }

    $previewData = (array)$preview['data'];
    $previewToken = mfs_create_preview_token(array_merge($previewData, [
        'expires_at' => now_ts() + 300,
        'status' => 'READY',
    ]));
    $previewHash = mfs_preview_token_hash($previewToken);
    $claim = mfs_claim_preview_token($previewHash, $uid);
    if (empty($claim['ok'])) {
        return ['preview' => $preview, 'result' => $claim, 'preview_hash' => $previewHash];
    }

    $result = mfs_create_request(
        $uid,
        $body,
        'USER_API',
        'PANEL',
        [
            'uid' => $uid,
            'role' => 'USER',
            'preview_token_hash' => $previewHash,
            'preview_data' => (array)($claim['preview'] ?? []),
        ]
    );

    return ['preview' => $preview, 'result' => $result, 'preview_hash' => $previewHash];
}

put_mfs_create_user('MFS_CREATE_BKASH_MY', 'MY', 'MYR');
$bkashConfirm = mfs_confirm_from_preview('MFS_CREATE_BKASH_MY', 'BKASH', 'MFS_CREATE_BKASH_MY_ONCE');
$bkashCreate = (array)$bkashConfirm['result'];
assert_true(!empty($bkashConfirm['preview']['ok']), 'bKash MY preview must succeed with default provider config');
assert_true(!empty($bkashCreate['ok']), 'bKash MY confirm must create a request with default provider config');
assert_true(array_keys($bkashCreate) === ['ok', 'code', 'message', 'data'], 'MFS create result envelope must remain unchanged');
$bkashData = (array)$bkashCreate['data'];
assert_true((string)($bkashData['provider'] ?? '') === 'BKASH', 'bKash provider must remain canonical');
assert_true((string)($bkashData['wallet_currency'] ?? '') === 'MYR', 'bKash MY wallet currency must remain MYR');
assert_true((float)($bkashData['total_debit'] ?? 0) === (float)($bkashData['amount_rm'] ?? 0) + (float)($bkashData['fee_rm'] ?? 0), 'bKash MY fee and total debit must remain consistent');
$bkashWrites = wallet_write_count('MFS_CREATE_BKASH_MY');
$bkashDuplicate = mfs_create_request(
    'MFS_CREATE_BKASH_MY',
    mfs_create_body('BKASH', 'MFS_CREATE_BKASH_MY_ONCE'),
    'USER_API',
    'PANEL',
    ['uid' => 'MFS_CREATE_BKASH_MY', 'role' => 'USER']
);
assert_true(!empty($bkashDuplicate['ok']), 'duplicate bKash confirm must replay canonical success: ' . json_encode($bkashDuplicate));
assert_true(wallet_write_count('MFS_CREATE_BKASH_MY') === $bkashWrites, 'duplicate bKash confirm must not hold wallet twice');

put_mfs_create_user('MFS_CREATE_NAGAD_MY', 'MY', 'MYR');
$nagadConfirm = mfs_confirm_from_preview('MFS_CREATE_NAGAD_MY', 'NAGAD', 'MFS_CREATE_NAGAD_MY_ONCE');
$nagadCreate = (array)$nagadConfirm['result'];
assert_true(!empty($nagadConfirm['preview']['ok']), 'Nagad MY preview must succeed with default provider config');
assert_true(!empty($nagadCreate['ok']), 'Nagad MY confirm must create a request with default provider config');
assert_true((string)($nagadCreate['data']['provider'] ?? '') === 'NAGAD', 'Nagad provider must remain canonical');

put_mfs_create_user('MFS_CREATE_BD', 'BD', 'BDT');
$bdConfirm = mfs_confirm_from_preview('MFS_CREATE_BD', 'BKASH', 'MFS_CREATE_BD_ONCE');
$bdCreate = (array)$bdConfirm['result'];
assert_true(!empty($bdConfirm['preview']['ok']), 'BD MFS preview must succeed');
assert_true(!empty($bdCreate['ok']), 'BD MFS confirm must create a request');
assert_true((float)($bdCreate['data']['total_debit'] ?? 0) === (float)($bdCreate['data']['amount_bdt'] ?? 0) + (float)($bdCreate['data']['fee_bdt'] ?? 0), 'BD fee and total debit must remain consistent');
assert_true((string)($bdCreate['data']['wallet_currency'] ?? '') === 'BDT', 'BD wallet currency must remain BDT');

put_mfs_create_user('MFS_CREATE_RETRY', 'MY', 'MYR');
$failNextPendingPut = true;
$createSaveFailure = mfs_create_request(
    'MFS_CREATE_RETRY',
    mfs_create_body('BKASH', 'MFS_CREATE_REQUEST_SAVE_RETRY'),
    'USER_API',
    'PANEL',
    ['uid' => 'MFS_CREATE_RETRY', 'role' => 'USER']
);
assert_true(empty($createSaveFailure['ok']) && ($createSaveFailure['code'] ?? '') === 'SERVER_ERROR', 'request-save failure must be reported after one hold');
$retryWrites = wallet_write_count('MFS_CREATE_RETRY');
$createSaveRetry = mfs_create_request(
    'MFS_CREATE_RETRY',
    mfs_create_body('BKASH', 'MFS_CREATE_REQUEST_SAVE_RETRY'),
    'USER_API',
    'PANEL',
    ['uid' => 'MFS_CREATE_RETRY', 'role' => 'USER']
);
assert_true(!empty($createSaveRetry['ok']), 'request-save retry must repair the request');
assert_true(wallet_write_count('MFS_CREATE_RETRY') === $retryWrites, 'request-save retry must not repeat MFS hold');

$invalidProvider = mfs_create_request(
    'MFS_CREATE_BD',
    mfs_create_body('INVALID', 'MFS_CREATE_INVALID_PROVIDER'),
    'USER_API',
    'PANEL',
    ['uid' => 'MFS_CREATE_BD', 'role' => 'USER']
);
assert_true(empty($invalidProvider['ok']) && ($invalidProvider['code'] ?? '') === 'VALIDATION_ERROR', 'invalid MFS provider must be rejected');

if (!defined('MFS_PROVIDER_NAGAD_ENABLED')) {
    define('MFS_PROVIDER_NAGAD_ENABLED', false);
}
$disabledProvider = mfs_create_request(
    'MFS_CREATE_BD',
    mfs_create_body('NAGAD', 'MFS_CREATE_DISABLED_PROVIDER'),
    'USER_API',
    'PANEL',
    ['uid' => 'MFS_CREATE_BD', 'role' => 'USER']
);
assert_true(empty($disabledProvider['ok']) && ($disabledProvider['code'] ?? '') === 'PROVIDER_DISABLED', 'disabled provider must return the canonical unavailable code');

echo "mfs wallet recovery tests passed\n";
