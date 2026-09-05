<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function balance_policy_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function balance_policy_read(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    balance_policy_expect(is_string($source), "Unable to read {$path}");
    return $source;
}

$summary = balance_policy_read($root, 'api/znews/balance/summary.php');
$publicPolicy = balance_policy_read($root, 'api/znews/public/policy.php');
$index = balance_policy_read($root, 'znews/index.html');
$config = balance_policy_read($root, 'znews/assets/znews-config.js');
$bootstrap = balance_policy_read($root, 'znews/assets/znews-bootstrap.js');
$embeddedWorker = balance_policy_read($root, 'znews/sw.js');
$standaloneWorker = balance_policy_read($root, 'znews/sw-root.js');

balance_policy_expect(str_contains($summary, "'revenue_mode' => 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT'"), 'Authenticated summary does not expose period-review payout mode.');
balance_policy_expect(str_contains($summary, "'creator_balance_enabled' => false"), 'Authenticated summary exposes the retired creator balance.');
balance_policy_expect(str_contains($summary, "'main_wallet_transfer_enabled' => false"), 'Authenticated summary exposes the retired wallet transfer.');
balance_policy_expect(str_contains($summary, "'withdraw_request_enabled' => false"), 'Authenticated summary exposes the retired withdrawal flow.');

balance_policy_expect(str_contains($publicPolicy, "'performance_review_days' => 7"), 'Seven-day performance review policy is missing.');
balance_policy_expect(str_contains($publicPolicy, "'payout_cycle' => 'MONTHLY'"), 'Monthly payout policy is missing.');
balance_policy_expect(str_contains($publicPolicy, "'automatic_per_ad_credit_enabled' => false"), 'Public policy exposes automatic per-ad credit.');
balance_policy_expect(str_contains($publicPolicy, "'client_submitted_revenue_allowed' => false"), 'Public policy allows client-submitted revenue.');

foreach (['id="weeklyCurrentReview"', 'id="weeklyCurrentMetrics"', 'id="weeklyCurrentNote"', 'id="policyView"'] as $marker) {
    balance_policy_expect(str_contains($index, $marker), "Current period-review UI marker is missing: {$marker}");
}
balance_policy_expect(str_contains($index, 'class="weekly-performance-compat" aria-hidden="true"'), 'Legacy compatibility content is not hidden.');
balance_policy_expect(str_contains($config, "provider: 'ADSTERRA'"), 'Adsterra Web provider is not active.');
balance_policy_expect(str_contains($config, 'creatorBalanceEnabled: false'), 'Creator balance feature flag remains enabled.');
balance_policy_expect(str_contains($index, 'znews.js?v=29'), 'Current feed application cache version is not active.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    balance_policy_expect(str_contains($worker, 'znews.js?v=29'), 'A PWA shell is missing the current application asset.');
    balance_policy_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'A PWA shell may cache API responses.');
}

echo "Z Sky 24 period-review policy and UI checks passed ({$assertions} assertions).\n";
