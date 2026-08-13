<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views_v2.php';

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

$result = znews_view_complete_v2($viewId, $viewToken);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_VIEW_COMPLETE_FAILED'),
    (string)($result['message'] ?? 'View session could not be completed.'),
    array_filter([
        'result' => isset($result['result']) ? (string)$result['result'] : null,
        'valid_view' => isset($result['valid_view']) ? (bool)$result['valid_view'] : null,
        'earning_eligible' => false,
        'active_seconds' => isset($result['active_seconds']) ? (int)$result['active_seconds'] : null,
        'elapsed_seconds' => isset($result['elapsed_seconds']) ? (int)$result['elapsed_seconds'] : null,
        'analytics' => is_array($result['analytics'] ?? null) ? (array)$result['analytics'] : null,
        'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
        'reconciliation_required' => isset($result['reconciliation_required']) ? (bool)$result['reconciliation_required'] : null,
        'auto_credit' => [
            'status' => 'DISABLED_PERIOD_REVENUE_PAYOUT',
            'processed' => 0,
            'credited' => 0,
            'retry_required' => false,
        ],
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
