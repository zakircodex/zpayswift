<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
define('APP_KEY', 'referral-test-secret');

$referralTestNow = 1789228800;
$referralStore = [];
$referralVersions = [];
$referralAssertions = 0;

function referral_test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function referral_test_get(string $path)
{
    global $referralStore;
    $node = $referralStore;
    foreach (referral_test_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function referral_test_set(string $path, $value): void
{
    global $referralStore, $referralVersions;
    $parts = referral_test_parts($path);
    $node =& $referralStore;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $referralVersions[$path] = ($referralVersions[$path] ?? 0) + 1;
}

function fb_get(string $path, array $query = [])
{
    return referral_test_get($path);
}

function fb_put(string $path, $data): bool
{
    referral_test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    $current = referral_test_get($path);
    $current = is_array($current) ? $current : [];
    referral_test_set($path, array_merge($current, $data));
    return true;
}

function fb_delete(string $path): bool
{
    referral_test_set($path, null);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $referralVersions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => 'v' . (string)($referralVersions[$path] ?? 0),
        'value' => referral_test_get($path),
        'error' => '',
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $referralVersions;
    $expected = 'v' . (string)($referralVersions[$path] ?? 0);
    if (!hash_equals($expected, $etag)) {
        return ['ok' => false, 'status' => 412];
    }
    referral_test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function now_ts(): int
{
    global $referralTestNow;
    return $referralTestNow;
}

function month_key(?int $timestamp = null): string
{
    return gmdate('Y-m', $timestamp ?? now_ts());
}

function referral_expect(bool $condition, string $message): void
{
    global $referralAssertions;
    $referralAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function referral_seed_account(string $uid, string $country, string $role, float $balance): void
{
    $currency = $country === 'BD' ? 'BDT' : 'MYR';
    fb_put('USERS/' . $uid, [
        'uid' => $uid,
        'name' => $uid . ' Person',
        'role' => $role,
        'account_status' => 'ACTIVE',
        'pricing_country' => $country,
        'created_at' => now_ts() - 3600,
    ]);
    fb_put('USER_WALLETS/' . $uid, [
        'available_balance' => $balance,
        'hold_balance' => 0,
        'wallet_currency' => $currency,
        'currency' => $currency,
    ]);
}

require_once dirname(__DIR__) . '/api/lib/referral.php';

$sharedDevice = 'zprd-' . str_repeat('a', 64);
$secondDevice = 'zprd-' . str_repeat('b', 64);

referral_seed_account('BD_REFERRER', 'BD', 'USER', 100.00);
referral_seed_account('BD_NEW_ONE', 'BD', 'USER', 0.00);
referral_seed_account('BD_NEW_TWO', 'BD', 'USER', 0.00);
referral_seed_account('MY_WRONG_MARKET', 'MY', 'USER', 0.00);
referral_seed_account('BD_HAS_REQUEST', 'BD', 'USER', 0.00);

$bdCode = referral_ensure_code('BD_REFERRER');
referral_expect(strlen($bdCode) === 12, 'A deterministic referral code was not created');

$bdClaim = referral_claim('BD_NEW_ONE', $bdCode, 'ANDROID', $sharedDevice);
referral_expect(!empty($bdClaim['ok']) && ($bdClaim['code'] ?? '') === 'REFERRAL_ACTIVE', 'First BD referral was not activated');
referral_expect((float)referral_test_get('USER_WALLETS/BD_REFERRER')['available_balance'] === 110.00, 'BD reward did not credit BDT 10');

$bdRetry = referral_claim('BD_NEW_ONE', $bdCode, 'ANDROID', $sharedDevice);
referral_expect(!empty($bdRetry['ok']), 'Idempotent BD referral retry failed');
referral_expect((float)referral_test_get('USER_WALLETS/BD_REFERRER')['available_balance'] === 110.00, 'BD referral retry credited twice');

$secondClaim = referral_claim('BD_NEW_TWO', $bdCode, 'ANDROID', $sharedDevice);
$blockedRelation = referral_test_get('USER_REFERRALS/BD_NEW_TWO');
referral_expect(empty($secondClaim['ok']) && ($secondClaim['code'] ?? '') === 'DEVICE_ALREADY_USED', 'A second account reused the rewarded phone');
referral_expect(($blockedRelation['status'] ?? '') === 'BLOCKED_DEVICE', 'Second account was not retained as reward-ineligible');
referral_expect((float)referral_test_get('USER_WALLETS/BD_REFERRER')['available_balance'] === 110.00, 'Blocked device created a second reward');

$blockedRetry = referral_activate_relation_device('BD_NEW_TWO', $secondDevice);
referral_expect(empty($blockedRetry['ok']) && ($blockedRetry['code'] ?? '') === 'DEVICE_ALREADY_USED', 'Blocked relation became eligible on another device');
referral_expect(referral_test_get('REFERRAL_DEVICE_LOCKS/' . referral_device_fingerprint($secondDevice)) === null, 'Blocked relation consumed another device lock');

$marketMismatch = referral_claim('MY_WRONG_MARKET', $bdCode, 'ANDROID', $secondDevice);
referral_expect(empty($marketMismatch['ok']) && ($marketMismatch['code'] ?? '') === 'REFERRAL_MARKET_MISMATCH', 'Cross-country referral was accepted');

fb_put('USER_API_REQUESTS/BD_HAS_REQUEST/OLD-MFS', ['status' => 'FAILED']);
$lateClaim = referral_claim('BD_HAS_REQUEST', $bdCode, 'WEB');
referral_expect(empty($lateClaim['ok']) && ($lateClaim['code'] ?? '') === 'FIRST_REQUEST_ALREADY_CREATED', 'An account claimed after its first MFS request');

$publicClaim = referral_public_action_data($bdClaim);
referral_expect(!isset($publicClaim['relation']['referrer_uid']) && !isset($publicClaim['reward']['data']), 'Public claim response exposed internal referral data');

referral_seed_account('MY_REFERRER', 'MY', 'RETAILER', 50.00);
referral_seed_account('MY_NEW', 'MY', 'USER', 0.00);
$myCode = referral_ensure_code('MY_REFERRER');
$myClaim = referral_claim('MY_NEW', $myCode, 'ANDROID', $secondDevice);
referral_expect(!empty($myClaim['ok']), 'Malaysia referral was not activated');
referral_expect((float)referral_test_get('USER_WALLETS/MY_REFERRER')['available_balance'] === 50.00, 'MY relation paid before a successful request');

$snapshot = referral_mfs_snapshot(
    referral_test_get('USERS/MY_NEW'),
    referral_test_get('USER_WALLETS/MY_NEW'),
    ['wallet_currency' => 'MYR', 'fee_tier_id' => 'TIER1', 'fee_rm' => 5.00],
    'BKASH'
);
referral_expect(!empty($snapshot['eligible']) && (float)$snapshot['commission_amount'] === 1.00, 'A retailer referrer changed the referred USER Tier 1 commission');

$myPayout = referral_process_mfs_success('MFS-SUCCESS-1', ['uid' => 'MY_NEW', 'provider' => 'BKASH', 'referral' => $snapshot]);
referral_expect(!empty($myPayout['ok']), 'MY successful request commission failed');
referral_expect((float)referral_test_get('USER_WALLETS/MY_REFERRER')['available_balance'] === 51.00, 'MY successful request did not credit RM 1');

$myPayoutRetry = referral_process_mfs_success('MFS-SUCCESS-1', ['uid' => 'MY_NEW', 'provider' => 'BKASH', 'referral' => $snapshot]);
referral_expect(!empty($myPayoutRetry['ok']), 'MY commission idempotent retry failed');
referral_expect((float)referral_test_get('USER_WALLETS/MY_REFERRER')['available_balance'] === 51.00, 'MY commission retry credited twice');

$retailerUser = referral_test_get('USERS/MY_NEW');
$retailerUser['role'] = 'RETAILER';
$retailerSnapshot = referral_mfs_snapshot(
    $retailerUser,
    referral_test_get('USER_WALLETS/MY_NEW'),
    ['wallet_currency' => 'MYR', 'fee_tier_id' => 'TIER2', 'fee_rm' => 3.00],
    'NAGAD'
);
referral_expect((float)($retailerSnapshot['commission_amount'] ?? 0) === 0.15, 'MY RETAILER Tier 2 commission snapshot is incorrect');

$invalidConfig = referral_validate_admin_config([
    'my_commissions' => [
        'USER' => ['TIER1' => 5.01, 'TIER2' => 1.20, 'TIER3' => 2.00],
        'RETAILER' => ['TIER1' => 0.10, 'TIER2' => 0.15, 'TIER3' => 0.20],
    ],
]);
referral_expect(empty($invalidConfig['ok']) && ($invalidConfig['code'] ?? '') === 'COMMISSION_EXCEEDS_FEE', 'Admin could configure commission above the fee');

for ($index = 0; $index < 12; $index++) {
    $id = 'HISTORY-' . $index;
    fb_put('USER_REFERRAL_PAYOUTS/MY_REFERRER/' . $id, [
        'payout_id' => $id,
        'payout_type' => 'MY_RECURRING',
        'amount' => 1.00,
        'currency' => 'MYR',
        'status' => 'COMPLETED',
        'created_at' => now_ts() - 100 - $index,
    ]);
}
$firstPage = referral_history_for_user('MY_REFERRER', 10, 0);
$secondPage = referral_history_for_user('MY_REFERRER', 10, (int)$firstPage['next_before']);
referral_expect(count($firstPage['items']) === 10 && !empty($firstPage['has_more']), 'Referral history did not return a lazy page of 10');
referral_expect(count($secondPage['items']) >= 2, 'Referral history cursor did not load remaining rows');

$corePayload = referral_status_payload('MY_REFERRER', 10, 0, 'core');
$historyPayload = referral_status_payload('MY_REFERRER', 10, 0, 'history');
referral_expect(!isset($corePayload['history']) && isset($corePayload['referral_code']), 'Referral core payload was not separated from history');
referral_expect(count($historyPayload['history']['items'] ?? []) === 10 && !isset($historyPayload['referral_code']), 'Referral history scope did not return one isolated page');

$webReferralScript = (string)file_get_contents(dirname(__DIR__) . '/api/user/assets/pages/referral-page.js');
referral_expect(str_contains($webReferralScript, "scope: 'core'") && str_contains($webReferralScript, "scope: 'history'"), 'Web referral page is not progressively loaded');
referral_expect(str_contains($webReferralScript, "rowsFor('USER', 'user')") && str_contains($webReferralScript, "rowsFor('RETAILER', 'retailer')"), 'Web reward rules still depend on the referrer account type');

$webReferralMarkup = (string)file_get_contents(dirname(__DIR__) . '/api/user/referral.php');
$webReferralStyles = (string)file_get_contents(dirname(__DIR__) . '/api/user/assets/pages/referral-page.css');
$webDashboardStyles = (string)file_get_contents(dirname(__DIR__) . '/api/user/assets/pages/dashboard-page.css');
referral_expect(!str_contains($webReferralMarkup, 'referralCopyLink') && str_contains($webReferralMarkup, '>Copy code<') && str_contains($webReferralMarkup, '>Share link<'), 'Web referral card does not expose exactly the requested actions');
referral_expect(str_contains($webReferralStyles, '.referral-page-section.active') && str_contains($webReferralStyles, 'touch-action: pan-y'), 'Web referral page scrolling contract is missing');
referral_expect((bool)preg_match('/\.user-dashboard-page\s+\.zpay-service-grid-secondary\s*\{[^}]*grid-template-columns:\s*repeat\(3,/s', $webDashboardStyles), 'Web dashboard utility cards do not match the three-column service grid');

$mfsSource = (string)file_get_contents(dirname(__DIR__) . '/api/lib/mfs.php');
$successStart = strpos($mfsSource, 'function mfs_mark_success');
$successEnd = strpos($mfsSource, 'function mfs_mark_failed', $successStart);
$successSource = substr($mfsSource, $successStart, $successEnd - $successStart);
referral_expect(str_contains($successSource, 'mfs_process_referral_payout'), 'Referral payout is not attached to canonical MFS success');
referral_expect(
    str_contains($successSource, "if (\$currentStatus === 'SUCCESSFUL')")
    && substr_count($successSource, 'mfs_process_referral_payout') >= 2,
    'Completed MFS retry cannot recover a missed referral payout'
);

echo "Referral reward system tests passed ({$referralAssertions} assertions).\n";
