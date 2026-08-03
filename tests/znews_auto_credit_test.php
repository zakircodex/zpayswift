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

$auto = auto_credit_read($root, 'api/znews/lib/settlements_auto.php');
auto_credit_expect(str_contains($auto, "=== 'INMOBI'"), 'Only production InMobi events may auto-credit.');
auto_credit_expect(str_contains($auto, 'znews_settle_impression('), 'Auto-credit does not reuse the exact-once settlement service.');
auto_credit_expect(str_contains($auto, 'znews_ad_recheck_impression('), 'Pending impressions are not rechecked after a valid view.');
auto_credit_expect(str_contains($auto, 'ZNEWS_AUTO_SETTLEMENT_SELF_VIEW_REJECTED'), 'Self-view protection is missing from auto-credit.');
auto_credit_expect(str_contains($auto, 'znews_ad_max_per_view()'), 'Per-view processing is not bounded.');

$ingest = auto_credit_read($root, 'api/znews/ads/impressions/ingest.php');
auto_credit_expect(str_contains($ingest, 'znews_auto_settle_impression($impressionId)'), 'Verified provider ingestion does not trigger auto-credit.');
auto_credit_expect(str_contains($ingest, "'retry_required' => empty(\$autoCredit['ok'])"), 'Ingestion does not report an auto-credit retry requirement.');

$complete = auto_credit_read($root, 'api/znews/views/complete.php');
auto_credit_expect(str_contains($complete, "!empty(\$result['valid_view'])"), 'Invalid views can trigger auto-credit.');
auto_credit_expect(str_contains($complete, 'znews_auto_settle_view_impressions($viewId)'), 'Valid view completion does not process pending impressions.');

$settlement = auto_credit_read($root, 'api/znews/lib/settlements_service.php');
auto_credit_expect(str_contains($settlement, 'applied_settlements') || str_contains(auto_credit_read($root, 'api/znews/lib/settlements_balances.php'), 'applied_settlements'), 'Exact-once balance protection is missing.');
auto_credit_expect(str_contains($settlement, 'ZNEWS_SETTLEMENT_SELF_VIEW_NOT_ELIGIBLE'), 'Final settlement self-view guard is missing.');

$transfer = auto_credit_read($root, 'api/znews/lib/transfers_admin_approve.php');
auto_credit_expect(str_contains($transfer, 'znews_transfer_consume_balance('), 'Payment transfer approval flow was removed.');

echo "Z News automatic creator credit tests passed ({$assertions} assertions).\n";
