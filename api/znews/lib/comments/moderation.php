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

    if (strtoupper(trim((string)($comment['status'] ?? ''))) !== 'REVIEW'
        || strtoupper(trim((string)($comment['moderation_status'] ?? ''))) !== 'PENDING'
        || (int)($comment['deleted_at'] ?? 0) > 0) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_NOT_PENDING_REVIEW');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_NOT_PENDING_REVIEW',
            'message' => 'This comment is not pending moderation.',
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
        ],
        znews_comment_action_path($commentId, $actionId) => [
            'action_id' => $actionId,
            'comment_id' => $commentId,
            'post_id' => $postId,
            'action' => $action,
            'admin_uid' => $adminUid,
            'admin_name' => $adminName,
            'note' => $note,
            'created_at' => $now,
        ],
    ]);

    $counterOk = true;
    if ($approve) {
        $counterOk = !empty(znews_engagement_adjust_counter(
            $postId,
            'comment_count',
            1
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
            'message' => 'Moderation was saved but comment indexes require reconciliation.',
            'http_status' => 503,
            'comment' => $formatted,
        ];
    }

    if (function_exists('system_log')) {
        system_log(
            $approve ? 'ZNEWS_COMMENT_APPROVED' : 'ZNEWS_COMMENT_REJECTED',
            $commentId,
            'Z News comment moderation decision',
            [
                'post_id' => $postId,
                'comment_id' => $commentId,
                'admin_uid' => $adminUid,
            ]
        );
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'comment' => $formatted,
    ];
}
