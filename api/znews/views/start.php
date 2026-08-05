<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views_v2.php';
require_once dirname(__DIR__) . '/lib/creator_view_policy.php';

api_require_method('POST');
$body = api_read_json_body();

$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);
$viewerUid = znews_optional_creator_uid();

$result = znews_view_start_v2($postId, $idempotencyKey, $viewerUid);
$viewGate = [
    'viewer_class' => $viewerUid !== '' ? 'CREATOR' : 'GUEST',
    'ad_eligible' => false,
    'revenue_share_eligible' => false,
    'spam' => false,
    'count' => 0,
    'limit' => znews_guest_view_window_limit(),
    'window_seconds' => znews_guest_view_window_seconds(),
    'next_allowed_at' => 0,
    'reason' => 'VIEW_SESSION_NOT_STARTED',
    'idempotent_replay' => false,
];
if (is_array($result['session'] ?? null)) {
    $viewGate = znews_creator_view_gate($viewerUid, $postId, $idempotencyKey);
    $result = znews_creator_view_policy_apply($result, $viewGate);
}

api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_VIEW_START_FAILED'),
    (string)($result['message'] ?? 'View session could not be started.'),
    array_filter([
        'session' => is_array($result['session'] ?? null) ? (array)$result['session'] : null,
        'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
        'reconciliation_required' => isset($result['reconciliation_required']) ? (bool)$result['reconciliation_required'] : null,
        'ad_policy' => [
            'viewer_class' => (string)($viewGate['viewer_class'] ?? 'GUEST'),
            'ad_eligible' => !empty($viewGate['ad_eligible']),
            'spam' => !empty($viewGate['spam']),
            'window_count' => max(0, (int)($viewGate['count'] ?? 0)),
            'window_limit' => max(1, (int)($viewGate['limit'] ?? 3)),
            'window_seconds' => max(1, (int)($viewGate['window_seconds'] ?? 300)),
            'next_allowed_at' => max(0, (int)($viewGate['next_allowed_at'] ?? 0)),
            'reason' => trim((string)($viewGate['reason'] ?? '')),
            'idempotent_replay' => !empty($viewGate['idempotent_replay']),
        ],
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
