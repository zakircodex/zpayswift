<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function zsky_creator_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function zsky_creator_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    zsky_creator_expect(is_file($path), 'missing file: ' . $relative);
    $source = file_get_contents($path);
    zsky_creator_expect($source !== false, 'unable to read: ' . $relative);
    return (string)$source;
}

$registry = zsky_creator_read('api/znews/lib/creator_registry.php');
$common = zsky_creator_read('api/znews/lib/common.php');
$bootstrap = zsky_creator_read('api/znews/bootstrap.php');
$viewPolicy = zsky_creator_read('api/znews/lib/creator_view_policy.php');
$start = zsky_creator_read('api/znews/views/start.php');
$payout = zsky_creator_read('api/znews/lib/creator_payout_batches.php');
$monthlyRevenue = zsky_creator_read('api/znews/lib/monthly_revenue.php');
$monthlyPayout = zsky_creator_read('api/znews/lib/monthly_creator_payouts.php');
$adsterra = zsky_creator_read('api/znews/lib/adsterra_publisher.php');
$activeList = zsky_creator_read('api/admin/znews/creators/list.php');
$statusEndpoint = zsky_creator_read('api/admin/znews/creators/status.php');
$preflight = zsky_creator_read('api/admin/znews/creators/payout_preflight.php');
$adminGateway = zsky_creator_read('api/admin/zsky24_creator_admin.php');
$adminJs = zsky_creator_read('api/admin/assets/zsky24-admin.js');
$adminCss = zsky_creator_read('api/admin/assets/zsky24-admin.css');
$ingest = zsky_creator_read('api/znews/ads/impressions/ingest.php');
$complete = zsky_creator_read('api/znews/views/complete.php');
$summary = zsky_creator_read('api/znews/balance/summary.php');
$ledger = zsky_creator_read('api/znews/balance/ledger.php');
$transfer = zsky_creator_read('api/znews/transfers/create.php');
$policy = zsky_creator_read('api/znews/public/policy.php');
$config = zsky_creator_read('znews/assets/znews-config.js');

foreach ([$registry, $viewPolicy, $payout, $activeList, $statusEndpoint, $preflight, $adminGateway] as $source) {
    zsky_creator_expect(str_contains($source, 'declare(strict_types=1);'), 'creator system file missing strict types');
}

zsky_creator_expect(str_contains($bootstrap, "creator_registry.php"), 'creator registry is not loaded by Z Sky bootstrap');
zsky_creator_expect(str_contains($common, 'znews_creator_registry_require_active'), 'creator actions do not enforce creator status');
zsky_creator_expect(str_contains($registry, 'ZNEWS_CREATORS/'), 'creator registry namespace missing');
zsky_creator_expect(str_contains($registry, 'ZNEWS_CREATORS_BY_STATUS/'), 'creator status index namespace missing');
zsky_creator_expect(str_contains($registry, "['ACTIVE', 'BLOCKED']"), 'creator status allowlist missing');
zsky_creator_expect(str_contains($registry, "'payout_eligible' => \$status === 'ACTIVE'"), 'blocked creator payout protection missing');
zsky_creator_expect(str_contains($registry, 'zpay_account_masked'), 'masked Z-Pay account reference missing');
zsky_creator_expect(!str_contains($registry, 'USER_WALLETS/') && !str_contains($registry, 'WALLET_LEDGER/'), 'creator registration mutates or depends on wallet ledgers');

zsky_creator_expect(str_contains($viewPolicy, ': 300;'), 'five-minute guest view window default missing');
zsky_creator_expect(str_contains($viewPolicy, ': 3;'), 'three-view guest limit default missing');
zsky_creator_expect(str_contains($viewPolicy, 'ZNEWS_GUEST_VIEW_WINDOWS/'), 'rolling guest window namespace missing');
zsky_creator_expect(str_contains($viewPolicy, "'viewer_class' => 'CREATOR'"), 'authenticated creator viewer class missing');
zsky_creator_expect(str_contains($viewPolicy, "'ad_eligible' => false"), 'creator no-ad rule missing');
zsky_creator_expect(str_contains($viewPolicy, 'GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'), 'guest spam reason missing');
zsky_creator_expect(str_contains($viewPolicy, "'invalid_views' => 1") && str_contains($viewPolicy, "'suspicious_views' => 1"), 'spam views are not marked invalid and suspicious');
zsky_creator_expect(str_contains($viewPolicy, "'events' => \$events"), 'rolling guest window lacks event-level idempotency ledger');
zsky_creator_expect(str_contains($viewPolicy, 'idempotent_replay'), 'guest view-window retry evidence missing');
zsky_creator_expect(str_contains($viewPolicy, "\$postId . '|' . \$idempotencyKey"), 'guest view event key is not bound to post and request idempotency');
zsky_creator_expect(str_contains($start, 'is_array($result[\'session\'] ?? null)'), 'failed view starts can incorrectly consume the guest ad window');
zsky_creator_expect(str_contains($start, 'znews_creator_view_gate($viewerUid, $postId, $idempotencyKey)'), 'view start does not use retry-safe creator/guest policy');
zsky_creator_expect(str_contains($start, 'znews_creator_view_policy_apply'), 'view start bypasses creator/guest policy application');
zsky_creator_expect(str_contains($start, "'ad_policy'"), 'view start does not return server ad policy');

