<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function auto_credit_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function auto_credit_read(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    auto_credit_expect(is_string($source), "Unable to read {$path}");
    return $source;
}

$ingest = auto_credit_read($root, 'api/znews/ads/impressions/ingest.php');
auto_credit_expect(!str_contains($ingest, 'settlements_auto.php'), 'Provider ingestion still loads legacy per-impression settlement.');
auto_credit_expect(str_contains($ingest, 'DISABLED_PERIOD_REVENUE_PAYOUT'), 'Provider ingestion does not report period-review payout mode.');

$complete = auto_credit_read($root, 'api/znews/views/complete.php');
auto_credit_expect(!str_contains($complete, 'settlements_auto.php'), 'View completion still loads legacy per-view settlement.');
auto_credit_expect(!str_contains($complete, 'znews_auto_settle_view_impressions'), 'View completion still invokes legacy per-view settlement.');
auto_credit_expect(str_contains($complete, 'DISABLED_PERIOD_REVENUE_PAYOUT'), 'View completion does not report period-review payout mode.');

$retryTool = auto_credit_read($root, 'tools/zsky24_auto_settlement_retry.php');
auto_credit_expect(!str_contains($retryTool, 'settlements_auto.php') && !str_contains($retryTool, 'znews_auto_settle'), 'Retired CLI tool can still execute legacy settlement.');
auto_credit_expect(str_contains($retryTool, 'no balances were changed'), 'Retired CLI tool does not fail closed with a clear status.');

$summary = auto_credit_read($root, 'api/znews/balance/summary.php');
auto_credit_expect(str_contains($summary, "'revenue_mode' => 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT'"), 'Balance summary is not in period-review payout mode.');
auto_credit_expect(str_contains($summary, "'creator_balance_enabled' => false"), 'Legacy creator balance remains enabled.');
auto_credit_expect(str_contains($summary, "'withdraw_request_enabled' => false"), 'Legacy creator withdrawal remains enabled.');

$policy = auto_credit_read($root, 'api/znews/public/policy.php');
auto_credit_expect(str_contains($policy, "'automatic_per_ad_credit_enabled' => false"), 'Public policy exposes automatic per-ad credit as enabled.');
auto_credit_expect(str_contains($policy, "'client_submitted_revenue_allowed' => false"), 'Public policy allows client-submitted revenue.');

$transfer = auto_credit_read($root, 'api/znews/transfers/create.php');
auto_credit_expect(str_contains($transfer, 'ZNEWS_CREATOR_WITHDRAW_DISABLED') && str_contains($transfer, '410'), 'Legacy creator transfer endpoint is not retired.');

echo "Z News automatic creator credit retirement tests passed ({$assertions} assertions).\n";
