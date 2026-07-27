<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/ad_impressions.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();

$impressionId = znews_firebase_key($body['impression_id'] ?? '', 'impression_id');
$expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? null, FILTER_VALIDATE_INT);
if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
    api_response(false, 'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED', 'expected_updated_at is required.', [], 422);
}
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_ad_admin_recheck(
    $auth,
    $impressionId,
    (int)$expectedUpdatedAt,
    $idempotencyKey
);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_AD_IMPRESSION_RECHECK_FAILED'),
    !empty($result['ok'])
        ? 'Ad impression rechecked.'
        : 'Ad impression could not be rechecked.',
    array_filter([
        'impression' => is_array($result['impression'] ?? null)
            ? (array)$result['impression']
            : null,
        'idempotent_replay' => isset($result['idempotent_replay'])
            ? (bool)$result['idempotent_replay']
            : null,
        'current_updated_at' => isset($result['current_updated_at'])
            ? (int)$result['current_updated_at']
            : null,
        'reconciliation_required' => isset($result['reconciliation_required'])
            ? (bool)$result['reconciliation_required']
            : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
