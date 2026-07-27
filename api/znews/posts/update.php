<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/post_media_attach.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();

$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$expectedUpdatedAt = filter_var(
    $body['expected_updated_at'] ?? null,
    FILTER_VALIDATE_INT
);
if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
    api_response(
        false,
        'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED',
        'expected_updated_at is required.',
        [],
        422
    );
}

$textProvided = array_key_exists('text', $body);
$mediaProvided = array_key_exists('media_id', $body)
    || array_key_exists('image_media_id', $body);
$requestedMediaId = $mediaProvided
    ? trim((string)($body['media_id'] ?? $body['image_media_id'] ?? ''))
    : '';

$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_update_post_with_media(
    $auth,
    $postId,
    (string)($body['text'] ?? ''),
    $textProvided,
    $mediaProvided,
    $requestedMediaId,
    (int)$expectedUpdatedAt,
    $idempotencyKey
);

if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_POST_UPDATE_FAILED'),
        (string)($result['message'] ?? 'Post could not be updated.'),
        array_filter([
            'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : null,
            'details' => is_array($result['data'] ?? null) ? (array)$result['data'] : null,
        ], static fn($value) => $value !== null),
        (int)($result['http_status'] ?? 500)
    );
}

$replay = !empty($result['idempotent_replay']);
api_response(
    true,
    $replay ? 'ZNEWS_POST_UPDATE_REPLAY' : 'ZNEWS_POST_UPDATED',
    $replay ? 'Post update was already completed.' : 'Post updated and submitted for review.',
    [
        'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : [],
        'idempotent_replay' => $replay,
    ]
);
