<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/likes.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();
$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');

if (!array_key_exists('liked', $body)) {
    api_response(false, 'ZNEWS_LIKED_REQUIRED', 'liked is required.', [], 422);
}
$rawLiked = $body['liked'];
if (is_bool($rawLiked)) {
    $liked = $rawLiked;
} else {
    $normalized = strtoupper(trim((string)$rawLiked));
    if (!in_array($normalized, ['1', '0', 'TRUE', 'FALSE', 'YES', 'NO'], true)) {
        api_response(false, 'ZNEWS_LIKED_INVALID', 'liked must be true or false.', [], 422);
    }
    $liked = in_array($normalized, ['1', 'TRUE', 'YES'], true);
}

$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_like_set($auth, $postId, $liked, $idempotencyKey);
if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_LIKE_FAILED'),
        (string)($result['message'] ?? 'Like status could not be saved.'),
        array_filter([
            'liked' => array_key_exists('liked', $result) ? (bool)$result['liked'] : null,
            'counts' => is_array($result['counts'] ?? null) ? (array)$result['counts'] : null,
        ], static fn($value) => $value !== null),
        (int)($result['http_status'] ?? 500)
    );
}

api_response(true, $liked ? 'ZNEWS_POST_LIKED' : 'ZNEWS_POST_UNLIKED', $liked ? 'Post liked.' : 'Post unliked.', [
    'liked' => (bool)$result['liked'],
    'counts' => is_array($result['counts'] ?? null) ? (array)$result['counts'] : [],
    'idempotent_replay' => !empty($result['idempotent_replay']),
]);
