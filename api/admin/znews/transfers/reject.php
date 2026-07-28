<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/transfers.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();
$requestId = znews_firebase_key($body['request_id'] ?? '', 'request_id');
$expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? null, FILTER_VALIDATE_INT);
if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
    api_response(false, 'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED', 'expected_updated_at is required.', [], 422);
}
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);
$reason = znews_transfer_rejection_reason($body['reason'] ?? '');

$result = znews_transfer_admin_reject(
    $auth,
    $requestId,
    (int)$expectedUpdatedAt,
    $idempotencyKey,
    $reason
);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_TRANSFER_REJECTION_FAILED'),
    !empty($result['ok']) ? 'Transfer rejected.' : 'Transfer could not be rejected.',
    array_filter([
        'request' => is_array($result['request'] ?? null) ? (array)$result['request'] : null,
        'balance' => is_array($result['balance'] ?? null) ? (array)$result['balance'] : null,
        'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
        'current_updated_at' => isset($result['current_updated_at']) ? (int)$result['current_updated_at'] : null,
        'reconciliation_required' => isset($result['reconciliation_required']) ? (bool)$result['reconciliation_required'] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
