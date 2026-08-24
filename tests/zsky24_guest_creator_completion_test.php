<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = [];
$queries = [];

function completion_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function completion_query_rows(array $rows, array $query): array
{
    if (!$query) {
        return $rows;
    }
    $orderBy = json_decode((string)($query['orderBy'] ?? '"$key"'), true);
    if ($orderBy === 'created_at') {
        uasort($rows, static function ($a, $b): int {
            $time = ((int)($a['created_at'] ?? 0)) <=> ((int)($b['created_at'] ?? 0));
            return $time;
        });
    }
    if (isset($query['startAt'])) {
        $start = (int)json_decode((string)$query['startAt'], true);
        $rows = array_filter($rows, static fn($row): bool => (int)($row['created_at'] ?? 0) >= $start);
    }
    if (isset($query['endAt'])) {
        $end = (int)json_decode((string)$query['endAt'], true);
        $rows = array_filter($rows, static fn($row): bool => (int)($row['created_at'] ?? 0) <= $end);
    }
    if (isset($query['limitToFirst'])) {
        $rows = array_slice($rows, 0, (int)$query['limitToFirst'], true);
    }
    if (isset($query['limitToLast'])) {
        $rows = array_slice($rows, -(int)$query['limitToLast'], null, true);
    }
    return $rows;
}

function fb_get(string $path, array $query = []): mixed
{
    global $fixture, $queries;
    $queries[] = ['path' => $path, 'query' => $query];
    $value = $fixture[$path] ?? null;
    return is_array($value) ? completion_query_rows($value, $query) : $value;
}

function znews_engagement_require_public_post(string $postId): array
{
    return ['post_id' => $postId, 'status' => 'ACTIVE'];
}

require_once $root . '/api/znews/lib/common.php';
require_once $root . '/api/znews/lib/posts.php';
require_once $root . '/api/znews/lib/post_access.php';
require_once $root . '/api/znews/lib/comments/common.php';
require_once $root . '/api/znews/lib/comments/access.php';
require_once $root . '/api/znews/lib/creator_view_policy.php';

for ($index = 1; $index <= 100; $index++) {
    $postId = 'POST' . str_pad((string)$index, 3, '0', STR_PAD_LEFT);
    $fixture['ZNEWS_USER_POSTS/U1'][$postId] = [
        'post_id' => $postId,
        'created_at' => $index,
    ];
    $fixture['ZNEWS_POSTS/' . $postId] = [
        'post_id' => $postId,
        'creator_uid' => 'U1',
        'creator_name' => 'Creator',
        'title' => 'Title ' . $index,
        'text' => 'Body',
        'image_media_id' => 'MEDIA' . $index,
        'status' => 'ACTIVE',
        'visibility' => 'PUBLIC',
        'moderation_status' => 'APPROVED',
        'created_at' => $index,
        'updated_at' => $index,
    ];
}

$mineFirst = znews_owned_posts_page('U1', 10);
completion_expect(count($mineFirst['items']) === 10, 'My Posts first page must contain 10 rows.');
completion_expect(!empty($mineFirst['has_more']) && $mineFirst['next_cursor'] !== '', 'My Posts must return a continuation cursor.');
completion_expect((int)$mineFirst['items'][0]['created_at'] === 100, 'My Posts must be newest first.');
completion_expect(($mineFirst['items'][0]['image_media_id'] ?? '') === 'MEDIA100', 'Owner media ID must be available for edit without entering public payloads.');
$mineQuery = $queries[0]['query'] ?? [];
completion_expect((int)($mineQuery['limitToLast'] ?? 0) <= 33, 'My Posts index query must remain bounded.');
completion_expect(($mineQuery['orderBy'] ?? '') === json_encode('created_at'), 'My Posts must query the created_at index.');

$mineSecond = znews_owned_posts_page('U1', 10, znews_cursor_decode($mineFirst['next_cursor']));
completion_expect(count($mineSecond['items']) === 10, 'My Posts second page must contain 10 rows.');
completion_expect($mineFirst['items'][9]['post_id'] !== $mineSecond['items'][0]['post_id'], 'My Posts pages must not repeat the cursor row.');

