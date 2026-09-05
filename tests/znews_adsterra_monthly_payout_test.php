<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$db = [];
$versions = [];
$accounts = [];
$monthlyFixture = [];

function znews_adsterra_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_now(): int
{
    return 1788134400;
}

function znews_firebase_key($value, string $field = 'id'): string
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid ' . $field);
    }
    return $value;
}

function znews_idempotency_key($value): string
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]{8,160}$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid idempotency key');
    }
    return $value;
}

function znews_creator_payout_batch_limit(): int
{
    return 5;
}

function znews_creator_payout_batch_preflight(array $creatorUids): array
{
    global $accounts;
    $creatorUids = array_values(array_unique(array_map('strval', $creatorUids)));
    if ($creatorUids === [] || count($creatorUids) > 5) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_BATCH_LIMIT_EXCEEDED'];
    }
    $rows = [];
    foreach ($creatorUids as $uid) {
        if (!isset($accounts[$uid])) {
            return ['ok' => false, 'code' => 'ZNEWS_CREATOR_NOT_READY'];
        }
        $rows[] = $accounts[$uid];
    }
    return ['ok' => true, 'creators' => $rows];
}

function znews_monthly_performance_month(string $monthId = '', ?int $anchor = null): array
{
    $monthId = $monthId !== '' ? $monthId : '2026-08';
    if (preg_match('/^\d{4}-\d{2}$/D', $monthId) !== 1) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_PERIOD_INVALID'];
    }
    return [
        'ok' => true,
        'month_id' => $monthId,
        'month_start_date' => $monthId . '-01',
        'month_end_date' => $monthId . '-31',
        'completed' => $monthId !== '2026-09',
        'upcoming' => false,
    ];
}

function znews_monthly_performance_preview(string $monthId = '', ?int $anchor = null): array
{
    global $monthlyFixture;
    return $monthlyFixture;
}

function fb_get(string $path, array $query = [])
{
    global $db;
    return $db[$path] ?? null;
}

function fb_get_with_etag(string $path): array
{
    global $db, $versions;
    return [
        'ok' => true,
        'value' => $db[$path] ?? null,
        'etag' => 'v' . (int)($versions[$path] ?? 0),
    ];
}

