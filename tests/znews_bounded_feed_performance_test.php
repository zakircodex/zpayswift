<?php
declare(strict_types=1);

$znewsPerfNow = 1800000000;
$znewsPerfData = [];
$znewsPerfSessions = [];
$znewsPerfReads = [];

function perf_expect(bool $condition, string $message): void
{
    static $assertions = 0;
    $assertions++;
    $GLOBALS['znewsPerfAssertions'] = $assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_now(): int
{
    return (int)$GLOBALS['znewsPerfNow'];
}

function znews_view_hmac(string $value): string
{
    return hash_hmac('sha256', $value, 'bounded-feed-test-secret');
}

function znews_view_context(): array
{
    return [
        'fingerprint' => 'fixture-browser',
        'visitor_hash' => 'fixture-visitor',
    ];
}

function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string
{
    $key = trim((string)$value);
    if ($key === '' || strlen($key) > $maxLength || preg_match('/[.#$\[\]\/]/', $key) === 1) {
        throw new RuntimeException('Invalid fixture key: ' . $field);
    }
    return $key;
}

function api_response(bool $success, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . ':' . $status . ':' . $message);
}

function znews_sort_index_rows_desc(array &$rows): void
{
    usort($rows, static function (array $a, array $b): int {
        $time = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        return $time !== 0
            ? $time
            : strcmp((string)($b['post_id'] ?? ''), (string)($a['post_id'] ?? ''));
    });
}

function znews_post_load(string $postId): ?array
{
    $post = fb_get('ZNEWS_POSTS/' . $postId);
    return is_array($post) ? $post : null;
}

function znews_post_is_public(array $post): bool
{
    return strtoupper((string)($post['status'] ?? '')) === 'ACTIVE'
        && strtoupper((string)($post['visibility'] ?? '')) === 'PUBLIC'
        && (int)($post['deleted_at'] ?? 0) === 0;
}

function znews_format_post(array $post): array
{
    return [
        'post_id' => (string)($post['post_id'] ?? ''),
        'creator_uid' => (string)($post['creator_uid'] ?? ''),
        'creator_name' => (string)($post['creator_name'] ?? 'Z-Pay User'),
        'creator_photo_url' => (string)($post['creator_photo_url'] ?? ''),
        'title' => (string)($post['title'] ?? ''),
        'text' => (string)($post['text'] ?? ''),
        'image_url' => (string)($post['image_url'] ?? ''),
        'content_type' => (string)($post['content_type'] ?? 'TEXT'),
        'status' => (string)($post['status'] ?? ''),
        'visibility' => (string)($post['visibility'] ?? ''),
        'like_count' => max(0, (int)($post['like_count'] ?? 0)),
        'comment_count' => max(0, (int)($post['comment_count'] ?? 0)),
        'share_count' => max(0, (int)($post['share_count'] ?? 0)),
        'created_at' => (int)($post['created_at'] ?? 0),
        'updated_at' => (int)($post['updated_at'] ?? 0),
    ];
}

function znews_format_public_post(array $post): array
{
    return znews_format_post($post);
}

function znews_feed_overlay_counts_for_test(array $post, array $counts): array
{
    $post['like_count'] = max(0, (int)($counts['like_count'] ?? 0));
    $post['comment_count'] = max(0, (int)($counts['comment_count'] ?? 0));
    $post['share_count'] = max(0, (int)($counts['share_count'] ?? 0));
    return $post;
}

