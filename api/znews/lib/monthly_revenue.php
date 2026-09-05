<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/adsterra_publisher.php';

function znews_monthly_revenue_sync_path(string $monthId): string
{
    return 'ZNEWS_ADSTERRA_MONTHLY_SYNC/' . znews_firebase_key($monthId, 'month_id');
}

function znews_monthly_revenue_lock_path(string $monthId): string
{
    return 'ZNEWS_ADSTERRA_REVENUE_LOCKS/' . znews_firebase_key($monthId, 'month_id');
}

function znews_monthly_mul_div(int $value, int $multiplier, int $divisor): ?array
{
    if ($value < 0 || $multiplier < 0 || $divisor <= 0) {
        return null;
    }

    $quotient = 0;
    $remainder = 0;
    $termQuotient = intdiv($value, $divisor);
    $termRemainder = $value % $divisor;
    $remainingMultiplier = $multiplier;

    while ($remainingMultiplier > 0) {
        if (($remainingMultiplier & 1) === 1) {
            if ($quotient > PHP_INT_MAX - $termQuotient) {
                return null;
            }
            $quotient += $termQuotient;
            if ($termRemainder > 0 && $remainder >= $divisor - $termRemainder) {
                if ($quotient === PHP_INT_MAX) {
                    return null;
                }
                $remainder -= $divisor - $termRemainder;
                $quotient++;
            } else {
                $remainder += $termRemainder;
            }
        }

        $remainingMultiplier = intdiv($remainingMultiplier, 2);
        if ($remainingMultiplier === 0) {
            break;
        }
        if ($termQuotient > intdiv(PHP_INT_MAX, 2)) {
            return null;
        }
        $termQuotient *= 2;
        if ($termRemainder > 0 && $termRemainder >= $divisor - $termRemainder) {
            if ($termQuotient === PHP_INT_MAX) {
                return null;
            }
            $termRemainder -= $divisor - $termRemainder;
            $termQuotient++;
        } else {
            $termRemainder += $termRemainder;
        }
    }

    return ['quotient' => $quotient, 'remainder' => $remainder];
}

function znews_monthly_revenue_formula(int $grossMicros): array
{
    $grossMicros = max(0, $grossMicros);
    $reserve = (int)(znews_monthly_mul_div($grossMicros, 1000, 10000)['quotient'] ?? 0);
    $distributable = $grossMicros - $reserve;
    $creatorPool = (int)(znews_monthly_mul_div($distributable, 4000, 10000)['quotient'] ?? 0);
    $platformShare = $distributable - $creatorPool;

    return [
        'gross_settled_usd_micros' => $grossMicros,
        'safety_reserve_usd_micros' => $reserve,
        'distributable_usd_micros' => $distributable,
        'creator_pool_usd_micros' => $creatorPool,
        'platform_share_usd_micros' => $platformShare,
        'gross_settled_usd' => znews_adsterra_decimal($grossMicros),
        'safety_reserve_usd' => znews_adsterra_decimal($reserve),
        'distributable_usd' => znews_adsterra_decimal($distributable),
        'creator_pool_usd' => znews_adsterra_decimal($creatorPool),
        'platform_share_usd' => znews_adsterra_decimal($platformShare),
        'safety_reserve_bps' => 1000,
        'creator_share_of_distributable_bps' => 4000,
        'platform_share_of_distributable_bps' => 6000,
        'creator_effective_gross_bps' => 3600,
        'platform_effective_gross_bps' => 5400,
    ];
}

function znews_monthly_revenue_public_row(array $row): array
{
    if ($row === []) {
        return [];
    }
    $gross = max(0, (int)($row['gross_settled_usd_micros'] ?? $row['revenue_usd_micros'] ?? 0));
    return array_merge([
        'provider' => 'ADSTERRA',
        'currency' => 'USD',
        'month_id' => trim((string)($row['month_id'] ?? '')),
        'status' => strtoupper(trim((string)($row['status'] ?? 'UNAVAILABLE'))),
        'source_status' => strtoupper(trim((string)($row['source_status'] ?? 'UNAVAILABLE'))),
        'provider_reported_at' => max(0, (int)($row['provider_reported_at'] ?? 0)),
        'synced_at' => max(0, (int)($row['synced_at'] ?? 0)),
        'locked_at' => max(0, (int)($row['locked_at'] ?? 0)),
        'sync_id' => trim((string)($row['sync_id'] ?? '')),
    ], znews_monthly_revenue_formula($gross));
}

