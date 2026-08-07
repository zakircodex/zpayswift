<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/creator_view_diagnostics.php';

function znews_calendar_review_scheme(): string
{
    return 'CALENDAR_01_07_08_14_15_21_22_EOM_V1';
}

function znews_calendar_review_start_days(): array
{
    return [1, 8, 15, 22];
}

function znews_calendar_review_bounds(DateTimeImmutable $date): array
{
    $timezone = new DateTimeZone('UTC');
    $date = $date->setTimezone($timezone)->setTime(0, 0, 0);
    $day = (int)$date->format('j');
    $yearMonth = $date->format('Y-m');

    if ($day <= 7) {
        $startDay = 1;
        $endDay = 8;
    } elseif ($day <= 14) {
        $startDay = 8;
        $endDay = 15;
    } elseif ($day <= 21) {
        $startDay = 15;
        $endDay = 22;
    } else {
        $startDay = 22;
        $endDay = 0;
    }

    $start = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        sprintf('%s-%02d', $yearMonth, $startDay),
        $timezone
    );
    if (!$start instanceof DateTimeImmutable) {
        return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_INVALID'];
    }

    if ($endDay > 0) {
        $end = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%s-%02d', $yearMonth, $endDay),
            $timezone
        );
    } else {
        $end = $start->modify('first day of next month');
    }
    if (!$end instanceof DateTimeImmutable) {
        return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_INVALID'];
    }

    return [
        'ok' => true,
        'start' => $start,
        'end' => $end,
    ];
}

function znews_calendar_review_period(
    string $periodId = '',
    ?int $anchor = null,
    string $mode = 'CURRENT'
): array {
    $timezone = new DateTimeZone('UTC');
    $now = $anchor ?? znews_now();
    $mode = strtoupper(trim($mode));

    if ($periodId !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $periodId, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $date instanceof DateTimeImmutable
            && ($errors === false || (((int)$errors['warning_count']) === 0 && ((int)$errors['error_count']) === 0))
            && $date->format('Y-m-d') === $periodId
            && in_array((int)$date->format('j'), znews_calendar_review_start_days(), true);
        if (!$valid) {
            return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_INVALID'];
        }
        $bounds = znews_calendar_review_bounds($date);
        if (empty($bounds['ok']) || $bounds['start']->format('Y-m-d') !== $periodId) {
            return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_INVALID'];
        }
    } else {
        $date = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
        $bounds = znews_calendar_review_bounds($date);
        if (empty($bounds['ok'])) {
            return $bounds;
        }
        if ($mode === 'PREVIOUS_COMPLETED') {
            $previousAnchor = $bounds['start']->getTimestamp() - 1;
            $previousDate = (new DateTimeImmutable('@' . $previousAnchor))->setTimezone($timezone);
            $bounds = znews_calendar_review_bounds($previousDate);
            if (empty($bounds['ok'])) {
                return $bounds;
            }
        }
    }

    /** @var DateTimeImmutable $startDate */
    $startDate = $bounds['start'];
    /** @var DateTimeImmutable $endDate */
    $endDate = $bounds['end'];
    $start = $startDate->getTimestamp();
    $end = $endDate->getTimestamp();
    $completed = $end <= $now;
    $live = $start <= $now && $now < $end;
    $upcoming = $now < $start;
    $lifecycle = $live ? 'LIVE' : ($completed ? 'COMPLETED' : 'UPCOMING');

    return [
        'ok' => true,
        'period_id' => $startDate->format('Y-m-d'),
        'period_start_at' => $start,
        'period_end_at' => $end,
        'period_start_date' => $startDate->format('Y-m-d'),
        'period_end_date' => $endDate->modify('-1 day')->format('Y-m-d'),
        'timezone' => 'UTC',
        'period_scheme' => znews_calendar_review_scheme(),
        'calendar_fixed' => true,
        'lifecycle_status' => $lifecycle,
        'completed' => $completed,
        'live' => $live,
        'upcoming' => $upcoming,
        'can_generate' => $completed,
        'can_review' => false,
    ];
}

