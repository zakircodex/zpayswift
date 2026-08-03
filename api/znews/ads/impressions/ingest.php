<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/ad_impressions.php';
require_once dirname(__DIR__, 2) . '/lib/settlements_auto.php';

api_require_method('POST');
$result = znews_ad_ingest();
$autoCredit = null;
$impressionId = trim((string)($result['impression']['impression_id'] ?? ''));
if (!empty($result['ok']) && $impressionId !== '') {
    $autoCredit = znews_auto_settle_impression_with_retry($impressionId);
}
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
        'auto_credit' => is_array($autoCredit) ? [
            'status' => (string)($autoCredit['code'] ?? ''),
            'credited' => !empty($autoCredit['ok']) && empty($autoCredit['skipped']),
            'retry_required' => empty($autoCredit['ok']),
            'retry_queued' => !empty($autoCredit['retry_queued']),
        ] : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
