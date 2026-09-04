<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/post_media_attach.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();

$content = znews_post_validate_content(
    $body['text'] ?? '',
    $body['media_id'] ?? $body['image_media_id'] ?? ''
);
$boldRanges = znews_validate_post_bold_ranges(
    $body['bold_ranges'] ?? [],
    (string)$content['text']
);
$formattingRuns = array_key_exists('formatting_runs', $body)
    ? znews_validate_post_formatting_runs($body['formatting_runs'], (string)$content['text'])
    : znews_post_formatting_runs_from_bold_ranges($boldRanges, (string)$content['text']);
$boldRanges = znews_post_bold_ranges_from_formatting_runs($formattingRuns, (string)$content['text']);
$title = znews_post_validate_title($body['title'] ?? '');
$category = array_key_exists('category', $body)
    ? znews_normalize_category($body['category'], false)
    : '';
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_create_post_with_media(
    $auth,
    $title,
    (string)$content['text'],
    $boldRanges,
    $formattingRuns,
    (string)$content['media_id'],
    (string)$content['content_type'],
    $category,
    $idempotencyKey
);

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
$post = is_array($result['post'] ?? null) ? (array)$result['post'] : [];
$published = strtoupper(trim((string)($post['status'] ?? ''))) === 'ACTIVE';
$message = $replay
    ? 'Post was already created.'
    : ($published ? 'Post published.' : 'Post requires a safety review.');

api_response(
    true,
    $replay ? 'ZNEWS_POST_ALREADY_CREATED' : 'ZNEWS_POST_CREATED',
    $message,
    [
        'post' => $post,
        'published_immediately' => $published,
        'requires_review' => !$published,
        'idempotent_replay' => $replay,
    ],
    $replay ? 200 : 201
);
