<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/transfers.php';

api_require_method('POST');
api_require_app_key();
$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$body = api_read_json_body();
$currency = znews_transfer_currency($body['currency'] ?? $body['source_currency'] ?? '');
$amountMicros = znews_transfer_amount_micros($body);

$result = znews_transfer_quote($uid, $currency, $amountMicros);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_TRANSFER_PREVIEW_FAILED'),
    !empty($result['ok']) ? 'Transfer preview loaded.' : (string)($result['message'] ?? 'Transfer preview could not be loaded.'),
    array_filter([
        'quote' => is_array($result['quote'] ?? null) ? (array)$result['quote'] : null,
        'available_micros' => isset($result['available_micros']) ? (int)$result['available_micros'] : null,
        'bdt_equivalent_micros' => isset($result['bdt_equivalent_micros']) ? (int)$result['bdt_equivalent_micros'] : null,
        'threshold_bdt_micros' => isset($result['threshold_bdt_micros']) ? (int)$result['threshold_bdt_micros'] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