$queries = [];
for ($index = 1; $index <= 100; $index++) {
    $commentId = 'COMMENT' . str_pad((string)$index, 3, '0', STR_PAD_LEFT);
    $fixture['ZNEWS_COMMENTS/POST100'][$commentId] = [
        'comment_id' => $commentId,
        'post_id' => 'POST100',
        'author_uid' => 'U2',
        'author_name' => 'Reader',
        'text' => 'Approved comment',
        'status' => 'ACTIVE',
        'moderation_status' => 'APPROVED',
        'created_at' => $index,
        'updated_at' => $index,
        'deleted_at' => 0,
    ];
}

$commentsFirst = znews_public_comments_page('POST100', 20);
completion_expect(count($commentsFirst['items']) === 20, 'Public comments first page must contain 20 rows.');
completion_expect(!empty($commentsFirst['has_more']) && $commentsFirst['next_cursor'] !== '', 'Public comments must return a continuation cursor.');
completion_expect((int)$commentsFirst['items'][0]['created_at'] === 1, 'Approved comments must remain oldest first.');
$commentQuery = $queries[0]['query'] ?? [];
completion_expect((int)($commentQuery['limitToFirst'] ?? 0) <= 63, 'Public comments query must remain bounded.');
completion_expect(($commentQuery['orderBy'] ?? '') === json_encode('created_at'), 'Public comments must query the created_at index.');

$commentsSecond = znews_public_comments_page('POST100', 20, znews_comment_cursor_decode($commentsFirst['next_cursor']));
completion_expect(count($commentsSecond['items']) === 20, 'Public comments second page must contain 20 rows.');
completion_expect($commentsFirst['items'][19]['comment_id'] !== $commentsSecond['items'][0]['comment_id'], 'Comment pages must not repeat the cursor row.');

$formatted = znews_format_post($fixture['ZNEWS_POSTS/POST100']);
foreach (['balance', 'wallet', 'available_balance', 'credit', 'phone', 'email'] as $privateField) {
    completion_expect(!array_key_exists($privateField, $formatted), "Public post payload leaked {$privateField}.");
}

$mutationFiles = [
    'posts/create.php', 'posts/update.php', 'posts/delete.php', 'posts/mine.php',
    'likes/set.php', 'comments/create.php', 'shares/create.php',
];
foreach ($mutationFiles as $relative) {
    $source = (string)file_get_contents($root . '/api/znews/' . $relative);
    completion_expect(str_contains($source, 'znews_require_creator'), "{$relative} must require a creator session.");
}
$ownershipSource = (string)file_get_contents($root . '/api/znews/lib/post_access.php');
completion_expect(str_contains($ownershipSource, 'znews_post_owner_snapshot') && str_contains($ownershipSource, 'hash_equals'), 'Post update/delete ownership must remain server enforced.');

$oldAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
$_SERVER['HTTP_USER_AGENT'] = 'ZPaySwift-Android-ZNews/1.0';
$androidGate = znews_creator_view_gate('', 'POST100', 'android-view-test');
completion_expect(empty($androidGate['ad_eligible']), 'Android view must never be ad eligible.');
completion_expect(empty($androidGate['revenue_share_eligible']), 'Android view must never be revenue-share eligible.');
completion_expect(($androidGate['reason'] ?? '') === 'ANDROID_APP_NO_ADS', 'Android no-ad reason must be explicit.');
if ($oldAgent === null) {
    unset($_SERVER['HTTP_USER_AGENT']);
} else {
    $_SERVER['HTTP_USER_AGENT'] = $oldAgent;
}

$web = (string)file_get_contents($root . '/znews/assets/znews.js');
$index = (string)file_get_contents($root . '/znews/index.html');
completion_expect(str_contains($web, 'const creatorActions = api.isAuthenticated()'), 'Web post controls must be selected before guest markup is rendered.');
completion_expect(str_contains($web, "if (api.isAuthenticated())") && str_contains($web, 'await api.recordShare'), 'Guest Web Share must not depend on authenticated analytics.');
completion_expect(str_contains($index, 'id="postTitle"') && str_contains($web, 'title: postTitle'), 'Web Create title contract must remain present.');
completion_expect(str_contains($index, 'id="mineLoadMoreButton"'), 'Web My Posts continuation control is missing.');

fwrite(STDOUT, "Z Sky 24 guest/creator completion tests passed.\n");
