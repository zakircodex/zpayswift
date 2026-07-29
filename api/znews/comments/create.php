<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/comments.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();
$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$text = znews_comment_text($body['text'] ?? '');
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_comment_create($auth, $postId, $text, $idempotencyKey);
if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_COMMENT_CREATE_FAILED'),
        (string)($result['message'] ?? 'Comment could not be submitted.'),
        [
            'comment' => is_array($result['comment'] ?? null) ? (array)$result['comment'] : [],
            'counts' => is_array($result['counts'] ?? null) ? (array)$result['counts'] : [],
        ],
        (int)($result['http_status'] ?? 500)
    );
}

$replay = !empty($result['idempotent_replay']);
$comment = is_array($result['comment'] ?? null) ? (array)$result['comment'] : [];
$published = znews_comment_is_public($comment);
$message = $replay
    ? 'Comment was already created.'
    : ($published ? 'Comment published.' : 'Comment requires a safety review.');

api_response(
    true,
    $replay ? 'ZNEWS_COMMENT_ALREADY_CREATED' : 'ZNEWS_COMMENT_CREATED',
    $message,
    [
        'comment' => $comment,
        'counts' => is_array($result['counts'] ?? null) ? (array)$result['counts'] : [],
        'published_immediately' => $published,
        'requires_review' => !$published,
        'idempotent_replay' => $replay,
    ],
    $replay ? 200 : 201
);
