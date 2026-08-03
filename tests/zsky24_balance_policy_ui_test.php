<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$policyPath = $root . '/api/znews/lib/settlement_payout_policy.php';
$policy = file_get_contents($policyPath);
$common = file_get_contents($root . '/api/znews/lib/settlements_common.php');
$service = file_get_contents($root . '/api/znews/lib/settlements_service.php');
$summary = file_get_contents($root . '/api/znews/balance/summary.php');
$publicPolicy = file_get_contents($root . '/api/znews/public/policy.php');
$index = file_get_contents($root . '/znews/index.html');
$app = file_get_contents($root . '/znews/assets/znews.js');
$api = file_get_contents($root . '/znews/assets/znews-api.js');
$css = file_get_contents($root . '/znews/assets/znews.css');
$bootstrap = file_get_contents($root . '/znews/assets/znews-bootstrap.js');
$embeddedWorker = file_get_contents($root . '/znews/sw.js');
$standaloneWorker = file_get_contents($root . '/znews/sw-root.js');

function balance_policy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

balance_policy_expect(str_contains($policy, 'return 10000; // BDT 0.01 (one paisa).'), 'Whole-paisa payout unit is missing.');
balance_policy_expect(str_contains($policy, 'min(30000, $configured)'), 'The BDT 0.03 hard maximum is missing.');
balance_policy_expect(str_contains($policy, 'return $creator - ($creator % $unit);'), 'Creator payout is not rounded down to whole paisa.');
balance_policy_expect(str_contains($common, 'znews_settlement_apply_bdt_creator_ad_policy($creator)'), 'Provider-derived creator share is not using the BDT policy.');
balance_policy_expect(str_contains($common, '$platform = $grossMicros - $creator;'), 'Platform remainder is not rounding safe.');
balance_policy_expect(str_contains($common, "'client_submitted_value_allowed' => false"), 'Client financial-value prohibition is missing.');
balance_policy_expect(substr_count($service, 'znews_settlement_allocation($grossMicros, $currency)') >= 2, 'Settlement does not bind allocation to provider currency.');
balance_policy_expect(str_contains($summary, "'creator_ad_payout_policy' => znews_settlement_creator_ad_payout_policy()"), 'Balance summary does not expose the server policy.');
balance_policy_expect(str_contains($publicPolicy, "'test_ads_payable' => false"), 'Public policy does not disclose that test ads are not payable.');
balance_policy_expect(str_contains($publicPolicy, "'client_submitted_value_allowed' => false"), 'Public policy does not preserve the client-value prohibition.');
balance_policy_expect(str_contains($publicPolicy, 'znews_transfer_threshold_bdt_micros()'), 'Public policy is not using the server transfer threshold.');

foreach (['id="policyView"', 'id="creatorAdRate"', 'id="creatorAdRateNote"', 'class="page-card card balance-activity-card"'] as $marker) {
    balance_policy_expect(str_contains($index, $marker), "Balance UI marker is missing: {$marker}");
}
balance_policy_expect(str_contains($app, 'function renderCreatorAdRate(summary)'), 'Balance UI does not render the server policy.');
balance_policy_expect(str_contains($app, 'summary?.data?.creator_ad_payout_policy'), 'Balance UI is not reading the authenticated summary policy.');
balance_policy_expect(str_contains($api, 'publicCreatorPolicy()'), 'Public policy API client method is missing.');
balance_policy_expect(str_contains($app, 'api.publicCreatorPolicy()'), 'Policy page does not load the current server policy.');
balance_policy_expect(!str_contains(substr($index, strpos($index, 'id="balanceView"'), strpos($index, 'id="policyView"') - strpos($index, 'id="balanceView"')), 'id="creatorAdRate"'), 'Per-ad credit must not be shown inside the balance card.');
balance_policy_expect(str_contains($index, 'data-route="policy"'), 'Creator policy navigation is missing.');
balance_policy_expect(str_contains($index, 'Test ads and test placement activity do not create payable credit.'), 'Test-ad disclosure is missing.');
balance_policy_expect(str_contains($index, 'The current minimum request is ৳200 BDT equivalent.'), 'BDT 200 policy disclosure is missing.');
balance_policy_expect(str_contains($css, '#balanceView.active { display: grid; gap: 20px; }'), 'Balance cards do not have explicit spacing.');
balance_policy_expect(str_contains($index, 'znews.css?v=4'), 'Balance stylesheet is not activated.');
balance_policy_expect(str_contains($index, 'znews-bootstrap.js?v=18'), 'Balance bootstrap is not activated.');
balance_policy_expect(str_contains($bootstrap, 'znews.js?v=17'), 'Balance behavior is not activated.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    balance_policy_expect(str_contains($worker, 'znews.css?v=4'), 'A PWA shell is missing the balance stylesheet.');
    balance_policy_expect(str_contains($worker, 'znews.js?v=17'), 'A PWA shell is missing the balance behavior.');
    balance_policy_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'A PWA shell may cache financial API responses.');
}
balance_policy_expect(str_contains($standaloneWorker, "url.pathname === '/policy'"), 'Standalone PWA policy navigation fallback is missing.');

require_once $policyPath;
balance_policy_expect(znews_settlement_apply_bdt_creator_ad_policy(500000) === 30000, 'BDT 0.50 was not capped to BDT 0.03.');
balance_policy_expect(znews_settlement_apply_bdt_creator_ad_policy(25000) === 20000, 'BDT 0.025 was not rounded down to BDT 0.02.');
balance_policy_expect(znews_settlement_apply_bdt_creator_ad_policy(15000) === 10000, 'BDT 0.015 was not rounded down to BDT 0.01.');
balance_policy_expect(znews_settlement_apply_bdt_creator_ad_policy(9999) === 0, 'Sub-paisa revenue must not be rounded up.');

echo "Z Sky 24 balance policy and UI checks passed.\n";
