<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/transfers.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();
$currency = znews_transfer_currency($body['currency'] ?? '');
$rateMicrosRaw = $body['bdt_per_unit_micros'] ?? null;
$rateMicros = $rateMicrosRaw !== null && $rateMicrosRaw !== ''
    ? filter_var($rateMicrosRaw, FILTER_VALIDATE_INT)
    : znews_transfer_decimal_to_micros($body['bdt_per_unit'] ?? '', 'rate');
if ($rateMicros === false || $rateMicros <= 0) {
    api_response(false, 'ZNEWS_TRANSFER_RATE_INVALID', 'Valid bdt_per_unit is required.', [], 422);
}
$expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? 0, FILTER_VALIDATE_INT);
if ($expectedUpdatedAt === false || $expectedUpdatedAt < 0) {
    api_response(false, 'ZNEWS_EXPECTED_UPDATED_AT_INVALID', 'expected_updated_at is invalid.', [], 422);
}
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_transfer_admin_update_rate(
    $auth,
    $currency,
    (int)$rateMicros,
    (int)$expectedUpdatedAt,
    $idempotencyKey
);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_TRANSFER_RATE_UPDATE_FAILED'),
    !empty($result['ok']) ? 'Transfer conversion rate updated.' : 'Transfer conversion rate could not be updated.',
    array_filter([
        'rate' => is_array($result['rate'] ?? null) ? (array)$result['rate'] : null,
        'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
        'current_updated_at' => isset($result['current_updated_at']) ? (int)$result['current_updated_at'] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