function znews_calendar_review_catalog(int $limit = 16, ?int $anchor = null): array
{
    $limit = max(4, min(36, $limit));
    $now = $anchor ?? znews_now();
    $timezone = new DateTimeZone('UTC');
    $date = (new DateTimeImmutable('@' . $now))->setTimezone($timezone);
    $month = $date->modify('first day of this month')->setTime(0, 0, 0);
    $periods = [];

    for ($monthOffset = 0; count($periods) < $limit && $monthOffset < 12; $monthOffset++) {
        $targetMonth = $month->modify('-' . $monthOffset . ' months');
        foreach (znews_calendar_review_start_days() as $day) {
            $periodId = sprintf('%s-%02d', $targetMonth->format('Y-m'), $day);
            $period = znews_calendar_review_period($periodId, $now);
            if (!empty($period['ok'])) {
                $periods[] = $period;
            }
        }
    }

    $savedRoot = fb_get('ZNEWS_WEEKLY_REVIEW_PERIODS');
    $savedRoot = is_array($savedRoot) ? $savedRoot : [];
    foreach ($periods as &$period) {
        $saved = $savedRoot[(string)$period['period_id']] ?? null;
        if (is_array($saved)
            && trim((string)($saved['period_scheme'] ?? '')) === znews_calendar_review_scheme()) {
            $period['generated'] = true;
            $period['review_status'] = strtoupper(trim((string)($saved['status'] ?? 'UNDER_REVIEW')));
            $period['can_review'] = !empty($period['completed']);
            $period['generated_at'] = max(0, (int)($saved['generated_at'] ?? 0));
        } else {
            $period['generated'] = false;
            $period['review_status'] = '';
            $period['can_review'] = false;
            $period['generated_at'] = 0;
        }
    }
    unset($period);

    usort($periods, static function (array $a, array $b): int {
        $priority = ['LIVE' => 0, 'COMPLETED' => 1, 'UPCOMING' => 2];
        $aLife = (string)($a['lifecycle_status'] ?? 'COMPLETED');
        $bLife = (string)($b['lifecycle_status'] ?? 'COMPLETED');
        $group = ($priority[$aLife] ?? 9) <=> ($priority[$bLife] ?? 9);
        if ($group !== 0) {
            return $group;
        }
        if ($aLife === 'UPCOMING') {
            return ((int)$a['period_start_at']) <=> ((int)$b['period_start_at']);
        }
        return ((int)$b['period_start_at']) <=> ((int)$a['period_start_at']);
    });

    return array_slice($periods, 0, $limit);
}

