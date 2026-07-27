<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/shares.php';

api_require_method('POST');
api_require_app_key();

$auth = znews_require_creator(true);
$body = api_read_json_body();
$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$channel = znews_share_channel($body['channel'] ?? 'OTHER');
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_record_share($auth, $postId, $channel, $idempotencyKey);
if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_SHARE_FAILED'),
        (string)($result['message'] ?? 'Share could not be recorded.'),
        array_filter([
            'share_id' => $result['share_id'] ?? null,
            'counts' => is_array($result['counts'] ?? null) ? (array)$result['counts'] : null,
        ], static fn($value) => $value !== null),
        (int)($result['http_status'] ?? 500)
    );
}

api_response(true, 'ZNEWS_SHARE_RECORDED', 'Share recorded.', [
    'share_id' => (string)($result['share_id'] ?? ''),
    'channel' => (string)($result['channel'] ?? $channel),
    'counts' => is_array($result['counts'] ?? null) ? (array)$result['counts'] : [],
    'idempotent_replay' => !empty($result['idempotent_replay']),
]);
