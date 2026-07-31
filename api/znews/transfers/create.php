<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/transfers.php';

api_require_method('POST');
api_require_app_key();
$auth = znews_require_creator(true);
$body = api_read_json_body();
$currency = znews_transfer_currency($body['currency'] ?? $body['source_currency'] ?? '');
$amountMicros = znews_transfer_amount_micros($body);
$idempotencyKey = znews_idempotency_key(
    $body['idempotency_key']
    ?? $body['client_request_id']
    ?? ''
);

$result = znews_transfer_create($auth, $currency, $amountMicros, $idempotencyKey);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_TRANSFER_REQUEST_FAILED'),
    !empty($result['ok']) ? 'Transfer request submitted.' : (string)($result['message'] ?? 'Transfer request could not be submitted.'),
    array_filter([
        'request' => is_array($result['request'] ?? null) ? (array)$result['request'] : null,
        'balance' => is_array($result['balance'] ?? null) ? array_merge((array)$result['balance'], [
            'main_wallet_transfer_enabled' => true,
            'minimum_bdt_micros' => znews_transfer_threshold_bdt_micros(),
            'minimum_bdt' => '200',
        ]) : null,
        'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
        'reconciliation_required' => isset($result['reconciliation_required']) ? (bool)$result['reconciliation_required'] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