function znews_calendar_review_build_rows(array $period, bool $livePreview): array
{
    $rows = [];
    foreach (znews_weekly_review_creator_registry_rows() as $creator) {
        $creatorUid = znews_firebase_key((string)$creator['creator_uid'], 'creator_uid');
        $metricsResult = znews_weekly_review_creator_metrics($creatorUid, $period);
        if (empty($metricsResult['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($metricsResult['code'] ?? 'ZNEWS_CALENDAR_METRICS_FAILED'),
                'creator_uid' => $creatorUid,
                'http_status' => 503,
            ];
        }

        $metrics = (array)$metricsResult['metrics'];
        $diagnostics = znews_view_diagnostics_creator_period($creatorUid, $period);
        $diagnosticsOk = !empty($diagnostics['ok'])
            && (int)($diagnostics['invalid_total'] ?? -1) === (int)($metrics['invalid_views'] ?? 0);
        $diagnosticItems = $diagnosticsOk && is_array($diagnostics['items'] ?? null)
            ? array_values((array)$diagnostics['items'])
            : [];
        $diagnosticSummary = $diagnosticsOk ? trim((string)($diagnostics['summary'] ?? '')) : '';

        $creatorStatus = znews_creator_normalize_status($creator['status'] ?? 'ACTIVE');
        $saved = $livePreview
            ? []
            : fb_get(znews_weekly_review_creator_path((string)$period['period_id'], $creatorUid));
        $saved = is_array($saved) ? $saved : [];
        $reviewStatus = $livePreview
            ? 'LIVE'
            : znews_weekly_review_status(
                $saved['review_status'] ?? ($creatorStatus === 'BLOCKED' ? 'HELD' : 'UNDER_REVIEW')
            );
        $reviewReason = $livePreview ? $diagnosticSummary : trim((string)($saved['review_reason'] ?? ''));
        if (!$livePreview && $reviewReason === '' && $diagnosticSummary !== '') {
            $reviewReason = $diagnosticSummary;
        }
        if (!$livePreview && $creatorStatus === 'BLOCKED') {
            $reviewStatus = 'HELD';
            $reviewReason = trim((string)($creator['block_reason'] ?? 'Creator account is blocked.'));
        }

        $rows[] = array_merge($period, $metrics, [
            'schema_version' => 2,
            'period_scheme' => znews_calendar_review_scheme(),
            'creator_uid' => $creatorUid,
            'creator_name' => trim((string)($creator['name'] ?? 'Z-Pay creator')),
            'creator_status' => $creatorStatus,
            'review_status' => $reviewStatus,
            'review_reason' => $reviewReason,
            'invalid_reason_summary' => $diagnosticSummary,
            'invalid_reason_counts' => $diagnosticItems,
            'diagnostics_available' => $diagnosticsOk,
            'traffic_share_ppm' => 0,
            'traffic_share_percent' => 0.0,
            'traffic_share_pending' => false,
            'source_view_count' => max(0, (int)($metricsResult['source_view_count'] ?? 0)),
            'generated_at' => $livePreview ? 0 : znews_now(),
            'generated_by' => '',
            'reviewed_at' => $livePreview ? 0 : max(0, (int)($saved['reviewed_at'] ?? 0)),
            'reviewed_by' => $livePreview ? '' : trim((string)($saved['reviewed_by'] ?? '')),
            'live_preview' => $livePreview,
        ]);
    }

    znews_weekly_review_allocate_shares($rows);
    return ['ok' => true, 'items' => $rows];
}

function znews_calendar_review_summary(array $period, array $rows, bool $livePreview): array
{
    $counts = ['UNDER_REVIEW' => 0, 'APPROVED' => 0, 'HELD' => 0];
    $totalRaw = 0;
    $totalEligible = 0;
    $totalInvalid = 0;
    $totalSpam = 0;
    $totalPending = 0;
    $totalDuplicate = 0;
    $creatorExcluded = 0;
    $selfExcluded = 0;
    $readSeconds = 0;
    $invalidReasons = [];

    foreach ($rows as $row) {
        $status = strtoupper(trim((string)($row['review_status'] ?? 'UNDER_REVIEW')));
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        $totalRaw += max(0, (int)($row['raw_views'] ?? 0));
        $totalEligible += max(0, (int)($row['eligible_views'] ?? 0));
        $totalInvalid += max(0, (int)($row['invalid_views'] ?? 0));
        $totalSpam += max(0, (int)($row['spam_views'] ?? 0));
        $totalPending += max(0, (int)($row['pending_views'] ?? 0));
        $totalDuplicate += max(0, (int)($row['duplicate_views'] ?? 0));
        $creatorExcluded += max(0, (int)($row['creator_views_excluded'] ?? 0));
        $selfExcluded += max(0, (int)($row['self_views_excluded'] ?? 0));
        $readSeconds += max(0, (int)($row['eligible_read_seconds'] ?? 0));
        foreach ((array)($row['invalid_reason_counts'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string)($item['code'] ?? 'OTHER'));
            $label = trim((string)($item['label'] ?? 'View validation failed'));
            $count = max(0, (int)($item['count'] ?? 0));
            if ($count <= 0) {
                continue;
            }
            if (!isset($invalidReasons[$code])) {
                $invalidReasons[$code] = ['code' => $code, 'label' => $label, 'count' => 0];
            }
            $invalidReasons[$code]['count'] += $count;
        }
    }

    uasort($invalidReasons, static function (array $a, array $b): int {
        $count = ((int)$b['count']) <=> ((int)$a['count']);
        return $count !== 0 ? $count : strcmp((string)$a['label'], (string)$b['label']);
    });

    return array_merge($period, [
        'schema_version' => 2,
        'period_scheme' => znews_calendar_review_scheme(),
        'status' => $livePreview ? 'LIVE' : 'UNDER_REVIEW',
        'creator_count' => count($rows),
        'total_raw_views' => $totalRaw,
        'total_eligible_views' => $totalEligible,
        'total_invalid_views' => $totalInvalid,
        'total_spam_views' => $totalSpam,
        'total_pending_views' => $totalPending,
        'total_duplicate_views' => $totalDuplicate,
        'creator_views_excluded' => $creatorExcluded,
        'self_views_excluded' => $selfExcluded,
        'eligible_read_seconds' => $readSeconds,
        'invalid_reason_counts' => array_values($invalidReasons),
        'under_review_count' => $livePreview ? 0 : $counts['UNDER_REVIEW'],
        'approved_count' => $livePreview ? 0 : $counts['APPROVED'],
        'held_count' => $livePreview ? 0 : $counts['HELD'],
        'live_preview' => $livePreview,
        'generated_at' => $livePreview ? 0 : znews_now(),
        'updated_at' => znews_now(),
    ]);
}

function znews_calendar_review_live_preview(string $periodId = ''): array
{
    $period = znews_calendar_review_period($periodId);
    if (empty($period['ok'])) {
        return ['ok' => false, 'code' => (string)$period['code'], 'http_status' => 422];
    }
    if (empty($period['live'])) {
        return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_NOT_LIVE', 'http_status' => 422];
    }

    $built = znews_calendar_review_build_rows($period, true);
    if (empty($built['ok'])) {
        return $built;
    }
    $rows = (array)$built['items'];
    $summary = znews_calendar_review_summary($period, $rows, true);
    return [
        'ok' => true,
        'code' => 'ZNEWS_CALENDAR_LIVE_PREVIEW_OK',
        'period' => $summary,
        'items' => $rows,
        'read_only' => true,
    ];
}

function znews_calendar_review_get_period(string $periodId): array
{
    $period = znews_calendar_review_period($periodId);
    if (empty($period['ok'])) {
        return ['ok' => false, 'code' => (string)$period['code'], 'http_status' => 422];
    }
    if (!empty($period['live'])) {
        return znews_calendar_review_live_preview($periodId);
    }
    if (!empty($period['upcoming'])) {
        return [
            'ok' => true,
            'code' => 'ZNEWS_CALENDAR_UPCOMING_PERIOD',
            'period' => $period,
            'items' => [],
            'read_only' => true,
        ];
    }

    $result = znews_weekly_review_get_period($periodId);
    if (empty($result['ok'])) {
        $result['period'] = $period;
        return $result;
    }
    $storedPeriod = is_array($result['period'] ?? null) ? (array)$result['period'] : [];
    if (trim((string)($storedPeriod['period_scheme'] ?? '')) !== znews_calendar_review_scheme()) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_CALENDAR_REVIEW_NOT_GENERATED',
            'period' => $period,
            'http_status' => 404,
        ];
    }
    $result['period'] = array_merge($storedPeriod, $period, [
        'can_review' => true,
        'generated' => true,
    ]);
    return $result;
}

