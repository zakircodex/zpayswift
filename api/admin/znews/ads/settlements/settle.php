<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/settlements.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();

$impressionId = znews_firebase_key($body['impression_id'] ?? '', 'impression_id');
$expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? null, FILTER_VALIDATE_INT);
if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
    api_response(
        false,
        'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED',
        'expected_updated_at is required.',
        [],
        422
    );
}
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_settlement_admin_request(
    $auth,
    $impressionId,
    (int)$expectedUpdatedAt,
    $idempotencyKey
);

api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_SETTLEMENT_FAILED'),
    !empty($result['ok'])
        ? 'Revenue settlement completed.'
        : 'Revenue settlement could not be completed.',
    array_filter([
        'settlement' => is_array($result['settlement'] ?? null)
            ? (array)$result['settlement']
            : null,
        'idempotent_replay' => isset($result['idempotent_replay'])
            ? (bool)$result['idempotent_replay']
            : null,
        'current_updated_at' => isset($result['current_updated_at'])
            ? (int)$result['current_updated_at']
            : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
