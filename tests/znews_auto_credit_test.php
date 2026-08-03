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
auto_credit_expect(str_contains($auto, 'ZNEWS_AUTO_SETTLEMENT_RETRIES/'), 'Persistent automatic-settlement retry queue is missing.');
auto_credit_expect(str_contains($auto, 'znews_auto_settle_impression_with_retry'), 'Automatic settlement failures are not queued for retry.');

$ingest = auto_credit_read($root, 'api/znews/ads/impressions/ingest.php');
auto_credit_expect(str_contains($ingest, 'znews_auto_settle_impression_with_retry($impressionId)'), 'Verified provider ingestion does not trigger durable auto-credit.');
auto_credit_expect(str_contains($ingest, "'retry_required' => empty(\$autoCredit['ok'])"), 'Ingestion does not report an auto-credit retry requirement.');
auto_credit_expect(str_contains($ingest, "'retry_queued' => !empty(\$autoCredit['retry_queued'])"), 'Ingestion does not report retry persistence.');

$complete = auto_credit_read($root, 'api/znews/views/complete.php');
auto_credit_expect(str_contains($complete, "!empty(\$result['valid_view'])"), 'Invalid views can trigger auto-credit.');
auto_credit_expect(str_contains($complete, 'znews_auto_settle_view_impressions($viewId)'), 'Valid view completion does not process pending impressions.');

$settlement = auto_credit_read($root, 'api/znews/lib/settlements_service.php');
auto_credit_expect(str_contains($settlement, 'applied_settlements') || str_contains(auto_credit_read($root, 'api/znews/lib/settlements_balances.php'), 'applied_settlements'), 'Exact-once balance protection is missing.');
auto_credit_expect(str_contains($settlement, 'ZNEWS_SETTLEMENT_SELF_VIEW_NOT_ELIGIBLE'), 'Final settlement self-view guard is missing.');

$transfer = auto_credit_read($root, 'api/znews/lib/transfers_admin_approve.php');
auto_credit_expect(str_contains($transfer, 'znews_transfer_consume_balance('), 'Payment transfer approval flow was removed.');

$retryWorker = auto_credit_read($root, 'tools/zsky24_auto_settlement_retry.php');
auto_credit_expect(str_contains($retryWorker, "PHP_SAPI !== 'cli'"), 'Retry worker is web-accessible.');
auto_credit_expect(str_contains($retryWorker, 'znews_auto_settle_impression_with_retry'), 'Retry worker does not use the exact automatic settlement path.');

echo "Z News automatic creator credit tests passed ({$assertions} assertions).\n";