function fb_get(string $path, array $query = []): mixed
{
    $GLOBALS['znewsPerfReads'][] = ['path' => $path, 'query' => $query];
    $data = $GLOBALS['znewsPerfData'];

    if ($path === 'ZNEWS_PUBLIC_FEED') {
        if ($query === []) {
            throw new RuntimeException('Unbounded public-feed root read detected.');
        }
        perf_expect(($query['orderBy'] ?? '') === json_encode('created_at'), 'Public feed must be ordered by created_at.');
        $limit = (int)($query['limitToLast'] ?? 0);
        perf_expect($limit > 0 && $limit <= 150, 'Candidate query must have a hard limit of at most 150.');
        $endAt = (int)json_decode((string)($query['endAt'] ?? '0'), true);
        $rows = array_filter(
            $data['index'],
            static fn(array $row): bool => (int)$row['created_at'] <= $endAt
        );
        uasort($rows, static function (array $a, array $b): int {
            return ((int)$a['created_at']) <=> ((int)$b['created_at']);
        });
        return array_slice($rows, -$limit, null, true);
    }

    foreach (['ZNEWS_POSTS', 'ZNEWS_ANALYTICS', 'ZNEWS_FEED_EXPOSURE', 'ZNEWS_ENGAGEMENT'] as $root) {
        if ($path === $root) {
            throw new RuntimeException('Unbounded growing-root read detected: ' . $root);
        }
    }

    if (str_starts_with($path, 'ZNEWS_POSTS/')) {
        return $data['posts'][substr($path, strlen('ZNEWS_POSTS/'))] ?? null;
    }
    if (str_starts_with($path, 'ZNEWS_ANALYTICS/')) {
        return $data['analytics'][substr($path, strlen('ZNEWS_ANALYTICS/'))] ?? null;
    }
    if (str_starts_with($path, 'ZNEWS_FEED_EXPOSURE/')) {
        return $data['exposure'][substr($path, strlen('ZNEWS_FEED_EXPOSURE/'))] ?? null;
    }
    if (str_starts_with($path, 'ZNEWS_ENGAGEMENT/')) {
        return $data['engagement'][substr($path, strlen('ZNEWS_ENGAGEMENT/'))] ?? null;
    }
    if (str_starts_with($path, 'ZNEWS_FEED_SESSIONS/')) {
        return $GLOBALS['znewsPerfSessions'][substr($path, strlen('ZNEWS_FEED_SESSIONS/'))] ?? null;
    }

    return null;
}

function fb_patch(string $path, array $data): bool
{
    perf_expect($path === '', 'Feed session must keep the existing root multi-path write.');
    foreach ($data as $target => $value) {
        if (str_starts_with((string)$target, 'ZNEWS_FEED_SESSIONS/')) {
            $sessionId = substr((string)$target, strlen('ZNEWS_FEED_SESSIONS/'));
            $GLOBALS['znewsPerfSessions'][$sessionId] = $value;
        }
    }
    return true;
}

require_once dirname(__DIR__) . '/api/znews/lib/feed_ranking.php';

function perf_fixture(int $size): void
{
    $index = [];
    $posts = [];
    $analytics = [];
    $exposure = [];
    $engagement = [];
    $base = znews_now() - $size - 100;

    for ($i = 1; $i <= $size; $i++) {
        $postId = 'ZNP' . str_pad((string)$i, 12, '0', STR_PAD_LEFT);
        $createdAt = $base + $i;
        $index[$postId] = [
            'post_id' => $postId,
            'creator_uid' => 'CREATOR_' . ($i % 25),
            'creator_name' => 'Creator ' . ($i % 25),
            'creator_photo_url' => 'https://cdn.example.test/avatar-' . ($i % 25) . '.jpg',
            'title' => 'Post ' . $i,
            'text' => 'Fixture post body.',
            'image_url' => $i % 2 === 0 ? 'https://cdn.example.test/post-' . $i . '.jpg' : '',
            'content_type' => $i % 2 === 0 ? 'IMAGE' : 'TEXT',
            'status' => $i === $size ? 'DELETED' : 'ACTIVE',
            'visibility' => 'PUBLIC',
            'created_at' => $createdAt,
            'updated_at' => $createdAt + 1,
            'engagement_snapshot' => [
                'like_count' => $i % 9,
                'comment_count' => $i % 5,
                'share_count' => $i % 3,
                'source_revision' => $i,
                'updated_at' => $createdAt + 1,
            ],
            'ranking_metrics' => [
                'impressions' => $i % 23,
                'unique_impressions' => $i % 11,
                'valid_views' => $i % 17,
                'unique_views' => $i % 13,
                'total_opens' => $i % 19,
                'last_shown_at' => $i % 7 === 0 ? znews_now() - 60 : 0,
                'updated_at' => znews_now() - 30,
            ],
        ];
        $posts[$postId] = [
            'post_id' => $postId,
            'creator_uid' => 'CREATOR_' . ($i % 25),
            'creator_name' => 'Creator ' . ($i % 25),
            'creator_photo_url' => 'https://cdn.example.test/avatar-' . ($i % 25) . '.jpg',
            'title' => 'Post ' . $i,
            'text' => 'Fixture post body.',
            'image_url' => $i % 2 === 0 ? 'https://cdn.example.test/post-' . $i . '.jpg' : '',
            'content_type' => $i % 2 === 0 ? 'IMAGE' : 'TEXT',
            'status' => $i === $size ? 'DELETED' : 'ACTIVE',
            'visibility' => 'PUBLIC',
            'deleted_at' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt + 1,
        ];
        $analytics[$postId] = [
            'valid_views' => $i % 17,
            'unique_viewers' => $i % 13,
            'total_opens' => $i % 19,
        ];
        $exposure[$postId] = [
            'impressions' => $i % 23,
            'unique_viewers' => $i % 11,
            'last_shown_at' => $i % 7 === 0 ? znews_now() - 60 : 0,
        ];
        $engagement[$postId] = [
            'like_count' => $i % 9,
            'comment_count' => $i % 5,
            'share_count' => $i % 3,
        ];
    }

    $GLOBALS['znewsPerfData'] = compact('index', 'posts', 'analytics', 'exposure', 'engagement');
    $GLOBALS['znewsPerfSessions'] = [];
    $GLOBALS['znewsPerfReads'] = [];
}

