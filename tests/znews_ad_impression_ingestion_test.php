<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_ad_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_ad_test_read(string $path): string
{
    znews_ad_test_expect(is_file($path), 'missing file: ' . $path);
    $source = file_get_contents($path);
    znews_ad_test_expect($source !== false, 'unable to read: ' . $path);
    return (string)$source;
}

$files = [
    'api/znews/lib/ad_impressions.php',
    'api/znews/lib/ad_impressions_common.php',
    'api/znews/lib/ad_impressions_signature.php',
    'api/znews/lib/ad_impressions_analytics.php',
    'api/znews/lib/ad_impressions_reconcile.php',
    'api/znews/lib/ad_impressions_ingest.php',
    'api/znews/ads/impressions/ingest.php',
    'api/znews/posts/ad_analytics.php',
    'api/admin/znews/ads/impressions/queue.php',
    'api/admin/znews/ads/impressions/details.php',
    'api/admin/znews/ads/impressions/recheck.php',
];

foreach ($files as $relative) {
    $source = znews_ad_test_read($root . '/' . $relative);
    znews_ad_test_expect(
        str_contains($source, 'declare(strict_types=1);'),
        "{$relative} missing strict types"
    );
    znews_ad_test_expect(
        !str_contains($source, 'lib/wallet.php')
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/')
        && !str_contains($source, 'wallet_credit_available')
        && !str_contains($source, 'wallet_debit_available'),
        "{$relative} touches existing wallet business logic"
    );
}

$common = znews_ad_test_read($root . '/api/znews/lib/ad_impressions_common.php');
znews_ad_test_expect(str_contains($common, 'ZNEWS_AD_NETWORK_SECRETS'), 'network secret map missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_AD_MAX_IMPRESSIONS_PER_VIEW'), 'per-view cap config missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_AD_IMPRESSIONS/'), 'impression namespace missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_AD_EVENTS/'), 'event idempotency namespace missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_AD_NONCES/'), 'nonce replay namespace missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_VIEW_AD_SLOTS/'), 'view/ad-unit duplicate guard missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_VIEW_AD_COUNTS/'), 'per-view impression counter missing');
znews_ad_test_expect(str_contains($common, 'ZNEWS_AD_REVIEW_QUEUE/'), 'review queue namespace missing');
znews_ad_test_expect(str_contains($common, 'znews_ad_require_view_and_post'), 'view binding validation missing');
znews_ad_test_expect(str_contains($common, "'VIEW_NOT_COMPLETED'"), 'pending-view classification missing');
znews_ad_test_expect(str_contains($common, "'VIEW_NOT_VALID'"), 'invalid view rejection missing');
znews_ad_test_expect(str_contains($common, "'IMPRESSION_OUTSIDE_VIEW_WINDOW'"), 'event/view time binding missing');
znews_ad_test_expect(str_contains($common, 'fb_get_with_etag') && str_contains($common, 'fb_put_if_match'), 'CAS protection missing');

$signature = znews_ad_test_read($root . '/api/znews/lib/ad_impressions_signature.php');
znews_ad_test_expect(str_contains($signature, 'X-ZNEWS-AD-NETWORK'), 'network signature header missing');
znews_ad_test_expect(str_contains($signature, 'X-ZNEWS-AD-TIMESTAMP'), 'timestamp signature header missing');
znews_ad_test_expect(str_contains($signature, 'X-ZNEWS-AD-NONCE'), 'nonce signature header missing');
znews_ad_test_expect(str_contains($signature, 'X-ZNEWS-AD-SIGNATURE'), 'signature header missing');
znews_ad_test_expect(str_contains($signature, "hash_hmac('sha256'"), 'HMAC-SHA256 verification missing');
znews_ad_test_expect(str_contains($signature, 'hash_equals($expected, $signature)'), 'signature comparison is not timing-safe');
znews_ad_test_expect(str_contains($signature, 'ZNEWS_AD_SIGNATURE_EXPIRED'), 'signed timestamp freshness check missing');
znews_ad_test_expect(str_contains($signature, 'ZNEWS_AD_NONCE_REPLAY'), 'nonce replay rejection missing');
znews_ad_test_expect(str_contains($signature, 'lease_expires_at'), 'event stale-processing lease missing');

$analytics = znews_ad_test_read($root . '/api/znews/lib/ad_impressions_analytics.php');
znews_ad_test_expect(str_contains($analytics, 'event_states'), 'exact-once analytics state ledger missing');
znews_ad_test_expect(str_contains($analytics, 'verified_revenue_micros_by_currency'), 'verified reported revenue analytics missing');
znews_ad_test_expect(str_contains($analytics, 'settled_revenue_micros_by_currency') && str_contains($analytics, "=> []"), 'settled revenue must remain disabled');
znews_ad_test_expect(str_contains($analytics, 'fb_put_if_match'), 'analytics transition lacks CAS protection');

