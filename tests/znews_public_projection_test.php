<?php
declare(strict_types=1);

$projectionStore = [];
$projectionPatchCalls = [];
$projectionFailMirror = false;
$projectionNow = 1800000000;

function projection_expect(bool $condition, string $message): void
{
    static $assertions = 0;
    $assertions++;
    $GLOBALS['projectionAssertions'] = $assertions;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function projection_store_get(string $path): mixed
{
    $node = $GLOBALS['projectionStore'];
    foreach (array_values(array_filter(explode('/', trim($path, '/')), 'strlen')) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function projection_store_set(string $path, mixed $value): void
{
    $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    $node =& $GLOBALS['projectionStore'];
    foreach ($parts as $index => $part) {
        if ($index === count($parts) - 1) {
            if ($value === null) {
                unset($node[$part]);
            } else {
                $node[$part] = $value;
            }
            return;
        }
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
}

function znews_now(): int
{
    return (int)$GLOBALS['projectionNow'];
}

function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string
{
    $key = trim((string)$value);
    if ($key === '' || strlen($key) > $maxLength || preg_match('/[.#$\[\]\/]/', $key) === 1) {
        throw new RuntimeException('Invalid fixture key: ' . $field);
    }
    return $key;
}

function znews_normalize_status($value, string $default = 'REVIEW'): string
{
    $status = strtoupper(trim((string)$value));
    return $status !== '' ? $status : $default;
}

function znews_path_post(string $postId): string
{
    return 'ZNEWS_POSTS/' . znews_firebase_key($postId, 'post_id');
}

function znews_path_public_feed(string $postId): string
{
    return 'ZNEWS_PUBLIC_FEED/' . znews_firebase_key($postId, 'post_id');
}

function znews_post_is_public(array $post): bool
{
    return strtoupper((string)($post['status'] ?? '')) === 'ACTIVE'
        && strtoupper((string)($post['visibility'] ?? '')) === 'PUBLIC'
        && (int)($post['deleted_at'] ?? 0) === 0;
}

function znews_format_public_post(array $post): array
{
    return [
        'post_id' => trim((string)($post['post_id'] ?? '')),
        'creator_uid' => trim((string)($post['creator_uid'] ?? '')),
        'creator_name' => trim((string)($post['creator_name'] ?? 'Z-Pay User')),
        'creator_photo_url' => trim((string)($post['creator_photo_url'] ?? '')),
        'title' => trim((string)($post['title'] ?? '')),
        'text' => (string)($post['text'] ?? ''),
        'image_url' => trim((string)($post['image_url'] ?? '')),
        'content_type' => strtoupper(trim((string)($post['content_type'] ?? 'TEXT'))),
        'visibility' => 'PUBLIC',
        'status' => znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW'),
        'like_count' => max(0, (int)($post['like_count'] ?? 0)),
        'comment_count' => max(0, (int)($post['comment_count'] ?? 0)),
        'share_count' => max(0, (int)($post['share_count'] ?? 0)),
        'created_at' => (int)($post['created_at'] ?? 0),
        'updated_at' => (int)($post['updated_at'] ?? 0),
    ];
}

function api_response(bool $success, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . ':' . $status . ':' . $message);
}

function fb_get(string $path, array $query = []): mixed
{
    if ($path === 'ZNEWS_PUBLIC_FEED' && $query !== []) {
        projection_expect(($query['orderBy'] ?? '') === json_encode('$key'), 'Backfill must use a bounded key query.');
        $rows = is_array($GLOBALS['projectionStore']['ZNEWS_PUBLIC_FEED'] ?? null)
            ? (array)$GLOBALS['projectionStore']['ZNEWS_PUBLIC_FEED']
            : [];
        ksort($rows, SORT_STRING);
        $startAt = isset($query['startAt']) ? (string)json_decode((string)$query['startAt'], true) : '';
        if ($startAt !== '') {
            $rows = array_filter($rows, static fn($_row, string $key): bool => strcmp($key, $startAt) >= 0, ARRAY_FILTER_USE_BOTH);
        }
        return array_slice($rows, 0, (int)($query['limitToFirst'] ?? 0), true);
    }
    return projection_store_get($path);
}

function fb_get_with_etag(string $path): array
{
    return ['ok' => true, 'status' => 200, 'etag' => 'etag-' . md5($path), 'value' => projection_store_get($path)];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    if (!empty($GLOBALS['projectionFailMirror']) && str_contains($path, '/engagement_snapshot')) {
        return ['ok' => false, 'status' => 503];
    }
    projection_store_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function fb_patch(string $path, array $data): bool
{
    $GLOBALS['projectionPatchCalls'][] = ['path' => $path, 'data' => $data];
    if ($path === '') {
        foreach ($data as $target => $value) {
            projection_store_set((string)$target, $value);
        }
        return true;
    }
    foreach ($data as $field => $value) {
        projection_store_set(trim($path, '/') . '/' . (string)$field, $value);
    }
    return true;
}

require_once dirname(__DIR__) . '/api/znews/lib/engagement.php';
require_once dirname(__DIR__) . '/api/znews/lib/instant_publish.php';
require_once dirname(__DIR__) . '/api/znews/lib/public_projection_backfill.php';

$firstQuery = znews_public_projection_backfill_query(2);
$resumeQuery = znews_public_projection_backfill_query(2, 'POST_A');
projection_expect((int)$firstQuery['limitToFirst'] === 3, 'First backfill page must fetch one lookahead row.');
projection_expect((int)$resumeQuery['limitToFirst'] === 4 && isset($resumeQuery['startAt']), 'Resumed backfill must account for the inclusive cursor and one lookahead row.');

function projection_post(string $postId, string $status = 'ACTIVE'): array
{
    return [
        'post_id' => $postId,
        'creator_uid' => 'USER_A',
        'creator_name' => 'Creator A',
        'creator_photo_url' => 'https://cdn.example.test/avatar.jpg',
        'title' => 'Projection title',
        'text' => 'Projection body',
        'image_url' => 'https://cdn.example.test/post.jpg',
        'content_type' => 'IMAGE',
        'status' => $status,
        'visibility' => 'PUBLIC',
        'created_at' => znews_now() - 100,
        'updated_at' => znews_now(),
        'published_at' => znews_now() - 90,
        'deleted_at' => $status === 'DELETED' ? znews_now() : 0,
        'like_count' => 0,
        'comment_count' => 0,
        'share_count' => 0,
        'email' => 'private@example.test',
        'wallet' => ['balance' => 999],
        'moderation_note' => 'private',
    ];
}

$post = projection_post('POST_A');
$projection = znews_public_projection_for_post($post);
projection_expect(is_array($projection) && znews_public_projection_is_complete($projection), 'Active post must produce a complete feed projection.');
foreach (['email', 'wallet', 'moderation_note', 'image_media_id'] as $privateField) {
    projection_expect(!array_key_exists($privateField, $projection), "Projection leaked {$privateField}.");
}
$item = znews_public_projection_item((array)$projection);
projection_expect(is_array($item) && ($item['title'] ?? '') === 'Projection title', 'Projection must render the canonical public title.');
projection_expect(!array_key_exists('ranking_metrics', $item) && !array_key_exists('engagement_snapshot', $item), 'Internal projection metadata leaked into the API item.');

$existing = (array)$projection;
$existing['ranking_metrics']['impressions'] = 8;
$existing['engagement_snapshot'] = [
    'like_count' => 7,
    'comment_count' => 6,
    'share_count' => 5,
    'source_revision' => 4,
    'updated_at' => znews_now(),
];
$edited = $post;
$edited['title'] = 'Edited title';
$edited['updated_at']++;
$refreshed = znews_public_projection_for_post($edited, $existing);
projection_expect(($refreshed['title'] ?? '') === 'Edited title', 'Post edit must refresh public fields.');
projection_expect(($refreshed['ranking_metrics']['impressions'] ?? 0) === 8, 'Post edit must preserve ranking metrics.');
projection_expect(($refreshed['engagement_snapshot']['like_count'] ?? 0) === 7, 'Post edit must preserve engagement snapshot.');
$leafUpdates = znews_public_projection_updates_for_post($edited);
projection_expect(!array_key_exists('ZNEWS_PUBLIC_FEED/POST_A', $leafUpdates), 'Post edit must not replace the whole projection row.');
projection_expect(count(array_filter(array_keys($leafUpdates), static fn(string $path): bool => str_contains($path, 'ranking_metrics') || str_contains($path, 'engagement_snapshot'))) === 0, 'Post edit leaf patch must not erase internal snapshots.');
projection_expect(znews_public_projection_for_post(projection_post('POST_A', 'DELETED')) === null, 'Deleted post must not produce a public projection.');
projection_expect(znews_public_projection_updates_for_post(projection_post('POST_A', 'DELETED')) === ['ZNEWS_PUBLIC_FEED/POST_A' => null], 'Deleted post must remove its feed projection.');

projection_store_set('ZNEWS_PUBLIC_FEED/POST_A', $projection);
projection_store_set('ZNEWS_ENGAGEMENT/POST_A', [
    'post_id' => 'POST_A',
    'like_count' => 0,
    'comment_count' => 0,
    'share_count' => 0,
    'revision' => 0,
    'updated_at' => znews_now(),
]);
$like = znews_engagement_adjust_counter('POST_A', 'like_count', 1);
projection_expect(!empty($like['ok']) && (int)($like['counts']['like_count'] ?? 0) === 1, 'Canonical Like must succeed.');
projection_expect((int)(projection_store_get('ZNEWS_PUBLIC_FEED/POST_A/engagement_snapshot')['like_count'] ?? 0) === 1, 'Like count was not mirrored into the projection.');
$unlike = znews_engagement_adjust_counter('POST_A', 'like_count', -1);
projection_expect(!empty($unlike['ok']) && (int)(projection_store_get('ZNEWS_PUBLIC_FEED/POST_A/engagement_snapshot')['like_count'] ?? -1) === 0, 'Unlike must mirror the canonical decremented count.');
znews_engagement_set_counter_exact('POST_A', 'comment_count', 4);
znews_engagement_set_counter_exact('POST_A', 'share_count', 3);
$mirrored = (array)projection_store_get('ZNEWS_PUBLIC_FEED/POST_A/engagement_snapshot');
projection_expect((int)$mirrored['comment_count'] === 4 && (int)$mirrored['share_count'] === 3, 'Comment/share mirrors must use canonical counts.');

$newer = $mirrored;
$newer['like_count'] = 9;
$newer['source_revision'] = 99;
projection_store_set('ZNEWS_PUBLIC_FEED/POST_A/engagement_snapshot', $newer);
projection_expect(znews_public_projection_mirror_engagement('POST_A', [
    'like_count' => 1,
    'comment_count' => 1,
    'share_count' => 1,
    'revision' => 2,
    'updated_at' => znews_now(),
]), 'Stale mirror should be safely ignored.');
projection_expect((int)(projection_store_get('ZNEWS_PUBLIC_FEED/POST_A/engagement_snapshot')['like_count'] ?? 0) === 9, 'Older mirror overwrote a newer projection revision.');

$GLOBALS['projectionFailMirror'] = true;
$canonicalBefore = (int)(projection_store_get('ZNEWS_ENGAGEMENT/POST_A')['comment_count'] ?? 0);
$canonicalResult = znews_engagement_adjust_counter('POST_A', 'comment_count', 1);
$GLOBALS['projectionFailMirror'] = false;
projection_expect(!empty($canonicalResult['ok']), 'Projection failure must not roll back canonical engagement.');
projection_expect((int)(projection_store_get('ZNEWS_ENGAGEMENT/POST_A')['comment_count'] ?? 0) === $canonicalBefore + 1, 'Canonical engagement changed incorrectly after mirror failure.');

$completeB = znews_public_projection_for_post(projection_post('POST_B'), [], [
    'like_count' => 0,
    'comment_count' => 0,
    'share_count' => 0,
    'revision' => 0,
    'updated_at' => 0,
]);
$GLOBALS['projectionStore'] = [
    'ZNEWS_PUBLIC_FEED' => [
        'POST_A' => [
            'post_id' => 'POST_A',
            'creator_uid' => 'USER_A',
            'status' => 'ACTIVE',
            'visibility' => 'PUBLIC',
            'created_at' => znews_now() - 100,
            'ranking_metrics' => ['impressions' => 12],
        ],
        'POST_B' => $completeB,
        'POST_C' => [
            'post_id' => 'POST_C',
            'creator_uid' => 'USER_A',
            'status' => 'ACTIVE',
            'visibility' => 'PUBLIC',
            'created_at' => znews_now() - 80,
        ],
    ],
    'ZNEWS_POSTS' => [
        'POST_A' => projection_post('POST_A'),
        'POST_B' => projection_post('POST_B'),
        'POST_C' => projection_post('POST_C', 'DELETED'),
    ],
    'ZNEWS_ENGAGEMENT' => [
        'POST_A' => ['like_count' => 2, 'comment_count' => 3, 'share_count' => 4, 'revision' => 6, 'updated_at' => znews_now()],
        'POST_B' => ['like_count' => 0, 'comment_count' => 0, 'share_count' => 0, 'revision' => 0, 'updated_at' => 0],
    ],
];
$GLOBALS['projectionPatchCalls'] = [];
$dryRun = znews_public_projection_backfill_run(['dry_run' => true, 'limit' => 2]);
projection_expect(!empty($dryRun['ok']) && !empty($dryRun['has_more']), 'Dry-run backfill must be bounded and resumable.');
projection_expect((int)$dryRun['scanned'] === 2 && (int)$dryRun['would_update'] === 1 && (int)$dryRun['unchanged'] === 1, 'Dry-run did not classify the bounded batch correctly.');
projection_expect(count($GLOBALS['projectionPatchCalls']) === 0, 'Dry-run must not mutate Firebase.');
projection_expect((int)$dryRun['projection_payload_bytes'] > 0, 'Dry-run must report bounded projection payload size.');

$applyFirst = znews_public_projection_backfill_run(['dry_run' => false, 'limit' => 2]);
projection_expect(!empty($applyFirst['ok']) && (int)$applyFirst['would_update'] === 1, 'First backfill batch failed.');
$backfilledA = (array)projection_store_get('ZNEWS_PUBLIC_FEED/POST_A');
projection_expect(znews_public_projection_is_complete($backfilledA), 'Backfill did not complete the legacy projection.');
projection_expect((int)($backfilledA['ranking_metrics']['impressions'] ?? 0) === 12, 'Backfill did not preserve ranking metrics.');
projection_expect((int)($backfilledA['engagement_snapshot']['like_count'] ?? 0) === 2, 'Backfill did not preserve canonical engagement.');

$firebaseOrderedA = $backfilledA;
$firebaseOrderedA['engagement_snapshot'] = array_reverse(
    (array)$firebaseOrderedA['engagement_snapshot'],
    true
);
$firebaseOrderedA['ranking_metrics'] = array_reverse(
    (array)$firebaseOrderedA['ranking_metrics'],
    true
);
projection_expect(
    znews_public_projection_backfill_updates('POST_A', $firebaseOrderedA, $backfilledA) === [],
    'Firebase object key ordering must not cause a false projection update.'
);

$idempotentDryRun = znews_public_projection_backfill_run(['dry_run' => true, 'limit' => 2]);
projection_expect(
    !empty($idempotentDryRun['ok'])
        && (int)$idempotentDryRun['would_update'] === 0
        && (int)$idempotentDryRun['unchanged'] === 2,
    'An applied projection batch must be idempotent on the next dry-run.'
);

$applySecond = znews_public_projection_backfill_run([
    'dry_run' => false,
    'limit' => 2,
    'cursor' => (string)$applyFirst['next_cursor'],
]);
projection_expect(!empty($applySecond['ok']) && (int)$applySecond['would_remove'] === 1, 'Resumed backfill must remove stale non-public projection cache rows.');
projection_expect(projection_store_get('ZNEWS_PUBLIC_FEED/POST_C') === null, 'Stale deleted projection remained after bounded apply.');

$cliSource = file_get_contents(dirname(__DIR__) . '/api/tools/backfill_znews_public_feed_projection.php');
projection_expect(is_string($cliSource) && str_contains($cliSource, "PHP_SAPI !== 'cli'") && str_contains($cliSource, '--dry-run') && str_contains($cliSource, '--apply'), 'Backfill command must remain CLI-only with explicit dry-run/apply modes.');

$assertions = (int)($GLOBALS['projectionAssertions'] ?? 0);
echo "PASS: {$assertions} Z Sky public projection assertions.\n";