function perf_legacy_candidates(int $pageSize, string $seed): array
{
    $rows = array_values($GLOBALS['znewsPerfData']['index']);
    znews_sort_index_rows_desc($rows);
    $rows = array_slice($rows, 0, znews_feed_candidate_window($pageSize));
    $candidates = [];
    foreach ($rows as $row) {
        if (strtoupper((string)($row['status'] ?? '')) !== 'ACTIVE'
            || strtoupper((string)($row['visibility'] ?? '')) !== 'PUBLIC') {
            continue;
        }
        $postId = (string)$row['post_id'];
        $analytics = $GLOBALS['znewsPerfData']['analytics'][$postId] ?? [];
        $exposure = $GLOBALS['znewsPerfData']['exposure'][$postId] ?? [];
        $createdAt = (int)$row['created_at'];
        $ageDays = max(0.0, (znews_now() - $createdAt) / 86400);
        $impressions = max(0, (int)($exposure['impressions'] ?? 0));
        $uniqueImpressions = max(0, (int)($exposure['unique_viewers'] ?? 0));
        $validViews = max(0, (int)($analytics['valid_views'] ?? 0));
        $uniqueViews = max(0, (int)($analytics['unique_viewers'] ?? 0));
        $totalOpens = max(0, (int)($analytics['total_opens'] ?? 0));
        $weight = ($uniqueImpressions * 12) + ($impressions * 2)
            + ($uniqueViews * 8) + ($validViews * 3) + $totalOpens;
        $candidates[] = [
            'post_id' => $postId,
            'creator_uid' => (string)$row['creator_uid'],
            'created_at' => $createdAt,
            'fair_score' => $weight / max(1.0, min(30.0, $ageDays + 1.0)),
            'impressions' => $impressions,
            'unique_impressions' => $uniqueImpressions,
            'last_shown_at' => max(0, (int)($exposure['last_shown_at'] ?? 0)),
            'tie' => znews_feed_tie($seed, $postId),
        ];
    }
    return $candidates;
}

function perf_read_count(string $prefix): int
{
    return count(array_filter(
        $GLOBALS['znewsPerfReads'],
        static fn(array $read): bool => str_starts_with((string)$read['path'], $prefix)
    ));
}

$firstPageReadCounts = [];
$nextPageReadCounts = [];

perf_expect(znews_feed_session_candidate_page_size(3) === 12, 'Three-item responses must preserve the established ranking pool size.');
perf_expect(znews_feed_candidate_window(znews_feed_session_candidate_page_size(3)) === 60, 'Progressive Web feed must retain a 60-row candidate window.');
perf_fixture(100);
$progressivePage = znews_fair_feed_page(3);
perf_expect(count($progressivePage['items'] ?? []) === 3, 'Progressive response must contain at most three posts.');
perf_expect((int)($GLOBALS['znewsPerfReads'][0]['query']['limitToLast'] ?? 0) === 60, 'Small response batch must not shrink the candidate query.');

