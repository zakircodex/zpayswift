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
$boldRangesProvided = array_key_exists('bold_ranges', $body);
$formattingRunsProvided = array_key_exists('formatting_runs', $body);
$titleProvided = array_key_exists('title', $body);
$categoryProvided = array_key_exists('category', $body);
$category = $categoryProvided
    ? znews_normalize_category($body['category'], false)
    : '';
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
    (string)($body['title'] ?? ''),
    $titleProvided,
    (string)($body['text'] ?? ''),
    $textProvided,
    $body['bold_ranges'] ?? [],
    $boldRangesProvided,
    $body['formatting_runs'] ?? [],
    $formattingRunsProvided,
    $mediaProvided,
    $requestedMediaId,
    $category,
    $categoryProvided,
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
$post = is_array($result['post'] ?? null) ? (array)$result['post'] : [];
$published = strtoupper(trim((string)($post['status'] ?? ''))) === 'ACTIVE';
$message = $replay
    ? 'Post update was already completed.'
    : ($published ? 'Post updated and published.' : 'Post updated and queued for a safety review.');

api_response(
    true,
    $replay ? 'ZNEWS_POST_UPDATE_REPLAY' : 'ZNEWS_POST_UPDATED',
    $message,
    [
        'post' => $post,
        'published_immediately' => $published,
        'requires_review' => !$published,
        'idempotent_replay' => $replay,
    ]
);
