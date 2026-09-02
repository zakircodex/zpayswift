<?php
declare(strict_types=1);

$rankingSnapshotNow = 1800000000;
$rankingSnapshotMetrics = [];
$rankingSnapshotWrites = [];
$rankingSnapshotCanonical = [];
$rankingSnapshotFailMirror = false;

function ranking_snapshot_expect(bool $condition, string $message): void
{
    static $assertions = 0;
    $assertions++;
    $GLOBALS['rankingSnapshotAssertions'] = $assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_now(): int
{
    return (int)$GLOBALS['rankingSnapshotNow'];
}

function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string
{
    $key = trim((string)$value);
    if ($key === '' || strlen($key) > $maxLength || preg_match('/[.#$\[\]\/]/', $key) === 1) {
        throw new RuntimeException('Invalid fixture key: ' . $field);
    }
    return $key;
}

function znews_path_public_feed(string $postId): string
{
    return 'ZNEWS_PUBLIC_FEED/' . znews_firebase_key($postId, 'post_id');
}

function api_response(bool $success, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . ':' . $status . ':' . $message);
}

function fb_get(string $path, array $query = []): mixed
{
    if (str_starts_with($path, 'ZNEWS_PUBLIC_FEED/')) {
        return [
            'status' => 'ACTIVE',
            'visibility' => 'PUBLIC',
        ];
    }
    return null;
}

function fb_get_with_etag(string $path): array
{
    if (str_starts_with($path, 'ZNEWS_ANALYTICS/')) {
        return ['ok' => true, 'etag' => 'analytics-etag', 'value' => $GLOBALS['rankingSnapshotCanonical'] ?: null];
    }
    return ['ok' => true, 'etag' => 'metrics-etag', 'value' => $GLOBALS['rankingSnapshotMetrics'] ?: null];
}

function fb_put_if_match(string $path, $data, string $etag): array
{
    $GLOBALS['rankingSnapshotWrites'][] = ['path' => $path, 'data' => $data, 'etag' => $etag];
    if (str_starts_with($path, 'ZNEWS_ANALYTICS/')) {
        $GLOBALS['rankingSnapshotCanonical'] = $data;
        return ['ok' => true, 'status' => 200];
    }
    if (!empty($GLOBALS['rankingSnapshotFailMirror'])) {
        return ['ok' => false, 'status' => 503];
    }
    $GLOBALS['rankingSnapshotMetrics'] = $data;
    return ['ok' => true, 'status' => 200];
}

require_once dirname(__DIR__) . '/api/znews/lib/views.php';
require_once dirname(__DIR__) . '/api/znews/lib/instant_publish.php';

$defaults = znews_ranking_metrics_from_index_row([]);
ranking_snapshot_expect($defaults === znews_ranking_metrics_defaults(), 'Legacy index rows must receive zero ranking metrics.');

$GLOBALS['rankingSnapshotMetrics'] = [
    'impressions' => 9,
    'unique_impressions' => 5,
    'valid_views' => 4,
    'unique_views' => 3,
    'total_opens' => 7,
    'last_shown_at' => znews_now() - 10,
    'updated_at' => znews_now() - 10,
];
ranking_snapshot_expect(znews_ranking_metrics_mirror_analytics('POST_A', [
    'valid_views' => 2,
    'unique_viewers' => 2,
    'total_opens' => 8,
    'updated_at' => znews_now(),
]), 'Analytics snapshot mirror must succeed.');
ranking_snapshot_expect((int)$GLOBALS['rankingSnapshotMetrics']['valid_views'] === 4, 'Snapshot counters must not regress during concurrent mirrors.');
ranking_snapshot_expect((int)$GLOBALS['rankingSnapshotMetrics']['total_opens'] === 8, 'Snapshot must accept newer canonical counters.');

$GLOBALS['rankingSnapshotCanonical'] = [];
$GLOBALS['rankingSnapshotFailMirror'] = true;
$analyticsResult = znews_view_analytics_apply('POST_A', ['total_opens' => 1]);
ranking_snapshot_expect(!empty($analyticsResult['ok']), 'Canonical analytics must succeed when the ranking cache mirror fails.');
ranking_snapshot_expect((int)($analyticsResult['analytics']['total_opens'] ?? 0) === 1, 'Canonical analytics result must remain authoritative.');
$GLOBALS['rankingSnapshotFailMirror'] = false;

$activeIndex = znews_public_feed_index_for_post([
    'post_id' => 'POST_A',
    'creator_uid' => 'USER_A',
    'status' => 'ACTIVE',
    'visibility' => 'PUBLIC',
    'created_at' => znews_now() - 100,
    'updated_at' => znews_now(),
    'published_at' => znews_now(),
]);
ranking_snapshot_expect(is_array($activeIndex), 'An active public post must produce a feed index row.');
ranking_snapshot_expect(($activeIndex['ranking_metrics'] ?? null) === znews_ranking_metrics_defaults(), 'New public posts must initialize zero ranking metrics.');

$preservedIndex = znews_public_feed_index_for_post([
    'post_id' => 'POST_A',
    'creator_uid' => 'USER_A',
    'status' => 'ACTIVE',
    'visibility' => 'PUBLIC',
    'created_at' => znews_now() - 100,
    'updated_at' => znews_now() + 1,
    'published_at' => znews_now(),
], ['ranking_metrics' => $GLOBALS['rankingSnapshotMetrics']]);
ranking_snapshot_expect(
    ($preservedIndex['ranking_metrics'] ?? null) === $GLOBALS['rankingSnapshotMetrics'],
    'Republishing must preserve existing ranking metrics.'
);

$updatePaths = znews_public_feed_index_updates_for_post([
    'post_id' => 'POST_A',
    'creator_uid' => 'USER_A',
    'status' => 'ACTIVE',
    'visibility' => 'PUBLIC',
    'created_at' => znews_now() - 100,
    'updated_at' => znews_now() + 1,
    'published_at' => znews_now(),
]);
ranking_snapshot_expect(!array_key_exists('ZNEWS_PUBLIC_FEED/POST_A', $updatePaths), 'Post edits must not replace the whole public-feed row.');
ranking_snapshot_expect(count(array_filter(
    array_keys($updatePaths),
    static fn(string $path): bool => str_contains($path, '/ranking_metrics')
)) === 0, 'Post edits must preserve ranking_metrics.');

$publicPostsSource = file_get_contents(dirname(__DIR__) . '/api/znews/lib/posts.php');
$moderationSource = file_get_contents(dirname(__DIR__) . '/api/znews/lib/moderation.php');
$viewsV2Source = file_get_contents(dirname(__DIR__) . '/api/znews/lib/views_v2.php');
ranking_snapshot_expect(is_string($publicPostsSource) && !str_contains($publicPostsSource, 'ranking_metrics'), 'Public post serializers must not expose ranking metrics.');
ranking_snapshot_expect(is_string($moderationSource) && str_contains($moderationSource, 'znews_public_feed_index_for_post('), 'Admin publication must initialize or preserve ranking metrics.');
ranking_snapshot_expect(is_string($viewsV2Source) && substr_count($viewsV2Source, 'znews_ranking_metrics_mirror_analytics') >= 2, 'Idempotent analytics paths must keep the ranking cache synchronized.');

$assertions = (int)($GLOBALS['rankingSnapshotAssertions'] ?? 0);
echo "PASS: {$assertions} Z Sky ranking snapshot assertions.\n";
