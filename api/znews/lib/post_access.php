<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_path_public_feed(string $postId): string
{
    return 'ZNEWS_PUBLIC_FEED/' . znews_firebase_key($postId, 'post_id');
}

function znews_post_load(string $postId): ?array
{
    $post = fb_get(znews_path_post($postId));
    return is_array($post) ? $post : null;
}

function znews_post_is_public(array $post): bool
{
    return znews_normalize_status($post['status'] ?? '', '') === 'ACTIVE'
        && strtoupper(trim((string)($post['visibility'] ?? 'PUBLIC'))) === 'PUBLIC'
        && (int)($post['deleted_at'] ?? 0) === 0;
}

function znews_format_owned_post(array $post): array
{
    $formatted = znews_format_post($post);
    $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');

    $formatted['deleted_at'] = (int)($post['deleted_at'] ?? 0);
    $formatted['can_edit'] = in_array($status, ['ACTIVE', 'REVIEW'], true);
    $formatted['can_delete'] = $status !== 'DELETED';

    return $formatted;
}

function znews_post_owner_snapshot(
    string $uid,
    string $postId,
    bool $allowDeleted = false
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $postId = znews_firebase_key($postId, 'post_id');
    $snapshot = fb_get_with_etag(znews_path_post($postId));

    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        api_response(false, 'ZNEWS_POST_READ_FAILED', 'Post could not be loaded.', [], 503);
    }

    $post = $snapshot['value'] ?? null;
    if (!is_array($post)) {
        api_response(false, 'ZNEWS_POST_NOT_FOUND', 'Post not found.', [], 404);
    }

    $creatorUid = trim((string)($post['creator_uid'] ?? ''));
    if ($creatorUid === '' || !hash_equals($creatorUid, $uid)) {
        api_response(false, 'ZNEWS_POST_FORBIDDEN', 'You cannot access this post.', [], 403);
    }

    $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');
    if (!$allowDeleted && $status === 'DELETED') {
        api_response(false, 'ZNEWS_POST_NOT_FOUND', 'Post not found.', [], 404);
    }

    return [
        'post' => $post,
        'etag' => (string)$snapshot['etag'],
        'post_id' => $postId,
    ];
}

function znews_cursor_encode(int $createdAt, string $postId): string
{
    $payload = json_encode([
        'created_at' => max(0, $createdAt),
        'post_id' => $postId,
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return '';
    }

    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}

function znews_cursor_decode($value): array
{
    $cursor = trim((string)$value);
    if ($cursor === '') {
        return [];
    }

    if (strlen($cursor) > 512 || preg_match('/[^A-Za-z0-9_-]/', $cursor) === 1) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    $padding = strlen($cursor) % 4;
    if ($padding > 0) {
        $cursor .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $data = is_string($decoded) ? json_decode($decoded, true) : null;

    if (!is_array($data)) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    $createdAt = filter_var($data['created_at'] ?? null, FILTER_VALIDATE_INT);
    $postId = trim((string)($data['post_id'] ?? ''));

    if ($createdAt === false || $createdAt < 0 || $postId === '') {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    return [
        'created_at' => (int)$createdAt,
        'post_id' => znews_firebase_key($postId, 'cursor post_id'),
    ];
}

function znews_item_is_after_cursor(int $createdAt, string $postId, array $cursor): bool
{
    if (!$cursor) {
        return true;
    }

    $cursorCreatedAt = (int)($cursor['created_at'] ?? 0);
    $cursorPostId = (string)($cursor['post_id'] ?? '');

    return $createdAt < $cursorCreatedAt
        || ($createdAt === $cursorCreatedAt && strcmp($postId, $cursorPostId) < 0);
}

function znews_sort_index_rows_desc(array &$rows): void
{
    usort($rows, static function (array $a, array $b): int {
        $timeCompare = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        if ($timeCompare !== 0) {
            return $timeCompare;
        }

        return strcmp((string)($b['post_id'] ?? ''), (string)($a['post_id'] ?? ''));
    });
}

function znews_owned_posts_page(
    string $uid,
    int $limit,
    array $cursor = [],
    bool $includeDeleted = false
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $index = fb_get('ZNEWS_USER_POSTS/' . $uid);
    if (!is_array($index)) {
        return [
            'items' => [],
            'next_cursor' => '',
            'has_more' => false,
        ];
    }

    $rows = [];
    foreach ($index as $postKey => $row) {
        if (!is_array($row)) {
            continue;
        }

        $postId = trim((string)($row['post_id'] ?? $postKey));
        if ($postId === '') {
            continue;
        }

        $rows[] = [
            'post_id' => $postId,
            'created_at' => max(0, (int)($row['created_at'] ?? 0)),
        ];
    }

    znews_sort_index_rows_desc($rows);

    $items = [];
    foreach ($rows as $row) {
        $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
        $createdAt = (int)$row['created_at'];

        if (!znews_item_is_after_cursor($createdAt, $postId, $cursor)) {
            continue;
        }

        $post = znews_post_load($postId);
        if (!is_array($post)) {
            continue;
        }

        $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');
        if (!$includeDeleted && $status === 'DELETED') {
            continue;
        }

        $items[] = znews_format_owned_post($post);
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

    return [
        'items' => $items,
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ];
}

function znews_public_post_by_id(string $postId): ?array
{
    $post = znews_post_load(znews_firebase_key($postId, 'post_id'));
    if (!is_array($post) || !znews_post_is_public($post)) {
        return null;
    }

    return znews_format_post($post);
}

function znews_public_feed_page(int $limit, array $cursor = []): array
{
    $index = fb_get('ZNEWS_PUBLIC_FEED');
    if (!is_array($index)) {
        return [
            'items' => [],
            'next_cursor' => '',
            'has_more' => false,
        ];
    }

    $rows = [];
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

    znews_sort_index_rows_desc($rows);

    $items = [];
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

        $items[] = znews_format_post($post);
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

    return [
        'items' => $items,
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ];
}
