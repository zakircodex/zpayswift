<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_comment_update(
    array $auth,
    string $postId,
    string $commentId,
    string $text,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $postId = znews_firebase_key($postId, 'post_id');
    $commentId = znews_firebase_key($commentId, 'comment_id');
    znews_engagement_require_public_post($postId);

    $owned = znews_comment_owner_snapshot($uid, $postId, $commentId, false);
    $comment = (array)$owned['comment'];
    $status = strtoupper(trim((string)($comment['status'] ?? 'REVIEW')));
    if ($status === 'BLOCKED') {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_BLOCKED',
            'message' => 'Blocked comments cannot be edited.',
            'http_status' => 409,
        ];
    }

    $currentUpdatedAt = (int)($comment['updated_at'] ?? 0);
    if ($expectedUpdatedAt <= 0 || $currentUpdatedAt !== $expectedUpdatedAt) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_VERSION_CONFLICT',
            'message' => 'This comment changed. Reload it before editing.',
            'http_status' => 409,
            'data' => ['current_updated_at' => $currentUpdatedAt],
        ];
    }

    $claim = znews_engagement_claim(
        $uid,
        $postId,
        'COMMENT_UPDATE',
        $idempotencyKey,
        [
            'comment_id' => $commentId,
            'text' => $text,
            'expected_updated_at' => $expectedUpdatedAt,
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

    $wasPublic = znews_comment_is_public($comment);
    $now = znews_now();
    $updated = $comment;
    $updated['text'] = $text;
    $updated['status'] = 'REVIEW';
    $updated['moderation_status'] = 'PENDING';
    $updated['moderation_note'] = '';
    $updated['updated_at'] = $now;
    $updated['last_edit_at'] = $now;

    $write = fb_put_if_match(
        znews_comment_path($postId, $commentId),
        $updated,
        (string)$owned['etag']
    );
    if ((int)($write['status'] ?? 0) === 412) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_VERSION_CONFLICT',
            'message' => 'This comment changed. Reload it before editing.',
            'http_status' => 409,
        ];
    }
    if (empty($write['ok'])) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_UPDATE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_UPDATE_FAILED',
            'message' => 'Comment could not be updated.',
            'http_status' => 503,
        ];
    }

    $indexOk = fb_patch('', [
        znews_comment_user_index_path($uid, $commentId) => [
            'comment_id' => $commentId,
            'post_id' => $postId,
            'status' => 'REVIEW',
            'created_at' => (int)($updated['created_at'] ?? $now),
            'updated_at' => $now,
        ],
        znews_comment_review_queue_path($commentId) => [
            'comment_id' => $commentId,
            'post_id' => $postId,
            'author_uid' => $uid,
            'created_at' => (int)($updated['created_at'] ?? $now),
            'updated_at' => $now,
        ],
    ]);

    $counterOk = true;
    if ($wasPublic) {
        $counterOk = !empty(znews_engagement_adjust_counter(
            $postId,
            'comment_count',
            -1
        )['ok']);
    }

    $formatted = znews_comment_format($updated, true);
    $result = [
        'comment' => $formatted,
        'reconciliation_required' => !$indexOk || !$counterOk,
    ];
    znews_engagement_finish($claim, $result);

    if (!$indexOk || !$counterOk) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_RECONCILIATION_REQUIRED',
            'message' => 'Comment was updated but its indexes require reconciliation.',
            'http_status' => 503,
            'comment' => $formatted,
        ];
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'comment' => $formatted,
    ];
}