function fb_put_if_match(string $path, $value, string $etag): array
{
    global $db, $versions;
    if ($etag !== 'v' . (int)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    $db[$path] = $value;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

function fb_put(string $path, $value): bool
{
    global $db, $versions;
    $db[$path] = $value;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
    return true;
}

function fb_patch(string $path, array $patch): bool
{
    global $db, $versions;
    if ($path === '') {
        foreach ($patch as $childPath => $value) {
            if ($value === null) {
                unset($db[$childPath]);
            } else {
                $db[$childPath] = $value;
            }
            $versions[$childPath] = (int)($versions[$childPath] ?? 0) + 1;
        }
        return true;
    }
    $row = is_array($db[$path] ?? null) ? (array)$db[$path] : [];
    foreach ($patch as $key => $value) {
        if ($value === null) {
            unset($row[$key]);
        } else {
            $row[$key] = $value;
        }
    }
    $db[$path] = $row;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
    return true;
}

function fb_delete(string $path): bool
{
    global $db, $versions;
    unset($db[$path]);
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
    return true;
}

putenv('ADSTERRA_PUBLISHER_API_TOKEN=unit-test-private-value');
putenv('ADSTERRA_ZSKY24_DOMAIN_ID=123456');

require_once $root . '/api/znews/lib/monthly_creator_payouts.php';

$capturedUrl = '';
$capturedHeaders = [];
$provider = znews_adsterra_fetch_stats('2026-08-01', '2026-08-31', false,
    static function (string $url, array $headers, int $timeout) use (&$capturedUrl, &$capturedHeaders): array {
        $capturedUrl = $url;
        $capturedHeaders = $headers;
        return [
            'ok' => true,
            'status' => 200,
            'body' => json_encode(['items' => [[
                'date' => '2026-08-31',
                'domain_id' => '123456',
                'placement_id' => '789',
                'impressions' => 1000,
                'clicks' => 25,
                'ctr' => '2.5',
                'cpm' => '100',
                'revenue' => '100.00',
            ]]], JSON_THROW_ON_ERROR),
        ];
    }
);
znews_adsterra_test_expect(!empty($provider['ok']), 'Adsterra report was not normalized.');
znews_adsterra_test_expect((int)$provider['revenue_usd_micros'] === 100000000, 'Provider USD micros are wrong.');
znews_adsterra_test_expect(str_contains($capturedUrl, 'domain=123456'), 'Provider domain is not server-scoped.');
znews_adsterra_test_expect(substr_count($capturedUrl, 'group_by%5B%5D=') === 3, 'Provider grouping does not use the documented repeated query shape.');
znews_adsterra_test_expect(!str_contains($capturedUrl, 'unit-test-private-value'), 'Provider token leaked into URL.');
znews_adsterra_test_expect(in_array('X-API-Key: unit-test-private-value', $capturedHeaders, true), 'Private API header is missing.');
znews_adsterra_test_expect(!str_contains(json_encode($provider), 'unit-test-private-value'), 'Provider token leaked into normalized data.');
$malformedProvider = znews_adsterra_normalize_report('{not-json', '123456');
znews_adsterra_test_expect(
    empty($malformedProvider['ok']) && (string)($malformedProvider['code'] ?? '') === 'ADSTERRA_RESPONSE_INVALID_JSON',
    'Malformed provider JSON was not rejected.'
);
putenv('ADSTERRA_PUBLISHER_API_TOKEN');
$missingConfig = znews_adsterra_fetch_stats('2026-08-01', '2026-08-31', false);
znews_adsterra_test_expect(
    empty($missingConfig['ok']) && (string)($missingConfig['code'] ?? '') === 'ADSTERRA_PRIVATE_CONFIG_MISSING',
    'Missing private provider configuration was not rejected.'
);
putenv('ADSTERRA_PUBLISHER_API_TOKEN=unit-test-private-value');

$formula = znews_monthly_revenue_formula(100000000);
znews_adsterra_test_expect((int)$formula['safety_reserve_usd_micros'] === 10000000, '$100 reserve is not $10.');
znews_adsterra_test_expect((int)$formula['distributable_usd_micros'] === 90000000, '$100 distributable is not $90.');
znews_adsterra_test_expect((int)$formula['creator_pool_usd_micros'] === 36000000, '$100 creator pool is not $36.');
znews_adsterra_test_expect((int)$formula['platform_share_usd_micros'] === 54000000, '$100 platform share is not $54.');

$items = [
    ['creator_uid' => 'CREATOR_A', 'settlement_eligible_views' => 750],
    ['creator_uid' => 'CREATOR_B', 'settlement_eligible_views' => 250],
];
$allocations = znews_monthly_creator_allocations($items, 36000000);
znews_adsterra_test_expect((int)$allocations['CREATOR_A']['creator_share_usd_micros'] === 27000000, 'Creator A share is not $27.');
znews_adsterra_test_expect((int)$allocations['CREATOR_B']['creator_share_usd_micros'] === 9000000, 'Creator B share is not $9.');
znews_adsterra_test_expect(array_sum(array_column($allocations, 'creator_share_usd_micros')) === 36000000, 'Creator allocation is not lossless.');
znews_adsterra_test_expect(znews_monthly_native_amount_micros(27000000, 4200000) === 113400000, 'MYR conversion is not RM113.40.');
znews_adsterra_test_expect(znews_monthly_native_amount_micros(9000000, 122000000) === 1098000000, 'BDT conversion is not 1098.00.');
$overflowAllocation = znews_monthly_creator_allocations([
    ['creator_uid' => 'OVERFLOW_A', 'settlement_eligible_views' => PHP_INT_MAX],
    ['creator_uid' => 'OVERFLOW_B', 'settlement_eligible_views' => PHP_INT_MAX],
], 1000000);
znews_adsterra_test_expect($overflowAllocation === [], 'Eligible-view overflow was not rejected.');

$db[znews_monthly_revenue_sync_path('2026-08')] = [
    'provider' => 'ADSTERRA',
    'month_id' => '2026-08',
    'source_status' => 'FINAL_SYNCED',
    'sync_id' => 'SYNC_FINAL_202608',
    'revenue_usd_micros' => 100000000,
    'provider_reported_at' => znews_now(),
    'provider_domain_id' => '123456',
    'report_start_date' => '2026-08-01',
    'report_finish_date' => '2026-08-31',
    'report_row_count' => 1,
    'impressions' => 1000,
    'clicks' => 25,
];
$lock = znews_monthly_revenue_lock('2026-08', 'SYNC_FINAL_202608', 'ADMIN_A');
znews_adsterra_test_expect(!empty($lock['ok']) && empty($lock['idempotent_replay']), 'Final revenue did not lock.');
$lockReplay = znews_monthly_revenue_lock('2026-08', 'SYNC_FINAL_202608', 'ADMIN_B');
znews_adsterra_test_expect(!empty($lockReplay['idempotent_replay']), 'Revenue lock is not immutable/idempotent.');
znews_adsterra_test_expect((int)$db[znews_monthly_revenue_lock_path('2026-08')]['gross_settled_usd_micros'] === 100000000, 'Locked gross changed.');

$fxMyr = znews_monthly_fx_lock('2026-08', 'MYR', '4.20', 'TEST_FX_FIXTURE', znews_now(), 'ADMIN_A');
$fxBdt = znews_monthly_fx_lock('2026-08', 'BDT', '122.00', 'TEST_FX_FIXTURE', znews_now(), 'ADMIN_A');
znews_adsterra_test_expect(!empty($fxMyr['ok']) && !empty($fxBdt['ok']), 'Payout FX snapshots did not lock.');
$fxReplay = znews_monthly_fx_lock('2026-08', 'MYR', '5.00', 'OTHER_SOURCE', znews_now(), 'ADMIN_B');
znews_adsterra_test_expect(!empty($fxReplay['idempotent_replay']) && (string)$fxReplay['fx']['rate'] === '4.2', 'Locked FX changed silently.');

$monthlyFixture = [
    'ok' => true,
    'month' => znews_monthly_performance_month('2026-08'),
    'items' => $items,
    'summary' => [
        'all_periods_generated' => true,
        'all_creator_reviews_complete' => true,
    ],
];
$accounts = [
    'CREATOR_A' => ['creator_uid' => 'CREATOR_A', 'zpay_uid' => 'CREATOR_A', 'name' => 'Creator A', 'wallet_currency' => 'MYR'],
    'CREATOR_B' => ['creator_uid' => 'CREATOR_B', 'zpay_uid' => 'CREATOR_B', 'name' => 'Creator B', 'wallet_currency' => 'BDT'],
];
$db['USERS/CREATOR_A'] = ['uid' => 'CREATOR_A', 'status' => 'ACTIVE', 'role' => 'USER', 'currency' => 'MYR'];
$db['USERS/CREATOR_B'] = ['uid' => 'CREATOR_B', 'status' => 'ACTIVE', 'role' => 'RETAILER', 'currency' => 'BDT'];
$db['USER_WALLETS/CREATOR_A'] = ['currency' => 'MYR', 'available_balance' => 0.0, 'hold_balance' => 0.0];
$db['USER_WALLETS/CREATOR_B'] = ['currency' => 'BDT', 'available_balance' => 0.0, 'hold_balance' => 0.0];

$preflight = znews_monthly_payout_preflight('2026-08', ['CREATOR_A', 'CREATOR_B']);
znews_adsterra_test_expect(!empty($preflight['ok']) && count($preflight['creators']) === 2, 'Monthly payout preflight failed.');
$native = array_column($preflight['creators'], 'wallet_amount_micros', 'creator_uid');
znews_adsterra_test_expect((int)$native['CREATOR_A'] === 113400000, 'Preflight MYR amount changed.');
znews_adsterra_test_expect((int)$native['CREATOR_B'] === 1098000000, 'Preflight BDT amount changed.');

$execution = znews_monthly_payout_execute('2026-08', ['CREATOR_A', 'CREATOR_B'], 'MONTHLY-PAYOUT-202608-A', 'ADMIN_A');
znews_adsterra_test_expect(!empty($execution['ok']), 'Canonical wallet payout execution failed.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_A']['available_balance'] === 113.4, 'Creator A wallet credit is wrong.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_B']['available_balance'] === 1098.0, 'Creator B wallet credit is wrong.');
$executionReplay = znews_monthly_payout_execute('2026-08', ['CREATOR_A', 'CREATOR_B'], 'MONTHLY-PAYOUT-202608-A', 'ADMIN_A');
znews_adsterra_test_expect(!empty($executionReplay['ok']) && !empty($executionReplay['idempotent_replay']), 'Completed payout retry did not replay canonically.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_A']['available_balance'] === 113.4, 'Creator A was credited twice.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_B']['available_balance'] === 1098.0, 'Creator B was credited twice.');

