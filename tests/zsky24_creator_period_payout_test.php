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
$activeList = zsky_creator_read('api/admin/znews/creators/list.php');
$statusEndpoint = zsky_creator_read('api/admin/znews/creators/status.php');
$preflight = zsky_creator_read('api/admin/znews/creators/payout_preflight.php');
$ingest = zsky_creator_read('api/znews/ads/impressions/ingest.php');
$summary = zsky_creator_read('api/znews/balance/summary.php');
$ledger = zsky_creator_read('api/znews/balance/ledger.php');
$transfer = zsky_creator_read('api/znews/transfers/create.php');
$policy = zsky_creator_read('api/znews/public/policy.php');
$config = zsky_creator_read('znews/assets/znews-config.js');

foreach ([$registry, $viewPolicy, $payout, $activeList, $statusEndpoint, $preflight] as $source) {
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
zsky_creator_expect(!str_contains($payout, 'wallet_credit_available') && !str_contains($payout, 'fb_patch(\'USER_WALLETS/'), 'preflight performs a wallet credit');

zsky_creator_expect(str_contains($activeList, "api_require_method('GET')") && str_contains($activeList, 'auth_require_admin_session(true)'), 'creator list is not admin GET-only');
zsky_creator_expect(str_contains($activeList, "['ACTIVE', 'BLOCKED']"), 'active/blocked list filter missing');
zsky_creator_expect(str_contains($statusEndpoint, "api_require_method('POST')") && str_contains($statusEndpoint, 'auth_require_admin_session(true)'), 'creator status endpoint is not admin POST-only');
zsky_creator_expect(str_contains($statusEndpoint, 'ZNEWS_CREATOR_BLOCK_REASON_REQUIRED'), 'creator block reason is not required');
zsky_creator_expect(str_contains($preflight, "api_require_method('POST')") && str_contains($preflight, 'auth_require_admin_session(true)'), 'payout preflight is not admin POST-only');
zsky_creator_expect(str_contains($preflight, 'znews_creator_payout_batch_preflight'), 'payout endpoint bypasses preflight helper');

zsky_creator_expect(!str_contains($ingest, 'settlements_auto.php'), 'per-impression auto settlement is still loaded');
zsky_creator_expect(str_contains($ingest, 'DISABLED_PERIOD_REVENUE_PAYOUT'), 'auto-credit disabled status missing');
zsky_creator_expect(str_contains($summary, "'creator_balance_enabled' => false"), 'balance summary remains enabled');
zsky_creator_expect(str_contains($ledger, "'creator_balance_enabled' => false"), 'balance ledger remains enabled');
zsky_creator_expect(str_contains($transfer, 'ZNEWS_CREATOR_WITHDRAW_DISABLED') && str_contains($transfer, '410'), 'legacy creator withdrawal is not retired');
zsky_creator_expect(str_contains($policy, "'performance_review_days' => 7"), 'weekly review policy missing');
zsky_creator_expect(str_contains($policy, "'payout_cycle' => 'MONTHLY'"), 'monthly payout policy missing');
zsky_creator_expect(str_contains($policy, "'safety_reserve_percent' => 10"), 'ten-percent reserve policy missing');
zsky_creator_expect(str_contains($policy, "'creator_pool_percent_of_net' => 40"), 'forty-percent creator pool policy missing');

zsky_creator_expect(str_contains($config, "provider: 'NONE'"), 'legacy ad provider remains active');
zsky_creator_expect(str_contains($config, 'creatorBalanceEnabled: false'), 'creator balance UI feature flag missing');
zsky_creator_expect(str_contains($config, "document.querySelectorAll('.ad-slot')"), 'ad slots are not disabled before provider integration');
zsky_creator_expect(str_contains($config, 'Weekly review • Monthly payout'), 'period payout UI policy missing');
zsky_creator_expect(str_contains($config, '[data-route="balance"]'), 'legacy balance navigation is not hidden');

echo "Z Sky 24 creator period payout tests passed ({$assertions} assertions).\n";
