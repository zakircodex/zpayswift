<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();

$text = znews_validate_post_text($body['text'] ?? '', 1, 5000);
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_create_text_post($auth, $text, $idempotencyKey);
if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_POST_CREATE_FAILED'),
        (string)($result['message'] ?? 'Post could not be created.'),
        is_array($result['data'] ?? null) ? (array)$result['data'] : [],
        (int)($result['http_status'] ?? 500)
    );
}

$replay = !empty($result['idempotent_replay']);
api_response(
    true,
    $replay ? 'ZNEWS_POST_ALREADY_CREATED' : 'ZNEWS_POST_CREATED',
    $replay ? 'Post was already created.' : 'Post submitted for review.',
    [
        'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : [],
        'idempotent_replay' => $replay,
    ],
    $replay ? 200 : 201
);
