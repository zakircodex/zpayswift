<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function self_view_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function self_view_read(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    self_view_expect(is_string($source), "Unable to read {$path}");
    return $source;
}

$start = self_view_read($root, 'api/znews/views/start.php');
self_view_expect(str_contains($start, 'znews_optional_creator_uid()'), 'View start does not resolve an optional authenticated creator.');
self_view_expect(!str_contains($start, 'znews_require_creator('), 'Public view start incorrectly requires creator login.');
self_view_expect(str_contains($start, 'znews_view_start_v2($postId, $idempotencyKey, $viewerUid)'), 'Validated viewer UID is not bound to the view session.');

$common = self_view_read($root, 'api/znews/lib/common.php');
self_view_expect(str_contains($common, 'function znews_optional_creator_uid()'), 'Optional creator resolver is missing.');
self_view_expect(str_contains($common, 'auth_get_session_token_from_request()'), 'Optional creator resolver does not inspect the signed session header.');
self_view_expect(str_contains($common, 'auth_require_user(false)'), 'Optional creator resolver does not validate the supplied session.');

$views = self_view_read($root, 'api/znews/lib/views_v2.php');
self_view_expect(str_contains($views, "'viewer_uid' => \$viewerUid"), 'View session does not persist the server-validated viewer UID.');
self_view_expect(str_contains($views, "'self_view' => \$selfView"), 'View session does not persist self-view classification.');

$publicView = self_view_read($root, 'api/znews/lib/views.php');
self_view_expect(str_contains($publicView, "'self_view' => !empty(\$row['self_view'])"), 'Public view result does not identify a self-view.');
self_view_expect(str_contains($publicView, "empty(\$row['self_view']) && empty(\$row['duplicate'])"), 'Self-views can still become eligible candidates.');

$adCommon = self_view_read($root, 'api/znews/lib/ad_impressions_common.php');
self_view_expect(str_contains($adCommon, "\$reasons[] = 'SELF_VIEW'"), 'Ad verification does not reject self-views.');
self_view_expect(str_contains($adCommon, "'status' => 'REJECTED'"), 'Self-view ad verification lacks a rejected result.');

$ingest = self_view_read($root, 'api/znews/lib/ad_impressions_ingest.php');
$recheck = self_view_read($root, 'api/znews/lib/ad_impressions_reconcile.php');
self_view_expect(str_contains($ingest, "'self_view' => !empty(\$evaluation['self_view'])"), 'Ad ingestion does not preserve self-view evidence.');
self_view_expect(str_contains($recheck, "\$row['self_view'] = !empty(\$evaluation['self_view'])"), 'Ad recheck can lose self-view evidence.');

$settlement = self_view_read($root, 'api/znews/lib/settlements_service.php');
self_view_expect(str_contains($settlement, 'ZNEWS_SETTLEMENT_SELF_VIEW_NOT_ELIGIBLE'), 'Settlement does not explicitly reject self-view credit.');
self_view_expect(str_contains($settlement, "in_array('SELF_VIEW', \$riskReasons, true)"), 'Settlement does not honor persisted self-view risk evidence.');

$api = self_view_read($root, 'znews/assets/znews-api.js');
$ads = self_view_read($root, 'znews/assets/znews-ads.js');
$reader = self_view_read($root, 'znews/assets/znews.js');
$viewPolicy = self_view_read($root, 'api/znews/lib/creator_view_policy.php');
self_view_expect(str_contains($api, 'authenticated: this.isAuthenticated()'), 'Authenticated reader does not bind its session to view start.');
self_view_expect(str_contains($viewPolicy, "'viewer_class' => 'CREATOR'") && str_contains($viewPolicy, "'ad_eligible' => false"), 'Server policy does not suppress all authenticated creator ads.');
self_view_expect(str_contains($ads, 'authenticatedCreator()') && str_contains($ads, 'window.ZNEWS_AUTH_VERIFIED === true'), 'Reader ad renderer does not suppress verified creators.');
self_view_expect(str_contains($reader, 'await Promise.resolve(window.ZNEWS_AUTH_READY)') && str_contains($reader, 'hasVerifiedSession()'), 'Post reader can mount an ad before creator verification finishes.');

echo "Z News self-view ad protection tests passed ({$assertions} assertions).\n";