zsky_creator_expect(str_contains($payout, ': 5;'), 'five-creator payout batch default missing');
zsky_creator_expect(str_contains($payout, 'min(5, $value)'), 'payout batch limit can exceed five');
zsky_creator_expect(str_contains($payout, 'ZNEWS_PAYOUT_BATCH_LIMIT_EXCEEDED'), 'payout batch overflow rejection missing');
zsky_creator_expect(str_contains($payout, "\$creatorStatus !== 'ACTIVE'"), 'blocked creator payout rejection missing');
zsky_creator_expect(str_contains($payout, "\$accountStatus !== 'ACTIVE'"), 'live inactive Z-Pay account rejection missing');
zsky_creator_expect(str_contains($payout, "['BDT', 'MYR']"), 'BDT/MYR payout currency support missing');
zsky_creator_expect(!str_contains($payout, 'wallet_credit_available') && !str_contains($payout, "fb_patch('USER_WALLETS/"), 'preflight performs a wallet credit');

zsky_creator_expect(str_contains($activeList, "api_require_method('GET')") && str_contains($activeList, 'auth_require_admin_session(true)'), 'creator list is not admin GET-only');
zsky_creator_expect(str_contains($activeList, "['ACTIVE', 'BLOCKED']"), 'active/blocked list filter missing');
zsky_creator_expect(str_contains($statusEndpoint, "api_require_method('POST')") && str_contains($statusEndpoint, 'auth_require_admin_session(true)'), 'creator status endpoint is not admin POST-only');
zsky_creator_expect(str_contains($statusEndpoint, 'ZNEWS_CREATOR_BLOCK_REASON_REQUIRED'), 'creator block reason is not required');
zsky_creator_expect(str_contains($preflight, "api_require_method('POST')") && str_contains($preflight, 'auth_require_admin_session(true)'), 'payout preflight is not admin POST-only');
zsky_creator_expect(str_contains($preflight, 'znews_creator_payout_batch_preflight'), 'payout endpoint bypasses preflight helper');

zsky_creator_expect(str_contains($adminGateway, "session_name('zawtopup_admin_v3')"), 'creator admin gateway does not reuse the protected dashboard session');
zsky_creator_expect(str_contains($adminGateway, "\$_SESSION['admin_session_token']"), 'creator admin gateway does not read the server-side admin token');
zsky_creator_expect(str_contains($adminGateway, "\$_SESSION['admin_csrf']"), 'creator admin gateway does not read the dashboard CSRF token');
zsky_creator_expect(str_contains($adminGateway, 'hash_equals($storedCsrf, $providedCsrf)'), 'creator admin gateway lacks timing-safe CSRF validation');
zsky_creator_expect(str_contains($adminGateway, "\$_SERVER['HTTP_X_SESSION_TOKEN'] = \$sessionToken"), 'creator admin gateway does not hand the token to the server auth layer');
zsky_creator_expect(str_contains($adminGateway, 'auth_require_admin_session(true)'), 'creator admin gateway lacks live admin authorization');
zsky_creator_expect(str_contains($adminGateway, "\$action === 'creators_list'"), 'creator list gateway action missing');
zsky_creator_expect(str_contains($adminGateway, "\$action === 'creator_status'"), 'creator status gateway action missing');
zsky_creator_expect(str_contains($adminGateway, "\$action === 'payout_preflight'"), 'payout preflight gateway action missing');
zsky_creator_expect(str_contains($adminGateway, "\$action === 'payout_execute'"), 'monthly payout execution gateway action missing');
zsky_creator_expect(str_contains($adminGateway, "\$action === 'revenue_sync'") && str_contains($adminGateway, "\$action === 'revenue_lock'"), 'Adsterra revenue admin actions missing');
zsky_creator_expect(str_contains($adminGateway, "\$action === 'payout_fx_lock'"), 'payout FX lock action missing');
zsky_creator_expect(str_contains($adminGateway, 'ZNEWS_CREATOR_BLOCK_REASON_REQUIRED'), 'gateway does not require a block reason');
zsky_creator_expect(!str_contains($adminGateway, 'wallet_credit_available') && !str_contains($adminGateway, 'wallet_debit_available'), 'creator admin gateway can mutate a wallet');

