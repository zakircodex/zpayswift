<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/ad_impressions.php';

api_require_method('POST');
$result = znews_ad_ingest();
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? 'ZNEWS_AD_IMPRESSION_INGEST_FAILED'),
    (string)($result['message'] ?? 'Ad impression could not be ingested.'),
    array_filter([
        'impression' => is_array($result['impression'] ?? null)
            ? (array)$result['impression']
            : null,
        'idempotent_replay' => isset($result['idempotent_replay'])
            ? (bool)$result['idempotent_replay']
            : null,
        'reconciliation_required' => isset($result['reconciliation_required'])
            ? (bool)$result['reconciliation_required']
            : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
