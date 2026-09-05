<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_legacy_comment_audit_limit(int $limit): int
{
    return max(1, min(100, $limit));
}

function znews_legacy_comment_audit_queue(int $limit, string $cursor = ''): array
{
    $limit = znews_legacy_comment_audit_limit($limit);
    $query = [
        'orderBy' => json_encode('$key'),
        'limitToFirst' => $limit + ($cursor !== '' ? 2 : 1),
    ];
    if ($cursor !== '') {
        $query['startAt'] = json_encode(znews_firebase_key($cursor, 'cursor'));
    }
    $rows = fb_get('ZNEWS_COMMENT_REVIEW_QUEUE', $query);
    $items = [];
    if (is_array($rows)) {
        ksort($rows, SORT_STRING);
        foreach ($rows as $commentId => $row) {
            $commentId = trim((string)$commentId);
            if ($commentId === '' || $commentId === $cursor || !is_array($row)) {
                continue;
            }
            $items[$commentId] = $row;
            if (count($items) >= $limit + 1) {
                break;
            }
        }
    }
    $hasMore = count($items) > $limit;
    if ($hasMore) {
        array_pop($items);
    }
    $nextCursor = $items !== [] ? (string)array_key_last($items) : '';
    return ['items' => $items, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
}

function znews_legacy_comment_reconcile_published(
    string $postId,
    string $commentId,
    array $comment,
    int $now
): array {
    $authorUid = trim((string)($comment['author_uid'] ?? ''));
    if ($authorUid !== '' && !fb_patch('', [
        znews_comment_user_index_path($authorUid, $commentId) => [
            'comment_id' => $commentId,
            'post_id' => $postId,
            'status' => 'ACTIVE',
            'created_at' => (int)($comment['created_at'] ?? $now),
            'updated_at' => $now,
            'published_at' => (int)($comment['published_at'] ?? $now),
        ],
    ])) {
        return [
            'action' => 'APPROVED_RECONCILIATION_REQUIRED',
            'reason' => 'COMMENT_INDEX_RECONCILIATION_REQUIRED',
            'post_id' => $postId,
            'comment_id' => $commentId,
        ];
    }

    $counter = znews_comment_recount($postId);
    if (empty($counter['ok'])) {
        return [
            'action' => 'APPROVED_RECONCILIATION_REQUIRED',
            'reason' => 'COMMENT_COUNT_RECONCILIATION_REQUIRED',
            'post_id' => $postId,
            'comment_id' => $commentId,
        ];
    }
    if (!fb_patch('', [znews_comment_review_queue_path($commentId) => null])) {
        return [
            'action' => 'APPROVED_RECONCILIATION_REQUIRED',
            'reason' => 'COMMENT_QUEUE_RECONCILIATION_REQUIRED',
            'post_id' => $postId,
            'comment_id' => $commentId,
        ];
    }
    return [
        'action' => 'APPROVED',
        'reason' => (string)($comment['legacy_review_reconciliation_reason'] ?? 'COMMENT_CHECKS_CLEAR'),
        'post_id' => $postId,
        'comment_id' => $commentId,
    ];
}

function znews_legacy_comment_audit_one(string $commentId, array $entry, bool $dryRun): array
{
    $postId = trim((string)($entry['post_id'] ?? ''));
    $commentId = trim((string)($entry['comment_id'] ?? $commentId));
    if ($postId === '' || $commentId === '') {
        return ['action' => 'SKIP', 'reason' => 'MALFORMED_QUEUE_ENTRY', 'comment_id' => $commentId];
    }

    $path = znews_comment_path($postId, $commentId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['action' => 'ERROR', 'reason' => 'COMMENT_READ_FAILED', 'post_id' => $postId, 'comment_id' => $commentId];
    }
    $comment = $snapshot['value'] ?? null;
    if (!is_array($comment)) {
        return ['action' => 'SKIP', 'reason' => 'COMMENT_NOT_FOUND', 'post_id' => $postId, 'comment_id' => $commentId];
    }
    $pending = strtoupper(trim((string)($comment['status'] ?? ''))) === 'REVIEW'
        && strtoupper(trim((string)($comment['moderation_status'] ?? ''))) === 'PENDING'
        && (int)($comment['deleted_at'] ?? 0) === 0;
    $partiallyReconciled = znews_comment_is_public($comment)
        && (int)($comment['legacy_review_audited_at'] ?? 0) > 0;
    if ($partiallyReconciled) {
        if ($dryRun) {
            return [
                'action' => 'RECONCILE',
                'reason' => 'PUBLISHED_COMMENT_RECONCILIATION_REQUIRED',
                'post_id' => $postId,
                'comment_id' => $commentId,
            ];
        }
        return znews_legacy_comment_reconcile_published($postId, $commentId, $comment, znews_now());
    }
    if (!$pending) {
        return ['action' => 'SKIP', 'reason' => 'COMMENT_NOT_PENDING', 'post_id' => $postId, 'comment_id' => $commentId];
    }

    $decision = znews_comment_publication_decision((string)($comment['text'] ?? ''));
    if (empty($decision['publish'])) {
        return [
            'action' => 'REMAIN_PENDING',
            'reason' => (string)($decision['reason'] ?? 'CURRENT_SAFETY_REVIEW'),
            'post_id' => $postId,
            'comment_id' => $commentId,
        ];
    }
    if ($dryRun) {
        return [
            'action' => 'APPROVE',
            'reason' => (string)($decision['reason'] ?? 'COMMENT_CHECKS_CLEAR'),
            'post_id' => $postId,
            'comment_id' => $commentId,
        ];
    }

    $now = znews_now();
    $updated = znews_apply_comment_publication_decision($comment, $decision, $now);
    $updated['updated_at'] = $now;
    $updated['legacy_review_audited_at'] = $now;
    $updated['legacy_review_reconciliation_reason'] = (string)($decision['reason'] ?? 'COMMENT_CHECKS_CLEAR');
    $write = fb_put_if_match($path, $updated, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        return ['action' => 'ERROR', 'reason' => 'COMMENT_VERSION_CONFLICT', 'post_id' => $postId, 'comment_id' => $commentId];
    }
    if (empty($write['ok'])) {
        return ['action' => 'ERROR', 'reason' => 'COMMENT_APPROVE_FAILED', 'post_id' => $postId, 'comment_id' => $commentId];
    }

    return znews_legacy_comment_reconcile_published($postId, $commentId, $updated, $now);
}

function znews_legacy_comment_audit_run(array $options = []): array
{
    $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
    $page = znews_legacy_comment_audit_queue(
        (int)($options['limit'] ?? 50),
        trim((string)($options['cursor'] ?? ''))
    );
    $results = [];
    $counts = [
        'scanned' => 0,
        'would_approve' => 0,
        'would_reconcile' => 0,
        'approved' => 0,
        'remain_pending' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];
    foreach ((array)$page['items'] as $commentId => $entry) {
        $result = znews_legacy_comment_audit_one((string)$commentId, (array)$entry, $dryRun);
        $results[] = $result;
        $counts['scanned']++;
        $action = (string)($result['action'] ?? 'ERROR');
        if ($action === 'APPROVE') {
            $counts['would_approve']++;
        } elseif ($action === 'RECONCILE') {
            $counts['would_reconcile']++;
        } elseif ($action === 'APPROVED') {
            $counts['approved']++;
        } elseif ($action === 'REMAIN_PENDING') {
            $counts['remain_pending']++;
        } elseif ($action === 'SKIP') {
            $counts['skipped']++;
        } else {
            $counts['errors']++;
        }
    }
    return array_merge([
        'ok' => $counts['errors'] === 0,
        'code' => $counts['errors'] === 0 ? 'ZNEWS_LEGACY_COMMENT_AUDIT_OK' : 'ZNEWS_LEGACY_COMMENT_AUDIT_ERRORS',
        'mode' => $dryRun ? 'dry-run' : 'apply',
        'has_more' => !empty($page['has_more']),
        'next_cursor' => (string)($page['next_cursor'] ?? ''),
        'results' => $results,
    ], $counts);
}
