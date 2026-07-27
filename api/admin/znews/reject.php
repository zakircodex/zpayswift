<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 2) . '/znews/lib/moderation.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();

$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$expectedUpdatedAt = (int)($body['expected_updated_at'] ?? 0);
$idempotencyKey = znews_idempotency_key($body['idempotency_key'] ?? $body['client_request_id'] ?? '');
$copyrightVerdict = znews_copyright_verdict($body['copyright_verdict'] ?? '', false);
$reason = znews_moderation_note($body['reason'] ?? $body['note'] ?? '', true, 500);

$result = znews_admin_moderate_post(
    $auth,
    $postId,
    $expectedUpdatedAt,
    $idempotencyKey,
    'REJECT',
    $copyrightVerdict,
    $reason
);

if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_REJECT_FAILED'),
        (string)($result['message'] ?? 'Post could not be rejected.'),
        array_filter([
            'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : null,
            'current_updated_at' => $result['data']['current_updated_at'] ?? null,
        ], static fn($value) => $value !== null),
        (int)($result['http_status'] ?? 500)
    );
}

$replay = !empty($result['idempotent_replay']);
api_response(true, $replay ? 'ZNEWS_POST_ALREADY_REJECTED' : 'ZNEWS_POST_REJECTED', $replay ? 'Post was already rejected.' : 'Post rejected.', [
    'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : [],
    'idempotent_replay' => $replay,
]);
