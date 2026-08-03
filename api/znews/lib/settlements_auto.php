<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements_service.php';

function znews_auto_settlement_network_allowed(string $network): bool
{
    return znews_ad_network_name($network) === 'INMOBI';
}

function znews_auto_settlement_actor(): array
{
    return [
        'user' => [
            'uid' => 'SYSTEM_ZSKY24_AUTO',
            'name' => 'Z Sky 24 automatic settlement',
        ],
    ];
}

function znews_auto_settlement_retry_path(string $impressionId): string
{
    return 'ZNEWS_AUTO_SETTLEMENT_RETRIES/'
        . znews_firebase_key($impressionId, 'impression_id');
}

function znews_auto_settlement_retry_delay(int $attempt): int
{
    return min(3600, 30 * (2 ** min(7, max(0, $attempt - 1))));
}

function znews_auto_settlement_retryable(array $result): bool
{
    $status = (int)($result['http_status'] ?? 500);
    $code = strtoupper(trim((string)($result['code'] ?? '')));
    if ($status >= 500 || $status === 429) {
        return true;
    }

    return in_array($code, [
        'ZNEWS_SETTLEMENT_VERSION_CONFLICT',
        'ZNEWS_SETTLEMENT_IN_PROGRESS',
        'ZNEWS_SETTLEMENT_FINALIZE_CONFLICT',
    ], true);
}

function znews_auto_settlement_queue_retry(string $impressionId, array $result): bool
{
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $path = znews_auto_settlement_retry_path($impressionId);
    $existing = fb_get($path);
    $attempt = max(0, (int)(is_array($existing) ? ($existing['attempt_count'] ?? 0) : 0)) + 1;
    $now = now_ts();

    return fb_put($path, [
        'impression_id' => $impressionId,
        'status' => $attempt >= 12 ? 'FAILED' : 'PENDING',
        'attempt_count' => $attempt,
        'last_error_code' => substr(trim((string)($result['code'] ?? 'ZNEWS_AUTO_SETTLEMENT_FAILED')), 0, 120),
        'created_at' => max(1, (int)(is_array($existing) ? ($existing['created_at'] ?? $now) : $now)),
        'updated_at' => $now,
        'next_attempt_at' => $attempt >= 12 ? 0 : $now + znews_auto_settlement_retry_delay($attempt),
    ]);
}

function znews_auto_settle_impression_with_retry(string $impressionId): array
{
    $result = znews_auto_settle_impression($impressionId);
    if (!empty($result['ok']) || !znews_auto_settlement_retryable($result)) {
        fb_delete(znews_auto_settlement_retry_path($impressionId));
    } else {
        $result['retry_queued'] = znews_auto_settlement_queue_retry($impressionId, $result);
    }

    return $result;
}

function znews_auto_settle_impression(string $impressionId): array
{
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $row = fb_get(znews_ad_impression_path($impressionId));
    if (!is_array($row)) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_NOT_FOUND', 'http_status' => 404];
    }
    if (!znews_auto_settlement_network_allowed((string)($row['network'] ?? ''))) {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_NETWORK_NOT_PAYABLE', 'skipped' => true];
    }
    if (strtoupper(trim((string)($row['status'] ?? ''))) !== 'VERIFIED'
        || strtoupper(trim((string)($row['verification_status'] ?? ''))) !== 'VERIFIED') {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_NOT_VERIFIED', 'skipped' => true];
    }
    if (!empty($row['self_view']) || in_array('SELF_VIEW', array_map('strval', (array)($row['risk_reasons'] ?? [])), true)) {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_SELF_VIEW_REJECTED', 'skipped' => true];
    }

    return znews_settle_impression(
        znews_auto_settlement_actor(),
        $impressionId,
        (int)($row['updated_at'] ?? 0)
    );
}

function znews_auto_settle_view_impressions(string $viewId): array
{
    $viewId = znews_firebase_key($viewId, 'view_id');
    $index = fb_get('ZNEWS_VIEW_AD_IMPRESSIONS/' . $viewId);
    if (!is_array($index)) {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_NO_IMPRESSIONS', 'processed' => 0, 'credited' => 0];
    }

    $processed = 0;
    $credited = 0;
    $retryRequired = false;
    foreach (array_keys($index) as $impressionIdRaw) {
        if ($processed >= znews_ad_max_per_view()) {
            break;
        }
        $impressionId = znews_firebase_key((string)$impressionIdRaw, 'impression_id');
        $row = fb_get(znews_ad_impression_path($impressionId));
        if (!is_array($row)) {
            continue;
        }
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if (in_array($status, ['PENDING_VIEW', 'REVIEW'], true)) {
            $recheck = znews_ad_recheck_impression($impressionId, null);
            if (empty($recheck['ok'])) {
                $retryRequired = true;
                $processed++;
                continue;
            }
        }
        $settled = znews_auto_settle_impression_with_retry($impressionId);
        if (empty($settled['ok'])) {
            $retryRequired = true;
        } elseif (empty($settled['skipped'])) {
            $credited++;
        }
        $processed++;
    }

    return [
        'ok' => !$retryRequired,
        'code' => $retryRequired ? 'ZNEWS_AUTO_SETTLEMENT_RETRY_REQUIRED' : 'ZNEWS_AUTO_SETTLEMENT_VIEW_PROCESSED',
        'processed' => $processed,
        'credited' => $credited,
        'retry_required' => $retryRequired,
    ];
}
