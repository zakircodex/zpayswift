<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__) . '/lib/post_access.php';
require_once dirname(__DIR__) . '/lib/engagement.php';

api_require_method('GET');

$creatorUidRaw = trim((string)($_GET['creator_uid'] ?? ''));
if ($creatorUidRaw === '') {
    api_response(false, 'ZNEWS_CREATOR_UID_REQUIRED', 'Creator is required.', [], 422);
}

$creatorUid = znews_firebase_key($creatorUidRaw, 'creator_uid');
$limit = znews_limit($_GET['limit'] ?? 20, 20, 30);
$cursor = znews_cursor_decode($_GET['cursor'] ?? '');
$index = fb_get('ZNEWS_USER_POSTS/' . $creatorUid);
$rows = [];

if (is_array($index)) {
    foreach ($index as $postKey => $row) {
        $row = is_array($row) ? $row : [];
        $postId = trim((string)($row['post_id'] ?? $postKey));
        if ($postId === '') {
            continue;
        }

        $rows[] = [
            'post_id' => $postId,
            'created_at' => max(0, (int)($row['created_at'] ?? 0)),
        ];
    }
}

znews_sort_index_rows_desc($rows);
$items = [];
$creator = [
    'uid' => $creatorUid,
    'name' => 'Z Sky 24 creator',
    'profile_photo_url' => '',
];

foreach ($rows as $row) {
    $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
    $createdAt = (int)$row['created_at'];

    if (!znews_item_is_after_cursor($createdAt, $postId, $cursor)) {
        continue;
    }

    $post = znews_post_load($postId);
    if (!is_array($post) || !znews_post_is_public($post)) {
        continue;
    }

    $postCreatorUid = trim((string)($post['creator_uid'] ?? ''));
    if ($postCreatorUid === '' || !hash_equals($creatorUid, $postCreatorUid)) {
        continue;
    }

    $formatted = znews_engagement_overlay(znews_format_public_post($post));
    if (!$items) {
        $creator = [
            'uid' => $creatorUid,
            'name' => trim((string)($formatted['creator_name'] ?? 'Z Sky 24 creator')) ?: 'Z Sky 24 creator',
            'profile_photo_url' => trim((string)($formatted['creator_photo_url'] ?? '')),
        ];
    }

    $items[] = $formatted;
    if (count($items) > $limit) {
        break;
    }
}

$hasMore = count($items) > $limit;
if ($hasMore) {
    array_pop($items);
}

$nextCursor = '';
if ($hasMore && $items) {
    $last = $items[count($items) - 1];
    $nextCursor = znews_cursor_encode(
        (int)($last['created_at'] ?? 0),
        (string)($last['post_id'] ?? '')
    );
}

api_response(true, 'ZNEWS_PUBLIC_CREATOR_OK', 'Creator posts loaded.', [
    'creator' => $creator,
    'items' => $items,
    'next_cursor' => $nextCursor,
    'has_more' => $hasMore,
]);
