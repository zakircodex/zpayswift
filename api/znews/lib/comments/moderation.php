<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_admin_moderate_comment(
    array $auth,
    string $postId,
    string $commentId,
    int $expectedUpdatedAt,
    string $idempotencyKey,
    string $action,
    string $note
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $adminUid = znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid');
    $adminName = trim((string)($user['name'] ?? 'Z-Pay Admin'));
    $postId = znews_firebase_key($postId, 'post_id');
    $commentId = znews_firebase_key($commentId, 'comment_id');
    $action = strtoupper(trim($action));
    $approve = $action === 'APPROVE';

    if (!in_array($action, ['APPROVE', 'REJECT'], true)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_INVALID_ACTION',
            'message' => 'Invalid comment moderation action.',
            'http_status' => 422,
        ];
    }

    if ($approve) {
        znews_engagement_require_public_post($postId);
    }

    $claim = znews_engagement_claim(
        $adminUid,
        $postId,
        'COMMENT_' . $action,
        $idempotencyKey,
        [
            'comment_id' => $commentId,
            'expected_updated_at' => $expectedUpdatedAt,
            'note' => $note,
        ]
    );
    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_engagement_replay_result(
            $claim,
            'ZNEWS_COMMENT_RECONCILIATION_REQUIRED',
            'Comment was saved but its indexes require reconciliation.'
        );
    }

    $snapshot = fb_get_with_etag(znews_comment_path($postId, $commentId));
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_READ_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_READ_FAILED',
            'message' => 'Comment could not be loaded.',
            'http_status' => 503,
        ];
    }

    $comment = $snapshot['value'] ?? null;
    if (!is_array($comment)) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_NOT_FOUND');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_NOT_FOUND',
            'message' => 'Comment not found.',
            'http_status' => 404,
        ];
    }

    $status = strtoupper(trim((string)($comment['status'] ?? '')));
    $moderationStatus = strtoupper(trim((string)($comment['moderation_status'] ?? '')));
    $deleted = (int)($comment['deleted_at'] ?? 0) > 0;
    $pendingReview = $status === 'REVIEW' && $moderationStatus === 'PENDING' && !$deleted;
    $wasPublic = znews_comment_is_public($comment);
    $postPublicationReject = !$approve && $wasPublic;

    $allowed = $approve ? $pendingReview : ($pendingReview || $postPublicationReject);
    if (!$allowed) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_NOT_MODERATABLE');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_NOT_MODERATABLE',
            'message' => 'This comment is not available for this moderation action.',
            'http_status' => 409,
        ];
    }

    $currentUpdatedAt = (int)($comment['updated_at'] ?? 0);
    if ($expectedUpdatedAt <= 0 || $expectedUpdatedAt !== $currentUpdatedAt) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_VERSION_CONFLICT',
            'message' => 'This comment changed. Reload it before moderating.',
            'http_status' => 409,
            'data' => ['current_updated_at' => $currentUpdatedAt],
        ];
    }

    $now = znews_now();
    $actionId = 'ZCM' . strtoupper(substr(hash(
        'sha256',
        $adminUid . '|' . $commentId . '|' . $idempotencyKey
    ), 0, 29));

    $updated = $comment;
    $updated['status'] = $approve ? 'ACTIVE' : 'BLOCKED';
    $updated['moderation_status'] = $approve ? 'APPROVED' : 'REJECTED';
    $updated['moderation_note'] = $note;
    $updated['reviewed_by_uid'] = $adminUid;
    $updated['reviewed_by_name'] = $adminName;
    $updated['reviewed_at'] = $now;
    $updated['updated_at'] = $now;
    if ($approve) {
        $updated['published_at'] = $now;
    } else {
        $updated['blocked_at'] = $now;
        $updated['post_publication_action'] = $postPublicationReject
            ? 'BLOCKED_AFTER_PUBLICATION'
            : 'REJECTED_BEFORE_PUBLICATION';
    }

    $write = fb_put_if_match(
        znews_comment_path($postId, $commentId),
        $updated,
        (string)$snapshot['etag']
    );
    if ((int)($write['status'] ?? 0) === 412) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_VERSION_CONFLICT',
            'message' => 'This comment changed. Reload it before moderating.',
            'http_status' => 409,
        ];
    }
    if (empty($write['ok'])) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_MODERATION_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_MODERATION_FAILED',
            'message' => 'Comment moderation decision could not be saved.',
            'http_status' => 503,
        ];
    }

    $authorUid = znews_firebase_key((string)($updated['author_uid'] ?? ''), 'author_uid');
    $indexOk = fb_patch('', [
        znews_comment_review_queue_path($commentId) => null,
        znews_comment_user_index_path($authorUid, $commentId) => [
            'comment_id' => $commentId,
            'post_id' => $postId,
            'status' => $updated['status'],
            'created_at' => (int)($updated['created_at'] ?? $now),
            'updated_at' => $now,
            'published_at' => (int)($updated['published_at'] ?? 0),
            'blocked_at' => (int)($updated['blocked_at'] ?? 0),
        ],
        znews_comment_action_path($commentId, $actionId) => [
            'action_id' => $actionId,
            'comment_id' => $commentId,
            'post_id' => $postId,
            'action' => $action,
            'mode' => $postPublicationReject ? 'POST_PUBLICATION_BLOCK' : 'PRE_PUBLICATION_REVIEW',
            'admin_uid' => $adminUid,
            'admin_name' => $adminName,
            'note' => $note,
            'created_at' => $now,
        ],
    ]);

    $counterResult = ['ok' => true, 'counts' => znews_engagement_counts($postId)];
    if ($approve) {
        $counterResult = znews_engagement_adjust_counter($postId, 'comment_count', 1);
    } elseif ($postPublicationReject) {
        $counterResult = znews_engagement_adjust_counter($postId, 'comment_count', -1);
    }
    $counterOk = !empty($counterResult['ok']);
    $counts = is_array($counterResult['counts'] ?? null)
        ? (array)$counterResult['counts']
        : znews_engagement_counts($postId);

    $formatted = znews_comment_format($updated, true);
    $result = [
        'comment' => $formatted,
        'counts' => $counts,
        'post_publication_block' => $postPublicationReject,
        'reconciliation_required' => !$indexOk || !$counterOk,
    ];
    znews_engagement_finish($claim, $result);

    if (!$indexOk || !$counterOk) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_RECONCILIATION_REQUIRED',
            'message' => 'Moderation was saved but comment indexes require reconciliation.',
            'http_status' => 503,
            'comment' => $formatted,
            'counts' => $counts,
        ];
    }

    if (function_exists('system_log')) {
        system_log(
            $approve
                ? 'ZNEWS_COMMENT_APPROVED'
                : ($postPublicationReject ? 'ZNEWS_COMMENT_BLOCKED_AFTER_PUBLICATION' : 'ZNEWS_COMMENT_REJECTED'),
            $commentId,
            'Z News comment moderation decision',
            [
                'post_id' => $postId,
                'comment_id' => $commentId,
                'admin_uid' => $adminUid,
                'post_publication_block' => $postPublicationReject,
            ]
        );
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'comment' => $formatted,
        'counts' => $counts,
        'post_publication_block' => $postPublicationReject,
    ];
}