zsky_creator_expect(str_contains($adminJs, "const BATCH_LIMIT = 5"), 'admin UI batch limit is not five');
zsky_creator_expect(str_contains($adminJs, "activeStatus: 'ACTIVE'"), 'admin UI active creator tab missing');
zsky_creator_expect(str_contains($adminJs, 'blockedCreators: []'), 'admin UI blocked creator list missing');
zsky_creator_expect(str_contains($adminJs, 'selected: new Set()'), 'admin UI duplicate-safe selection missing');
zsky_creator_expect(str_contains($adminJs, 'data-zsky-creator-tab="ACTIVE"') && str_contains($adminJs, 'data-zsky-creator-tab="BLOCKED"'), 'active/blocked creator tabs missing');
zsky_creator_expect(str_contains($adminJs, 'data-zsky-block-creator') && str_contains($adminJs, 'data-zsky-unblock-creator'), 'creator block/unblock controls missing');
zsky_creator_expect(str_contains($adminJs, 'A block reason is required.'), 'admin UI block reason validation missing');
zsky_creator_expect(str_contains($adminJs, "request('payout_preflight'"), 'admin UI payout preflight call missing');
zsky_creator_expect(str_contains($adminJs, "request('payout_execute'") && str_contains($adminJs, 'Execute payout'), 'admin UI monthly payout execution is missing');
zsky_creator_expect(!str_contains($adminJs, 'wallet_credit_available') && !str_contains($adminJs, 'Approve transfer'), 'browser code exposes wallet internals or legacy transfer UI');
zsky_creator_expect(str_contains($adminCss, '.zsky-payout-dock') && str_contains($adminCss, '.zsky-preflight-list'), 'creator payout preview styles missing');

zsky_creator_expect(str_contains($adsterra, "getenv(\$name)") && str_contains($adsterra, "'X-API-Key: ' . \$token"), 'Adsterra private environment/header contract missing');
zsky_creator_expect(!str_contains($adsterra, "echo \$token") && !str_contains($adsterra, "'token' => \$token"), 'Adsterra token can be emitted.');
zsky_creator_expect(str_contains($monthlyRevenue, "'safety_reserve_bps' => 1000") && str_contains($monthlyRevenue, "'creator_effective_gross_bps' => 3600"), '10/36/54 revenue formula missing');
zsky_creator_expect(str_contains($monthlyPayout, 'wallet_financial_operation_begin(') && str_contains($monthlyPayout, 'wallet_credit_available('), 'monthly payout bypasses canonical wallet helper');
zsky_creator_expect(str_contains($monthlyPayout, "'ADSTERRA|' . \$monthId . '|' . \$uid"), 'provider/month/creator payout identity missing');
zsky_creator_expect(!str_contains($monthlyPayout, 'phone_country'), 'monthly payout uses phone_country');

zsky_creator_expect(!str_contains($ingest, 'settlements_auto.php'), 'per-impression auto settlement is still loaded');
zsky_creator_expect(str_contains($ingest, 'DISABLED_PERIOD_REVENUE_PAYOUT'), 'auto-credit disabled status missing');
zsky_creator_expect(!str_contains($complete, 'settlements_auto.php'), 'per-view auto settlement is still loaded');
zsky_creator_expect(!str_contains($complete, 'znews_auto_settle_view_impressions'), 'view completion still triggers per-view auto settlement');
zsky_creator_expect(str_contains($summary, "'creator_balance_enabled' => false"), 'balance summary remains enabled');
zsky_creator_expect(str_contains($ledger, "'creator_balance_enabled' => false"), 'balance ledger remains enabled');
zsky_creator_expect(str_contains($transfer, 'ZNEWS_CREATOR_WITHDRAW_DISABLED') && str_contains($transfer, '410'), 'legacy creator withdrawal is not retired');
zsky_creator_expect(str_contains($policy, "'performance_review_days' => 7"), 'weekly review policy missing');
zsky_creator_expect(str_contains($policy, "'payout_cycle' => 'MONTHLY'"), 'monthly payout policy missing');
zsky_creator_expect(str_contains($policy, "'safety_reserve_percent' => 10"), 'ten-percent reserve policy missing');
zsky_creator_expect(str_contains($policy, "'creator_pool_percent_of_net' => 40"), 'forty-percent creator pool policy missing');

zsky_creator_expect(str_contains($config, "provider: 'ADSTERRA'"), 'Adsterra Web provider is not active');
zsky_creator_expect(str_contains($config, 'creatorBalanceEnabled: false'), 'creator balance UI feature flag missing');
zsky_creator_expect(!str_contains($config, "document.querySelectorAll('.ad-slot')"), 'revenue UI observer still deletes server-gated ads');
zsky_creator_expect(str_contains($config, 'Weekly review • Monthly payout'), 'period payout UI policy missing');
zsky_creator_expect(str_contains($config, '[data-route="balance"]'), 'legacy balance navigation is not hidden');

echo "Z Sky 24 creator period payout tests passed ({$assertions} assertions).\n";
