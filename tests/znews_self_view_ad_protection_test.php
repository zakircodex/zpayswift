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
self_view_expect(str_contains($start, 'auth_get_session_token_from_request()'), 'View start does not detect an authenticated creator.');
self_view_expect(str_contains($start, 'znews_require_creator(false)'), 'View start does not validate the optional creator session.');
self_view_expect(str_contains($start, 'znews_view_start_v2($postId, $idempotencyKey, $viewerUid)'), 'Validated viewer UID is not bound to the view session.');

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
self_view_expect(str_contains($api, 'authenticated: this.isAuthenticated()'), 'Authenticated reader does not bind its session to view start.');
self_view_expect(str_contains($ads, 'creatorUid === viewerUid'), 'Reader ad renderer does not suppress the creator own-post slot.');
self_view_expect(str_contains($reader, 'creatorUid: text(post.creator_uid).trim()'), 'Post reader does not provide ownership context to the ad renderer.');

echo "Z News self-view ad protection tests passed ({$assertions} assertions).\n";