function znews_calendar_review_generate(string $periodId, string $adminUid): array
{
    $period = znews_calendar_review_period($periodId);
    if (empty($period['ok'])) {
        return ['ok' => false, 'code' => (string)$period['code'], 'http_status' => 422];
    }
    if (empty($period['completed'])) {
        return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_NOT_COMPLETED', 'http_status' => 422];
    }

    $lock = znews_weekly_review_claim_lock((string)$period['period_id'], $adminUid);
    if (empty($lock['ok'])) {
        return $lock;
    }

    try {
        $built = znews_calendar_review_build_rows($period, false);
        if (empty($built['ok'])) {
            return $built;
        }
        $rows = (array)$built['items'];
        $generatedAt = znews_now();
        foreach ($rows as &$row) {
            $row['generated_at'] = $generatedAt;
            $row['generated_by'] = trim($adminUid);
            $row['source_hash'] = hash('sha256', json_encode([
                $row['period_scheme'], $row['period_id'], $row['creator_uid'],
                $row['post_count'], $row['raw_views'], $row['eligible_views'],
                $row['invalid_views'], $row['spam_views'], $row['duplicate_views'],
                $row['creator_views_excluded'], $row['self_views_excluded'],
                $row['pending_views'], $row['eligible_read_seconds'],
            ], JSON_UNESCAPED_SLASHES));
        }
        unset($row);

        foreach ($rows as $row) {
            $creatorUid = (string)$row['creator_uid'];
            if (!fb_put(znews_weekly_review_creator_path((string)$period['period_id'], $creatorUid), $row)) {
                return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_CREATOR_WRITE_FAILED', 'creator_uid' => $creatorUid, 'http_status' => 503];
            }
            @fb_put(znews_weekly_review_creator_index_path($creatorUid, (string)$period['period_id']), [
                'period_id' => (string)$period['period_id'],
                'period_scheme' => znews_calendar_review_scheme(),
                'period_start_at' => (int)$period['period_start_at'],
                'period_end_at' => (int)$period['period_end_at'],
                'review_status' => (string)$row['review_status'],
                'eligible_views' => (int)$row['eligible_views'],
                'generated_at' => $generatedAt,
            ]);
        }

        $summary = znews_calendar_review_summary($period, $rows, false);
        $summary['generated_at'] = $generatedAt;
        $summary['generated_by'] = trim($adminUid);
        $summary['updated_at'] = $generatedAt;
        if (!fb_put(znews_weekly_review_period_path((string)$period['period_id']), $summary)) {
            return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_WRITE_FAILED', 'http_status' => 503];
        }

        return [
            'ok' => true,
            'code' => 'ZNEWS_CALENDAR_REVIEW_GENERATED',
            'period' => $summary,
            'items' => $rows,
        ];
    } finally {
        znews_weekly_review_release_lock((string)$period['period_id'], (string)$lock['lock_id']);
    }
}

function znews_calendar_review_set_status(
    string $periodId,
    string $creatorUid,
    string $status,
    string $reason,
    string $adminUid
): array {
    $period = znews_calendar_review_period($periodId);
    if (empty($period['ok'])) {
        return ['ok' => false, 'code' => (string)$period['code'], 'http_status' => 422];
    }
    if (empty($period['completed'])) {
        return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_PERIOD_READ_ONLY', 'http_status' => 422];
    }
    $stored = fb_get(znews_weekly_review_period_path($periodId));
    if (!is_array($stored)
        || trim((string)($stored['period_scheme'] ?? '')) !== znews_calendar_review_scheme()) {
        return ['ok' => false, 'code' => 'ZNEWS_CALENDAR_REVIEW_NOT_GENERATED', 'http_status' => 404];
    }
    return znews_weekly_review_set_status($periodId, $creatorUid, $status, $reason, $adminUid);
}