foreach ([100, 1000, 10000] as $fixtureSize) {
    perf_fixture($fixtureSize);
    $first = znews_fair_feed_page(10);

    perf_expect(count($first['items'] ?? []) === 10, "{$fixtureSize}-post first page must contain ten items.");
    perf_expect(!empty($first['has_more']), "{$fixtureSize}-post fixture must expose a next page.");
    perf_expect((string)($first['next_cursor'] ?? '') !== '', 'First page must return a signed cursor.');
    perf_expect(perf_read_count('ZNEWS_PUBLIC_FEED') === 1, 'A new session must use one bounded index query.');
    perf_expect(perf_read_count('ZNEWS_POSTS/') === 0, 'First-page rendering must not load canonical posts per item.');
    perf_expect(perf_read_count('ZNEWS_ANALYTICS/') === 0, 'Ranking must not read candidate analytics rows.');
    perf_expect(perf_read_count('ZNEWS_FEED_EXPOSURE/') === 0, 'Ranking must not read candidate exposure rows.');
    perf_expect(perf_read_count('ZNEWS_ENGAGEMENT/') === 0, 'First-page rendering must not load engagement per item.');
    perf_expect(count($GLOBALS['znewsPerfReads']) === 1, 'First page must use one bounded data read.');

    $firstIds = array_column((array)$first['items'], 'post_id');
    perf_expect(count($firstIds) === count(array_unique($firstIds)), 'First page must not contain duplicate post IDs.');
    $staleDeletedId = 'ZNP' . str_pad((string)$fixtureSize, 12, '0', STR_PAD_LEFT);
    perf_expect(!in_array($staleDeletedId, $firstIds, true), 'A stale index row must not expose a deleted canonical post.');
    foreach ((array)$first['items'] as $item) {
        $postId = (string)($item['post_id'] ?? '');
        $legacy = znews_feed_overlay_counts_for_test(
            znews_format_public_post((array)$GLOBALS['znewsPerfData']['posts'][$postId]),
            (array)$GLOBALS['znewsPerfData']['engagement'][$postId]
        );
        perf_expect($item === $legacy, 'Projection response must match the previous public post and engagement response.');
        perf_expect(!array_key_exists('ranking_metrics', $item), 'Ranking internals must not leak into feed items.');
        perf_expect(!array_key_exists('engagement_snapshot', $item), 'Engagement snapshot internals must not leak into feed items.');
    }
    $firstPageReadCounts[$fixtureSize] = count($GLOBALS['znewsPerfReads']);

    $newPostId = 'ZNP_CONCURRENT_' . $fixtureSize;
    $GLOBALS['znewsPerfData']['index'][$newPostId] = [
        'post_id' => $newPostId,
        'creator_uid' => 'CREATOR_NEW',
        'creator_name' => 'New Creator',
        'creator_photo_url' => '',
        'title' => 'Concurrent post',
        'text' => 'Must not enter an existing signed session.',
        'image_url' => '',
        'content_type' => 'TEXT',
        'status' => 'ACTIVE',
        'visibility' => 'PUBLIC',
        'created_at' => znews_now(),
        'updated_at' => znews_now(),
        'engagement_snapshot' => znews_public_projection_engagement_defaults(),
        'ranking_metrics' => znews_ranking_metrics_defaults(),
    ];
    $GLOBALS['znewsPerfData']['posts'][$newPostId] = [
        'post_id' => $newPostId,
        'creator_uid' => 'CREATOR_NEW',
        'title' => 'Concurrent post',
        'text' => 'Must not enter an existing signed session.',
        'status' => 'ACTIVE',
        'visibility' => 'PUBLIC',
        'deleted_at' => 0,
        'created_at' => znews_now(),
    ];

    $GLOBALS['znewsPerfReads'] = [];
    $second = znews_fair_feed_page(10, (string)$first['next_cursor']);
    $secondIds = array_column((array)$second['items'], 'post_id');
    perf_expect(count($secondIds) === 10, "{$fixtureSize}-post next page must contain ten items.");
    perf_expect(count(array_intersect($firstIds, $secondIds)) === 0, 'Adjacent pages must not repeat post IDs.');
    perf_expect(!in_array($staleDeletedId, $secondIds, true), 'A deleted canonical post must stay excluded on later pages.');
    perf_expect(!in_array($newPostId, $secondIds, true), 'A concurrent new post must not corrupt an existing session cursor.');
    perf_expect(perf_read_count('ZNEWS_PUBLIC_FEED') === 1, 'A next page must refresh one bounded projection window.');
    perf_expect(perf_read_count('ZNEWS_POSTS/') === 0, 'A next page must not load selected canonical posts.');
    perf_expect(perf_read_count('ZNEWS_ENGAGEMENT/') === 0, 'A next page must not load selected engagement rows.');
    perf_expect(perf_read_count('ZNEWS_ANALYTICS/') === 0, 'A next page must not rescan analytics.');
    perf_expect(perf_read_count('ZNEWS_FEED_EXPOSURE/') === 0, 'A next page must not rescan exposure counters.');

    $nextPageReadCounts[$fixtureSize] = count($GLOBALS['znewsPerfReads']);
}

