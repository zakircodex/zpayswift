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

    $formatted['image_media_id'] = trim((string)($post['image_media_id'] ?? ''));
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

function znews_bounded_multi_get(array $paths, int $maximum = 22): array
{
    $paths = array_values(array_unique(array_filter(array_map(
        static fn($path): string => trim((string)$path, '/'),
        $paths
    ))));
    if ($paths === []) {
        return ['ok' => true, 'values' => [], 'request_count' => 0];
    }
    if (count($paths) > max(1, $maximum)) {
        return ['ok' => false, 'values' => [], 'request_count' => 0];
    }

    if (!function_exists('fb_build_url') || !function_exists('curl_multi_init')) {
        $values = [];
        foreach ($paths as $path) {
            $values[$path] = fb_get($path);
        }
        return ['ok' => true, 'values' => $values, 'request_count' => count($paths)];
    }

    $multi = curl_multi_init();
    if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 8);
    }
    if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
        curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 8);
    }
    $handles = [];
    foreach ($paths as $path) {
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => fb_build_url($path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = [$path, $handle];
    }

    $multiStatus = CURLM_OK;
    do {
        $multiStatus = curl_multi_exec($multi, $running);
        if ($running && $multiStatus === CURLM_OK) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(10000);
            }
        }
    } while ($running && $multiStatus === CURLM_OK);

    $values = [];
    $ok = $multiStatus === CURLM_OK;
    foreach ($handles as [$path, $handle]) {
        $raw = curl_multi_getcontent($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $decoded = null;
        if ($status >= 200 && $status < 300 && is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $ok = $ok && json_last_error() === JSON_ERROR_NONE;
        } else {
            $ok = false;
        }
        $values[$path] = $decoded;
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return [
        'ok' => $ok,
        'values' => $values,
        'request_count' => count($paths),
    ];
}

function znews_owned_posts_page(
    string $uid,
    int $limit,
    array $cursor = [],
    bool $includeDeleted = false
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $candidateWindow = max($limit + 1, min(33, ($limit + 1) * 3));
    $query = [
        'orderBy' => json_encode('created_at'),
        'limitToLast' => $candidateWindow,
    ];
    if ($cursor) {
        $query['endAt'] = json_encode(max(0, (int)($cursor['created_at'] ?? 0)));
    }

    $index = fb_get('ZNEWS_USER_POSTS/' . $uid, $query);
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
            'status' => znews_normalize_status($row['status'] ?? '', ''),
        ];
    }

    znews_sort_index_rows_desc($rows);

    $eligibleRows = [];
    $lastScanned = null;
    foreach ($rows as $row) {
        $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
        $createdAt = (int)$row['created_at'];

        if (!znews_item_is_after_cursor($createdAt, $postId, $cursor)) {
            continue;
        }
        if (!$includeDeleted && ($row['status'] ?? '') === 'DELETED') {
            continue;
        }
        $eligibleRows[] = $row;
    }

    $items = [];
    $offset = 0;
    $chunkSize = $limit + 1;
    while (count($items) <= $limit && $offset < count($eligibleRows)) {
        $chunk = array_slice($eligibleRows, $offset, $chunkSize);
        $offset += count($chunk);
        $paths = [];
        foreach ($chunk as $row) {
            $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
            $paths[] = znews_path_post($postId);
            $paths[] = 'ZNEWS_ENGAGEMENT/' . $postId;
        }
        $batch = znews_bounded_multi_get($paths, $chunkSize * 2);
        if (empty($batch['ok'])) {
            api_response(
                false,
                'ZNEWS_MY_POSTS_READ_FAILED',
                'Posts could not be loaded. Please try again.',
                [],
                503
            );
        }
        $values = is_array($batch['values'] ?? null) ? (array)$batch['values'] : [];

        foreach ($chunk as $row) {
            $lastScanned = $row;
            $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
            $post = $values[znews_path_post($postId)] ?? null;
            if (!is_array($post)) {
                continue;
            }
            $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');
            if (!$includeDeleted && $status === 'DELETED') {
                continue;
            }

            $formatted = znews_format_owned_post($post);
            $counts = $values['ZNEWS_ENGAGEMENT/' . $postId] ?? [];
            if (function_exists('znews_engagement_overlay_counts')) {
                $formatted = znews_engagement_overlay_counts(
                    $formatted,
                    is_array($counts) ? $counts : []
                );
            } else {
                $formatted['like_count'] = max(0, (int)($counts['like_count'] ?? 0));
                $formatted['comment_count'] = max(0, (int)($counts['comment_count'] ?? 0));
                $formatted['share_count'] = max(0, (int)($counts['share_count'] ?? 0));
            }
            $items[] = $formatted;
            if (count($items) > $limit) {
                break 2;
            }
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
    } elseif (($offset < count($eligibleRows) || count($index) >= $candidateWindow)
        && is_array($lastScanned)) {
        $hasMore = true;
        $nextCursor = znews_cursor_encode(
            (int)($lastScanned['created_at'] ?? 0),
            (string)($lastScanned['post_id'] ?? '')
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

    return znews_format_public_post($post);
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

        $items[] = znews_format_public_post($post);
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
