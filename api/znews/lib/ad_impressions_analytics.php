<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/ad_impressions_common.php';

function znews_ad_analytics_defaults(string $postId): array
{
    return [
        'post_id' => $postId,
        'received_impressions' => 0,
        'verified_impressions' => 0,
        'pending_view_impressions' => 0,
        'review_impressions' => 0,
        'rejected_impressions' => 0,
        'verified_revenue_micros_by_currency' => [],
        'event_states' => [],
        'updated_at' => 0,
    ];
}

function znews_ad_analytics_format(array $row): array
{
    $revenue = [];
    foreach ((array)($row['verified_revenue_micros_by_currency'] ?? []) as $currency => $amount) {
        $currency = strtoupper(trim((string)$currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            continue;
        }
        $revenue[$currency] = max(0, (int)$amount);
    }
    ksort($revenue);
    return [
        'post_id' => trim((string)($row['post_id'] ?? '')),
        'received_impressions' => max(0, (int)($row['received_impressions'] ?? 0)),
        'verified_impressions' => max(0, (int)($row['verified_impressions'] ?? 0)),
        'pending_view_impressions' => max(0, (int)($row['pending_view_impressions'] ?? 0)),
        'review_impressions' => max(0, (int)($row['review_impressions'] ?? 0)),
        'rejected_impressions' => max(0, (int)($row['rejected_impressions'] ?? 0)),
        'reported_verified_revenue_micros_by_currency' => $revenue,
        'settled_revenue_micros_by_currency' => [],
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
    ];
}

function znews_ad_analytics_status_field(string $status): string
{
    return match (strtoupper(trim($status))) {
        'VERIFIED' => 'verified_impressions',
        'PENDING_VIEW' => 'pending_view_impressions',
        'REVIEW' => 'review_impressions',
        'REJECTED' => 'rejected_impressions',
        default => '',
    };
}

function znews_ad_analytics_transition(
    string $postId,
    string $impressionId,
    string $newStatus,
    string $currency,
    int $revenueMicros
): array {
    $postId = znews_firebase_key($postId, 'post_id');
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $newStatus = strtoupper(trim($newStatus));
    $newField = znews_ad_analytics_status_field($newStatus);
    if ($newField === '') {
        return ['ok' => false, 'code' => 'ZNEWS_AD_ANALYTICS_STATUS_INVALID'];
    }
    $currency = znews_ad_currency($currency);
    $eventKey = hash('sha256', $impressionId);
    $path = znews_ad_analytics_path($postId);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_ANALYTICS_READ_FAILED'];
        }
        $row = is_array($snapshot['value'] ?? null)
            ? (array)$snapshot['value']
            : znews_ad_analytics_defaults($postId);
        foreach ([
            'received_impressions',
            'verified_impressions',
            'pending_view_impressions',
            'review_impressions',
            'rejected_impressions',
        ] as $field) {
            $row[$field] = max(0, (int)($row[$field] ?? 0));
        }
        $states = is_array($row['event_states'] ?? null)
            ? (array)$row['event_states']
            : [];
        $old = is_array($states[$eventKey] ?? null) ? (array)$states[$eventKey] : [];
        $oldStatus = strtoupper(trim((string)($old['status'] ?? '')));
        if ($oldStatus === $newStatus) {
            return [
                'ok' => true,
                'idempotent_replay' => true,
                'analytics' => znews_ad_analytics_format($row),
            ];
        }

        if ($oldStatus === '') {
            $row['received_impressions']++;
        } else {
            $oldField = znews_ad_analytics_status_field($oldStatus);
            if ($oldField !== '') {
                $row[$oldField] = max(0, (int)$row[$oldField] - 1);
            }
            if ($oldStatus === 'VERIFIED') {
                $oldCurrency = strtoupper(trim((string)($old['currency'] ?? '')));
                $oldRevenue = max(0, (int)($old['revenue_micros'] ?? 0));
                $map = is_array($row['verified_revenue_micros_by_currency'] ?? null)
                    ? (array)$row['verified_revenue_micros_by_currency']
                    : [];
                if ($oldCurrency !== '') {
                    $map[$oldCurrency] = max(0, (int)($map[$oldCurrency] ?? 0) - $oldRevenue);
                }
                $row['verified_revenue_micros_by_currency'] = $map;
            }
        }

        $row[$newField]++;
        if ($newStatus === 'VERIFIED') {
            $map = is_array($row['verified_revenue_micros_by_currency'] ?? null)
                ? (array)$row['verified_revenue_micros_by_currency']
                : [];
            $map[$currency] = max(0, (int)($map[$currency] ?? 0)) + max(0, $revenueMicros);
            $row['verified_revenue_micros_by_currency'] = $map;
        }
        $states[$eventKey] = [
            'status' => $newStatus,
            'currency' => $currency,
            'revenue_micros' => max(0, $revenueMicros),
            'updated_at' => znews_now(),
        ];
        $row['event_states'] = $states;
        $row['post_id'] = $postId;
        $row['updated_at'] = znews_now();

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(60000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_ANALYTICS_WRITE_FAILED'];
        }
        return [
            'ok' => true,
            'idempotent_replay' => false,
            'analytics' => znews_ad_analytics_format($row),
        ];
    }
    return ['ok' => false, 'code' => 'ZNEWS_AD_ANALYTICS_BUSY'];
}

function znews_ad_analytics_get(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $row = fb_get(znews_ad_analytics_path($postId));
    return znews_ad_analytics_format(
        is_array($row) ? $row : znews_ad_analytics_defaults($postId)
    );
}
