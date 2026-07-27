<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views.php';

api_require_method('POST');
$body = api_read_json_body();

$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_view_start($postId, $idempotencyKey);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_VIEW_START_FAILED'),
    (string)($result['message'] ?? 'View session could not be started.'),
    array_filter([
        'session' => is_array($result['session'] ?? null) ? (array)$result['session'] : null,
        'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
        'reconciliation_required' => isset($result['reconciliation_required']) ? (bool)$result['reconciliation_required'] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
