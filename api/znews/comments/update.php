<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/comments.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();
$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$commentId = znews_firebase_key($body['comment_id'] ?? '', 'comment_id');
$text = znews_comment_text($body['text'] ?? '');
$expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? null, FILTER_VALIDATE_INT);
if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
    api_response(false, 'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED', 'expected_updated_at is required.', [], 422);
}
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_comment_update(
    $auth,
    $postId,
    $commentId,
    $text,
    (int)$expectedUpdatedAt,
    $idempotencyKey
);
if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_COMMENT_UPDATE_FAILED'),
        (string)($result['message'] ?? 'Comment could not be updated.'),
        array_filter([
            'comment' => is_array($result['comment'] ?? null) ? (array)$result['comment'] : null,
            'details' => is_array($result['data'] ?? null) ? (array)$result['data'] : null,
        ], static fn($value) => $value !== null),
        (int)($result['http_status'] ?? 500)
    );
}

api_response(true, 'ZNEWS_COMMENT_UPDATED', 'Comment updated and submitted for review.', [
    'comment' => is_array($result['comment'] ?? null) ? (array)$result['comment'] : [],
    'idempotent_replay' => !empty($result['idempotent_replay']),
]);
