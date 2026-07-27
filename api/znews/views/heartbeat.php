<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views.php';

api_require_method('POST');
$body = api_read_json_body();

$viewId = znews_firebase_key($body['view_id'] ?? '', 'view_id');
$viewToken = trim((string)(
    $body['view_token']
    ?? api_get_header('X-ZNEWS-VIEW-TOKEN')
    ?? ''
));
if ($viewToken === '' || strlen($viewToken) > 160) {
    api_response(false, 'ZNEWS_VIEW_TOKEN_REQUIRED', 'view_token is required.', [], 422);
}

$result = znews_view_heartbeat($viewId, $viewToken);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_VIEW_HEARTBEAT_FAILED'),
    (string)($result['message'] ?? 'Heartbeat could not be saved.'),
    array_filter([
        'accepted' => isset($result['accepted']) ? (bool)$result['accepted'] : null,
        'active_seconds' => isset($result['active_seconds']) ? (int)$result['active_seconds'] : null,
        'heartbeat_count' => isset($result['heartbeat_count']) ? (int)$result['heartbeat_count'] : null,
        'retry_after_seconds' => isset($result['retry_after_seconds']) ? (int)$result['retry_after_seconds'] : null,
        'server_time' => isset($result['server_time']) ? (int)$result['server_time'] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
