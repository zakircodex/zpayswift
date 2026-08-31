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
        'title' => (string)($post['title'] ?? ''),
        'text' => (string)($post['text'] ?? ''),
        'status' => (string)($post['status'] ?? ''),
        'visibility' => (string)($post['visibility'] ?? ''),
        'created_at' => (int)($post['created_at'] ?? 0),
    ];
}

function znews_format_public_post(array $post): array
{
    return znews_format_post($post);
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
            'status' => 'ACTIVE',
            'visibility' => 'PUBLIC',
            'created_at' => $createdAt,
        ];
        $posts[$postId] = [
            'post_id' => $postId,
            'creator_uid' => 'CREATOR_' . ($i % 25),
            'title' => 'Post ' . $i,
            'text' => 'Fixture post body.',
            'status' => $i === $size ? 'DELETED' : 'ACTIVE',
            'visibility' => 'PUBLIC',
            'deleted_at' => 0,
            'created_at' => $createdAt,
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

function perf_read_count(string $prefix): int
{
    return count(array_filter(
        $GLOBALS['znewsPerfReads'],
        static fn(array $read): bool => str_starts_with((string)$read['path'], $prefix)
    ));
}

$firstPageReadCounts = [];
$nextPageReadCounts = [];
foreach ([100, 1000, 10000] as $fixtureSize) {
    perf_fixture($fixtureSize);
    $first = znews_fair_feed_page(10);

    perf_expect(count($first['items'] ?? []) === 10, "{$fixtureSize}-post first page must contain ten items.");
    perf_expect(!empty($first['has_more']), "{$fixtureSize}-post fixture must expose a next page.");
    perf_expect((string)($first['next_cursor'] ?? '') !== '', 'First page must return a signed cursor.');
    perf_expect(perf_read_count('ZNEWS_PUBLIC_FEED') === 1, 'A new session must use one bounded index query.');
    perf_expect(perf_read_count('ZNEWS_POSTS/') <= 50, 'Canonical post reads must remain within the bounded session window.');
    perf_expect(perf_read_count('ZNEWS_ANALYTICS/') <= 50, 'Analytics reads must remain candidate-specific and bounded.');
    perf_expect(perf_read_count('ZNEWS_FEED_EXPOSURE/') <= 50, 'Exposure reads must remain candidate-specific and bounded.');
    perf_expect(perf_read_count('ZNEWS_ENGAGEMENT/') === 10, 'Only returned posts may load engagement counters.');

    $firstIds = array_column((array)$first['items'], 'post_id');
    perf_expect(count($firstIds) === count(array_unique($firstIds)), 'First page must not contain duplicate post IDs.');
    $staleDeletedId = 'ZNP' . str_pad((string)$fixtureSize, 12, '0', STR_PAD_LEFT);
    perf_expect(!in_array($staleDeletedId, $firstIds, true), 'A stale index row must not expose a deleted canonical post.');
    $firstPageReadCounts[$fixtureSize] = count($GLOBALS['znewsPerfReads']);

    $newPostId = 'ZNP_CONCURRENT_' . $fixtureSize;
    $GLOBALS['znewsPerfData']['index'][$newPostId] = [
        'post_id' => $newPostId,
        'creator_uid' => 'CREATOR_NEW',
        'status' => 'ACTIVE',
        'visibility' => 'PUBLIC',
        'created_at' => znews_now(),
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
    perf_expect(perf_read_count('ZNEWS_PUBLIC_FEED') === 0, 'A next page must reuse the bounded server-side session.');
    perf_expect(perf_read_count('ZNEWS_POSTS/') === 10, 'A next page may read only its selected posts.');
    perf_expect(perf_read_count('ZNEWS_ENGAGEMENT/') === 10, 'A next page may read only its selected engagement rows.');
    perf_expect(perf_read_count('ZNEWS_ANALYTICS/') === 0, 'A next page must not rescan analytics.');
    perf_expect(perf_read_count('ZNEWS_FEED_EXPOSURE/') === 0, 'A next page must not rescan exposure counters.');

    $nextPageReadCounts[$fixtureSize] = count($GLOBALS['znewsPerfReads']);
}

perf_expect(count(array_unique(array_values($firstPageReadCounts))) === 1, 'First-page database work must not grow with dataset size.');
perf_expect(count(array_unique(array_values($nextPageReadCounts))) === 1, 'Next-page database work must not grow with dataset size.');

$source = file_get_contents(dirname(__DIR__) . '/api/znews/lib/feed_ranking.php');
perf_expect(is_string($source), 'Feed ranking source must be readable.');
foreach (['ZNEWS_PUBLIC_FEED', 'ZNEWS_POSTS', 'ZNEWS_ANALYTICS', 'ZNEWS_FEED_EXPOSURE', 'ZNEWS_ENGAGEMENT'] as $root) {
    perf_expect(!str_contains($source, "fb_get('{$root}')"), "Full {$root} read must not remain in feed ranking.");
}
perf_expect(str_contains($source, "'orderBy' => json_encode('created_at')"), 'The public-feed query must use the created_at index.');
perf_expect(str_contains($source, "'limitToLast' => znews_feed_candidate_window"), 'The candidate query must enforce its bounded window.');

$assertions = (int)($GLOBALS['znewsPerfAssertions'] ?? 0);
echo "PASS: {$assertions} bounded Z Sky feed assertions; 100/1000/10000 first-page reads="
    . implode('/', array_values($firstPageReadCounts))
    . '; next-page reads=' . implode('/', array_values($nextPageReadCounts)) . ".\n";
