<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_comment_create(
    array $auth,
    string $postId,
    string $text,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $postId = znews_firebase_key($postId, 'post_id');
    znews_engagement_require_public_post($postId);

    $claim = znews_engagement_claim(
        $uid,
        $postId,
        'COMMENT_CREATE',
        $idempotencyKey,
        ['text' => $text]
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

    $commentId = znews_comment_id($uid, $idempotencyKey);
    $existing = fb_get(znews_comment_path($postId, $commentId));
    if (is_array($existing)) {
        $formatted = znews_comment_format($existing, true);
        znews_engagement_finish($claim, ['comment' => $formatted]);

        return [
            'ok' => true,
            'idempotent_replay' => true,
            'comment' => $formatted,
        ];
    }
    if ($existing !== null) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_INVALID_RECORD');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_INVALID_RECORD',
            'message' => 'Comment could not be verified.',
            'http_status' => 409,
        ];
    }

    $author = znews_public_creator_snapshot($user);
    $now = znews_now();
    $comment = [
        'schema_version' => 1,
        'comment_id' => $commentId,
        'post_id' => $postId,
        'author_uid' => $uid,
        'author_name' => (string)($author['name'] ?? 'Z-Pay User'),
        'author_photo_url' => (string)($author['profile_photo_url'] ?? ''),
        'text' => $text,
        'status' => 'REVIEW',
        'moderation_status' => 'PENDING',
        'moderation_note' => '',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => 0,
        'source' => 'ZPAY_API',
    ];

    $index = [
        'comment_id' => $commentId,
        'post_id' => $postId,
        'status' => 'REVIEW',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $queue = [
        'comment_id' => $commentId,
        'post_id' => $postId,
        'author_uid' => $uid,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    if (!fb_patch('', [
        znews_comment_path($postId, $commentId) => $comment,
        znews_comment_user_index_path($uid, $commentId) => $index,
        znews_comment_review_queue_path($commentId) => $queue,
    ])) {
        znews_engagement_fail($claim, 'ZNEWS_COMMENT_CREATE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_CREATE_FAILED',
            'message' => 'Comment could not be submitted.',
            'http_status' => 503,
        ];
    }

    $formatted = znews_comment_format($comment, true);
    if (!znews_engagement_finish($claim, ['comment' => $formatted])) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COMMENT_FINALIZE_FAILED',
            'message' => 'Comment was submitted but the request could not be finalized.',
            'http_status' => 503,
            'comment' => $formatted,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_COMMENT_CREATED', $commentId, 'Z News comment submitted', [
            'post_id' => $postId,
            'uid' => $uid,
        ]);
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'comment' => $formatted,
    ];
}
