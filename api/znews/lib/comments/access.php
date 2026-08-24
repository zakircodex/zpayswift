<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_public_comments_page(
    string $postId,
    int $limit,
    array $cursor = []
): array {
    $postId = znews_firebase_key($postId, 'post_id');
    znews_engagement_require_public_post($postId);
    $candidateWindow = max($limit + 1, min(63, ($limit + 1) * 3));
    $query = [
        'orderBy' => json_encode('created_at'),
        'limitToFirst' => $candidateWindow,
    ];
    if ($cursor) {
        $query['startAt'] = json_encode(max(0, (int)($cursor['created_at'] ?? 0)));
    }
    $rows = fb_get('ZNEWS_COMMENTS/' . $postId, $query);
    $comments = [];
    $scanned = [];

    if (is_array($rows)) {
        foreach ($rows as $commentId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['comment_id'] = (string)($row['comment_id'] ?? $commentId);
            $formatted = znews_comment_format($row, false);
            if (!znews_comment_after_cursor($formatted, $cursor)) {
                continue;
            }
            $scanned[] = $formatted;
            if (!znews_comment_is_public($row)) {
                continue;
            }
            $comments[] = $formatted;
        }
    }

    usort($comments, static function (array $a, array $b): int {
        $timeCompare = ((int)$a['created_at']) <=> ((int)$b['created_at']);
        return $timeCompare !== 0
            ? $timeCompare
            : strcmp((string)$a['comment_id'], (string)$b['comment_id']);
    });

    $slice = array_slice($comments, 0, $limit + 1);
    $hasMore = count($slice) > $limit;
    if ($hasMore) {
        array_pop($slice);
    }

    $nextCursor = '';
    if ($hasMore && $slice) {
        $last = $slice[count($slice) - 1];
        $nextCursor = znews_comment_cursor_encode(
            (int)$last['created_at'],
            (string)$last['comment_id']
        );
    } elseif (is_array($rows) && count($rows) >= $candidateWindow && $scanned) {
        usort($scanned, static function (array $a, array $b): int {
            $timeCompare = ((int)$a['created_at']) <=> ((int)$b['created_at']);
            return $timeCompare !== 0
                ? $timeCompare
                : strcmp((string)$a['comment_id'], (string)$b['comment_id']);
        });
        $lastScanned = $scanned[count($scanned) - 1];
        $hasMore = true;
        $nextCursor = znews_comment_cursor_encode(
            (int)$lastScanned['created_at'],
            (string)$lastScanned['comment_id']
        );
    }

    return [
        'items' => array_values($slice),
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ];
}

function znews_admin_comment_queue(int $limit, array $cursor = []): array
{
    $index = fb_get('ZNEWS_COMMENT_REVIEW_QUEUE');
    $rows = [];

    if (is_array($index)) {
        foreach ($index as $commentId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $postId = trim((string)($entry['post_id'] ?? ''));
            $commentId = trim((string)($entry['comment_id'] ?? $commentId));
            if ($postId === '' || $commentId === '') {
                continue;
            }

            $comment = fb_get(znews_comment_path($postId, $commentId));
            if (!is_array($comment)
                || strtoupper(trim((string)($comment['status'] ?? ''))) !== 'REVIEW'
                || strtoupper(trim((string)($comment['moderation_status'] ?? ''))) !== 'PENDING'
                || (int)($comment['deleted_at'] ?? 0) > 0) {
                continue;
            }

            $formatted = znews_comment_format($comment, true);
            $rows[] = $formatted;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $timeCompare = ((int)$b['created_at']) <=> ((int)$a['created_at']);
        return $timeCompare !== 0
            ? $timeCompare
            : strcmp((string)$b['comment_id'], (string)$a['comment_id']);
    });

    if ($cursor) {
        $rows = array_values(array_filter($rows, static function (array $row) use ($cursor): bool {
            $createdAt = (int)$row['created_at'];
            $commentId = (string)$row['comment_id'];
            $cursorTime = (int)($cursor['created_at'] ?? 0);
            $cursorId = (string)($cursor['comment_id'] ?? '');

            return $createdAt < $cursorTime
                || ($createdAt === $cursorTime && strcmp($commentId, $cursorId) < 0);
        }));
    }

    $slice = array_slice($rows, 0, $limit + 1);
    $hasMore = count($slice) > $limit;
    if ($hasMore) {
        array_pop($slice);
    }

    $nextCursor = '';
    if ($hasMore && $slice) {
        $last = $slice[count($slice) - 1];
        $nextCursor = znews_comment_cursor_encode(
            (int)$last['created_at'],
            (string)$last['comment_id']
        );
    }

    return [
        'items' => array_values($slice),
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ];
}

function znews_admin_comment_details(string $postId, string $commentId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $commentId = znews_firebase_key($commentId, 'comment_id');
    $comment = fb_get(znews_comment_path($postId, $commentId));

    if (!is_array($comment)) {
        api_response(false, 'ZNEWS_COMMENT_NOT_FOUND', 'Comment not found.', [], 404);
    }

    $actions = fb_get('ZNEWS_COMMENT_MODERATION_ACTIONS/' . $commentId);

    return [
        'comment' => znews_comment_format($comment, true),
        'moderation_actions' => is_array($actions) ? array_values($actions) : [],
    ];
}