$ingest = znews_ad_test_read($root . '/api/znews/lib/ad_impressions_ingest.php');
znews_ad_test_expect(str_contains($ingest, 'znews_ad_signed_request'), 'ingest bypasses signature validation');
znews_ad_test_expect(str_contains($ingest, 'znews_ad_event_claim'), 'ingest lacks event idempotency');
znews_ad_test_expect(str_contains($ingest, 'znews_ad_nonce_claim'), 'ingest lacks nonce claim');
znews_ad_test_expect(str_contains($ingest, 'znews_ad_claim_slot'), 'ingest lacks duplicate ad-unit guard');
znews_ad_test_expect(str_contains($ingest, 'znews_ad_apply_view_limit'), 'ingest lacks per-view cap');
znews_ad_test_expect(str_contains($ingest, "'settlement_status' => 'NOT_SETTLED'"), 'ingest can mark revenue settled');
znews_ad_test_expect(str_contains($ingest, "'credit_status' => 'NOT_CREDITED'"), 'ingest can mark creator credited');
znews_ad_test_expect(str_contains($ingest, "'earning_eligible' => false"), 'ingest can directly enable earnings');
znews_ad_test_expect(!str_contains($ingest, "'signature' =>") && !str_contains($ingest, "'nonce' =>"), 'raw signature or nonce appears to be stored');
znews_ad_test_expect(str_contains($ingest, 'reconciliation_required'), 'partial-failure reconciliation evidence missing');

$reconcile = znews_ad_test_read($root . '/api/znews/lib/ad_impressions_reconcile.php');
znews_ad_test_expect(str_contains($reconcile, 'znews_ad_recheck_impression'), 'impression recheck service missing');
znews_ad_test_expect(str_contains($reconcile, 'expectedUpdatedAt'), 'recheck version protection missing');
znews_ad_test_expect(str_contains($reconcile, 'ZNEWS_AD_ADMIN_IDEMPOTENCY/'), 'admin recheck idempotency namespace missing');
znews_ad_test_expect(str_contains($reconcile, 'fb_put_if_match'), 'recheck lacks optimistic concurrency');
znews_ad_test_expect(str_contains($reconcile, "'earning_eligible'] = false"), 'recheck can enable earnings');
znews_ad_test_expect(str_contains($reconcile, "'settlement_status'] = 'NOT_SETTLED'"), 'recheck can settle revenue');
znews_ad_test_expect(str_contains($reconcile, "'credit_status'] = 'NOT_CREDITED'"), 'recheck can credit creator');
znews_ad_test_expect(str_contains($ingest, 'znews_ad_recheck_impression'), 'webhook replay does not attempt safe reconciliation');

$endpoint = znews_ad_test_read($root . '/api/znews/ads/impressions/ingest.php');
znews_ad_test_expect(str_contains($endpoint, "api_require_method('POST')"), 'ingest endpoint is not POST-only');
znews_ad_test_expect(!str_contains($endpoint, 'api_require_app_key();'), 'server webhook incorrectly requires app key');
znews_ad_test_expect(str_contains($endpoint, 'znews_ad_ingest'), 'ingest endpoint bypasses service layer');

$creator = znews_ad_test_read($root . '/api/znews/posts/ad_analytics.php');
znews_ad_test_expect(str_contains($creator, 'api_require_app_key();') && str_contains($creator, 'znews_require_creator(true)'), 'creator ad analytics lacks authentication');
znews_ad_test_expect(str_contains($creator, 'znews_post_owner_snapshot'), 'creator ad analytics lacks ownership check');
znews_ad_test_expect(str_contains($creator, "'settlement_enabled' => false") && str_contains($creator, "'credit_enabled' => false"), 'creator response incorrectly enables settlement');

foreach (['queue.php', 'details.php'] as $name) {
    $source = znews_ad_test_read($root . '/api/admin/znews/ads/impressions/' . $name);
    znews_ad_test_expect(str_contains($source, 'auth_require_admin_session(true)'), "{$name} lacks admin session protection");
    znews_ad_test_expect(str_contains($source, "api_require_method('GET')"), "{$name} is not GET-only");
}

$recheckEndpoint = znews_ad_test_read($root . '/api/admin/znews/ads/impressions/recheck.php');
znews_ad_test_expect(str_contains($recheckEndpoint, "api_require_method('POST')"), 'admin recheck is not POST-only');
znews_ad_test_expect(str_contains($recheckEndpoint, 'auth_require_admin_session(true)'), 'admin recheck lacks admin session');
znews_ad_test_expect(str_contains($recheckEndpoint, 'expected_updated_at') && str_contains($recheckEndpoint, 'znews_idempotency_key'), 'admin recheck lacks version or idempotency protection');

$details = znews_ad_test_read($root . '/api/znews/lib/ad_impressions_common.php');
znews_ad_test_expect(str_contains($details, "unset(\$row['payload_hash'], \$row['nonce_hash'], \$row['provider_event_hash'])"), 'admin details expose internal verification hashes');

echo "Z News ad impression ingestion tests passed ({$assertions} assertions).\n";