$normalAfterPaid = znews_monthly_payout_preflight('2026-08', ['CREATOR_A']);
znews_adsterra_test_expect(empty($normalAfterPaid['ok']) && ($normalAfterPaid['rejected'][0]['code'] ?? '') === 'ZNEWS_CREATOR_MONTH_ALREADY_PAID', 'Read-only preflight did not reject an already-paid creator.');
$openMonth = znews_monthly_payout_preflight('2026-09', ['CREATOR_A']);
znews_adsterra_test_expect(empty($openMonth['ok']) && ($openMonth['code'] ?? '') === 'ZNEWS_PAYOUT_MONTH_OPEN', 'Open month was not rejected.');

$db[znews_monthly_revenue_lock_path('2026-07')] = array_merge([
    'provider' => 'ADSTERRA', 'month_id' => '2026-07', 'status' => 'LOCKED',
], znews_monthly_revenue_formula(10000000));
$db[znews_monthly_fx_path('2026-07', 'MYR')] = [
    'provider' => 'ADSTERRA', 'month_id' => '2026-07', 'currency_pair' => 'USD_MYR',
    'rate_micros' => 4200000, 'status' => 'LOCKED', 'locked_at' => znews_now(),
];
$monthlyFixture = [
    'ok' => true,
    'month' => znews_monthly_performance_month('2026-07'),
    'items' => [
        ['creator_uid' => 'CREATOR_C', 'settlement_eligible_views' => 1],
        ['creator_uid' => 'CREATOR_D', 'settlement_eligible_views' => 1],
    ],
    'summary' => ['all_periods_generated' => true, 'all_creator_reviews_complete' => true],
];
$accounts['CREATOR_C'] = ['creator_uid' => 'CREATOR_C', 'zpay_uid' => 'CREATOR_C', 'name' => 'Creator C', 'wallet_currency' => 'MYR'];
$accounts['CREATOR_D'] = ['creator_uid' => 'CREATOR_D', 'zpay_uid' => 'CREATOR_D', 'name' => 'Creator D', 'wallet_currency' => 'MYR'];
$db['USERS/CREATOR_C'] = ['uid' => 'CREATOR_C', 'status' => 'ACTIVE', 'role' => 'USER', 'currency' => 'MYR'];
$db['USERS/CREATOR_D'] = ['uid' => 'CREATOR_D', 'status' => 'ACTIVE', 'role' => 'USER', 'currency' => 'MYR'];
$db['USER_WALLETS/CREATOR_C'] = ['currency' => 'MYR', 'available_balance' => 0.0, 'hold_balance' => 0.0];
$partial = znews_monthly_payout_execute('2026-07', ['CREATOR_C', 'CREATOR_D'], 'MONTHLY-PAYOUT-202607-A', 'ADMIN_A');
znews_adsterra_test_expect(empty($partial['ok']) && ($partial['code'] ?? '') === 'ZNEWS_PAYOUT_BATCH_PARTIAL_FAILED', 'Partial payout failure was not reported.');
$creatorCAfterPartial = (float)$db['USER_WALLETS/CREATOR_C']['available_balance'];
znews_adsterra_test_expect($creatorCAfterPartial > 0, 'Successful item in a partial batch was not credited.');
$db['USER_WALLETS/CREATOR_D'] = ['currency' => 'MYR', 'available_balance' => 0.0, 'hold_balance' => 0.0];
$partialRetry = znews_monthly_payout_execute('2026-07', ['CREATOR_C', 'CREATOR_D'], 'MONTHLY-PAYOUT-202607-A', 'ADMIN_A');
znews_adsterra_test_expect(!empty($partialRetry['ok']), 'Failed item could not be retried safely.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_C']['available_balance'] === $creatorCAfterPartial, 'Partial retry duplicated the successful creator.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_D']['available_balance'] > 0, 'Partial retry did not credit the failed creator.');
$creatorDAfterRetry = (float)$db['USER_WALLETS/CREATOR_D']['available_balance'];
$newBatchReplay = znews_monthly_payout_execute('2026-07', ['CREATOR_C', 'CREATOR_D'], 'MONTHLY-PAYOUT-202607-B', 'ADMIN_B');
znews_adsterra_test_expect(!empty($newBatchReplay['ok']), 'Provider/month/creator replay with another batch key failed.');
znews_adsterra_test_expect((float)$db['USER_WALLETS/CREATOR_C']['available_balance'] === $creatorCAfterPartial
    && (float)$db['USER_WALLETS/CREATOR_D']['available_balance'] === $creatorDAfterRetry, 'New batch key duplicated a monthly creator payout.');

$gatewaySource = (string)file_get_contents($root . '/api/admin/zsky24_creator_admin.php');
$payoutSource = (string)file_get_contents($root . '/api/znews/lib/monthly_creator_payouts.php');
znews_adsterra_test_expect(str_contains($gatewaySource, "\$action === 'payout_execute'") && str_contains($gatewaySource, 'hash_equals(\'EXECUTE_PAYOUT\''), 'Protected payout action/confirmation is missing.');
znews_adsterra_test_expect(str_contains($payoutSource, 'wallet_credit_available(') && str_contains($payoutSource, 'wallet_financial_operation_begin('), 'Canonical wallet helper integration is missing.');
znews_adsterra_test_expect(!str_contains($payoutSource, 'phone_country'), 'Payout currency depends on phone_country.');
znews_adsterra_test_expect(!str_contains($gatewaySource, "\$body['wallet_amount']") && !str_contains($gatewaySource, "\$body['fx_rate']"), 'Gateway trusts browser-submitted payout money.');

putenv('ADSTERRA_PUBLISHER_API_TOKEN');
putenv('ADSTERRA_ZSKY24_DOMAIN_ID');

echo "Z Sky Adsterra monthly payout tests passed ({$assertions} assertions).\n";