perf_fixture(100);
$equivalenceSeed = 'ZFS' . str_repeat('E', 32);
$legacyOrder = znews_feed_rank_candidates(perf_legacy_candidates(10, $equivalenceSeed), $equivalenceSeed);
$GLOBALS['znewsPerfReads'] = [];
$snapshotOrder = znews_feed_rank_candidates(
    znews_feed_public_candidates(znews_now(), $equivalenceSeed, 10),
    $equivalenceSeed
);
perf_expect($snapshotOrder === $legacyOrder, 'Ranking snapshots must preserve the previous FRESH_FAIR_V1 order.');
perf_expect(perf_read_count('ZNEWS_ANALYTICS/') === 0, 'Equivalence ranking must not read canonical analytics.');
perf_expect(perf_read_count('ZNEWS_FEED_EXPOSURE/') === 0, 'Equivalence ranking must not read canonical exposure.');

$latestId = 'ZNP' . str_pad('99', 12, '0', STR_PAD_LEFT);
unset($GLOBALS['znewsPerfData']['index'][$latestId]['ranking_metrics']);
$GLOBALS['znewsPerfReads'] = [];
$missingSnapshotCandidates = znews_feed_public_candidates(znews_now(), $equivalenceSeed, 10);
$missingSnapshot = array_values(array_filter(
    $missingSnapshotCandidates,
    static fn(array $row): bool => ($row['post_id'] ?? '') === $latestId
));
perf_expect(count($missingSnapshot) === 1, 'A legacy row without ranking_metrics must remain eligible.');
perf_expect((float)$missingSnapshot[0]['fair_score'] === 0.0, 'Missing ranking_metrics must default to zero.');
perf_expect((int)$missingSnapshot[0]['last_shown_at'] === 0, 'Missing last_shown_at must default to zero.');
perf_expect(perf_read_count('ZNEWS_ANALYTICS/') === 0, 'Legacy fallback must not restore analytics N+1 reads.');
perf_expect(perf_read_count('ZNEWS_FEED_EXPOSURE/') === 0, 'Legacy fallback must not restore exposure N+1 reads.');

perf_expect(count(array_unique(array_values($firstPageReadCounts))) === 1, 'First-page database work must not grow with dataset size.');
perf_expect(count(array_unique(array_values($nextPageReadCounts))) === 1, 'Next-page database work must not grow with dataset size.');

$source = file_get_contents(dirname(__DIR__) . '/api/znews/lib/feed_ranking.php');
perf_expect(is_string($source), 'Feed ranking source must be readable.');
foreach (['ZNEWS_PUBLIC_FEED', 'ZNEWS_POSTS', 'ZNEWS_ANALYTICS', 'ZNEWS_FEED_EXPOSURE', 'ZNEWS_ENGAGEMENT'] as $root) {
    perf_expect(!str_contains($source, "fb_get('{$root}')"), "Full {$root} read must not remain in feed ranking.");
}
perf_expect(str_contains($source, "'orderBy' => json_encode('created_at')"), 'The public-feed query must use the created_at index.');
perf_expect(str_contains($source, "'limitToLast' => znews_feed_candidate_window"), 'The candidate query must enforce its bounded window.');
perf_expect(!str_contains($source, "fb_get('ZNEWS_ANALYTICS/' . \$postId)"), 'Per-candidate analytics reads must be removed.');
perf_expect(!str_contains($source, 'fb_get(znews_feed_exposure_path($postId))'), 'Per-candidate exposure reads must be removed.');

$assertions = (int)($GLOBALS['znewsPerfAssertions'] ?? 0);
echo "PASS: {$assertions} bounded Z Sky feed assertions; 100/1000/10000 first-page reads="
    . implode('/', array_values($firstPageReadCounts))
    . '; next-page reads=' . implode('/', array_values($nextPageReadCounts)) . ".\n";