function znews_monthly_revenue_status(string $monthId = ''): array
{
    $month = znews_monthly_performance_month($monthId);
    if (empty($month['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_PERIOD_INVALID', 'http_status' => 422];
    }
    $monthId = (string)$month['month_id'];
    $sync = fb_get(znews_monthly_revenue_sync_path($monthId));
    $lock = fb_get(znews_monthly_revenue_lock_path($monthId));
    return [
        'ok' => true,
        'code' => 'ZNEWS_MONTHLY_REVENUE_STATUS_OK',
        'month' => $month,
        'sync' => is_array($sync) ? znews_monthly_revenue_public_row($sync) : [],
        'lock' => is_array($lock) ? znews_monthly_revenue_public_row($lock) : [],
        'provider_configured' => znews_adsterra_token() !== '' && znews_adsterra_domain_id() !== '',
    ];
}

function znews_monthly_revenue_sync(string $monthId, string $adminUid, ?callable $transport = null): array
{
    $month = znews_monthly_performance_month($monthId);
    if (empty($month['ok']) || !empty($month['upcoming'])) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_PERIOD_INVALID', 'http_status' => 422];
    }
    $monthId = (string)$month['month_id'];
    if (is_array(fb_get(znews_monthly_revenue_lock_path($monthId)))) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_ALREADY_LOCKED', 'http_status' => 409];
    }

    $finishDate = !empty($month['completed'])
        ? (string)$month['month_end_date']
        : min(gmdate('Y-m-d'), (string)$month['month_end_date']);
    $report = znews_adsterra_fetch_stats(
        (string)$month['month_start_date'],
        $finishDate,
        empty($month['completed']),
        $transport
    );
    if (empty($report['ok'])) {
        return $report;
    }

    $now = znews_now();
    $sourceStatus = !empty($month['completed']) ? 'FINAL_SYNCED' : 'ESTIMATED';
    $syncId = 'ZRS' . strtoupper(substr(hash(
        'sha256',
        implode('|', [$monthId, (string)$report['revenue_usd_micros'], (string)$report['reported_at'], $sourceStatus])
    ), 0, 29));
    $row = [
        'schema_version' => 1,
        'provider' => 'ADSTERRA',
        'currency' => 'USD',
        'month_id' => $monthId,
        'status' => $sourceStatus === 'ESTIMATED' ? 'ESTIMATED' : 'SYNCED',
        'source_status' => $sourceStatus,
        'sync_id' => $syncId,
        'revenue_usd_micros' => (int)$report['revenue_usd_micros'],
        'impressions' => (int)$report['impressions'],
        'clicks' => (int)$report['clicks'],
        'report_rows' => (array)$report['rows'],
        'report_row_count' => (int)$report['row_count'],
        'provider_domain_id' => (string)$report['domain_id'],
        'provider_reported_at' => (int)$report['reported_at'],
        'report_start_date' => (string)$report['start_date'],
        'report_finish_date' => (string)$report['finish_date'],
        'cache_hit' => !empty($report['cache_hit']),
        'synced_at' => $now,
        'synced_by' => znews_firebase_key($adminUid, 'admin_uid'),
    ];
    if (!fb_put(znews_monthly_revenue_sync_path($monthId), $row)) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_SYNC_SAVE_FAILED', 'http_status' => 503];
    }
    return [
        'ok' => true,
        'code' => 'ZNEWS_ADSTERRA_REVENUE_SYNCED',
        'month' => $month,
        'sync' => znews_monthly_revenue_public_row($row),
    ];
}

function znews_monthly_revenue_lock(string $monthId, string $syncId, string $adminUid): array
{
    $month = znews_monthly_performance_month($monthId);
    if (empty($month['ok']) || empty($month['completed'])) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_MONTH_OPEN', 'http_status' => 409];
    }
    $monthId = (string)$month['month_id'];
    $sync = fb_get(znews_monthly_revenue_sync_path($monthId));
    if (!is_array($sync)
        || strtoupper((string)($sync['source_status'] ?? '')) !== 'FINAL_SYNCED'
        || $syncId === ''
        || !hash_equals((string)($sync['sync_id'] ?? ''), $syncId)) {
        return ['ok' => false, 'code' => 'ZNEWS_FINAL_REVENUE_SYNC_REQUIRED', 'http_status' => 409];
    }

    $path = znews_monthly_revenue_lock_path($monthId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_LOCK_UNAVAILABLE', 'http_status' => 503];
    }
    $existing = $snapshot['value'] ?? null;
    if (is_array($existing)) {
        return [
            'ok' => true,
            'code' => 'ZNEWS_REVENUE_ALREADY_LOCKED',
            'idempotent_replay' => true,
            'lock' => znews_monthly_revenue_public_row($existing),
        ];
    }
    if ($existing !== null) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_LOCK_INVALID', 'http_status' => 409];
    }

    $now = znews_now();
    $gross = max(0, (int)($sync['revenue_usd_micros'] ?? 0));
    $row = array_merge([
        'schema_version' => 1,
        'provider' => 'ADSTERRA',
        'currency' => 'USD',
        'month_id' => $monthId,
        'status' => 'LOCKED',
        'source_status' => 'FINAL',
        'sync_id' => (string)$sync['sync_id'],
        'gross_settled_usd_micros' => $gross,
        'provider_reported_at' => (int)($sync['provider_reported_at'] ?? 0),
        'provider_snapshot' => [
            'domain_id' => (string)($sync['provider_domain_id'] ?? ''),
            'report_start_date' => (string)($sync['report_start_date'] ?? ''),
            'report_finish_date' => (string)($sync['report_finish_date'] ?? ''),
            'report_row_count' => (int)($sync['report_row_count'] ?? 0),
            'impressions' => (int)($sync['impressions'] ?? 0),
            'clicks' => (int)($sync['clicks'] ?? 0),
        ],
        'locked_at' => $now,
        'locked_by' => znews_firebase_key($adminUid, 'admin_uid'),
        'created_at' => $now,
    ], znews_monthly_revenue_formula($gross));
    $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_LOCK_CONFLICT', 'http_status' => 409];
    }
    if (empty($write['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_REVENUE_LOCK_FAILED', 'http_status' => 503];
    }
    return [
        'ok' => true,
        'code' => 'ZNEWS_REVENUE_LOCKED',
        'idempotent_replay' => false,
        'lock' => znews_monthly_revenue_public_row($row),
    ];
}
