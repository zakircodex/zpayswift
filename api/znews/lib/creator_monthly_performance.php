<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_monthly_performance_scheme(): string
{
    return 'MONTHLY_APPROVED_CALENDAR_REVIEWS_V1';
}

function znews_monthly_performance_month(string $monthId = '', ?int $anchor = null): array
{
    $timezone = new DateTimeZone('UTC');
    $now = $anchor ?? znews_now();

    if ($monthId === '') {
        $monthId = gmdate('Y-m', $now);
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $monthId)) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_PERIOD_INVALID'];
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $monthId . '-01', $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    $valid = $start instanceof DateTimeImmutable
        && ($errors === false || (((int)$errors['warning_count']) === 0 && ((int)$errors['error_count']) === 0))
        && $start->format('Y-m') === $monthId;
    if (!$valid) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_PERIOD_INVALID'];
    }

    $end = $start->modify('first day of next month');
    $startAt = $start->getTimestamp();
    $endAt = $end->getTimestamp();
    $completed = $endAt <= $now;
    $live = $startAt <= $now && $now < $endAt;
    $lifecycle = $live ? 'LIVE' : ($completed ? 'COMPLETED' : 'UPCOMING');

    $periodIds = [];
    foreach (znews_calendar_review_start_days() as $day) {
        $periodIds[] = sprintf('%s-%02d', $monthId, $day);
    }

    return [
        'ok' => true,
        'month_id' => $monthId,
        'month_start_at' => $startAt,
        'month_end_at' => $endAt,
        'month_start_date' => $start->format('Y-m-d'),
        'month_end_date' => $end->modify('-1 day')->format('Y-m-d'),
        'timezone' => 'UTC',
        'scheme' => znews_monthly_performance_scheme(),
        'calendar_review_scheme' => znews_calendar_review_scheme(),
        'period_ids' => $periodIds,
        'expected_period_count' => count($periodIds),
        'lifecycle_status' => $lifecycle,
        'completed' => $completed,
        'live' => $live,
        'upcoming' => !$completed && !$live,
        'read_only' => true,
    ];
}

function znews_monthly_performance_catalog(int $limit = 12, ?int $anchor = null): array
{
    $limit = max(1, min(24, $limit));
    $now = $anchor ?? znews_now();
    $timezone = new DateTimeZone('UTC');
    $current = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->modify('first day of this month');
    $items = [];

    for ($offset = 0; $offset < $limit; $offset++) {
        $monthId = $current->modify('-' . $offset . ' months')->format('Y-m');
        $month = znews_monthly_performance_month($monthId, $now);
        if (!empty($month['ok'])) {
            $items[] = $month;
        }
    }
    return $items;
}

function znews_monthly_performance_period_state(string $periodId): array
{
    $period = znews_calendar_review_period($periodId);
    if (empty($period['ok'])) {
        return [
            'period_id' => $periodId,
            'generated' => false,
            'period_scheme_ok' => false,
            'status' => 'NOT_GENERATED',
        ];
    }

    $saved = fb_get(znews_weekly_review_period_path($periodId));
    $saved = is_array($saved) ? $saved : [];
    $schemeOk = trim((string)($saved['period_scheme'] ?? '')) === znews_calendar_review_scheme();
    $generated = $schemeOk && max(0, (int)($saved['generated_at'] ?? 0)) > 0;

    return [
        'period_id' => $periodId,
        'period_start_date' => (string)($period['period_start_date'] ?? ''),
        'period_end_date' => (string)($period['period_end_date'] ?? ''),
        'completed' => !empty($period['completed']),
        'generated' => $generated,
        'period_scheme_ok' => $schemeOk,
        'status' => $generated ? strtoupper(trim((string)($saved['status'] ?? 'UNDER_REVIEW'))) : 'NOT_GENERATED',
        'generated_at' => $generated ? max(0, (int)($saved['generated_at'] ?? 0)) : 0,
    ];
}

function znews_monthly_performance_registry_map(): array
{
    $rows = znews_weekly_review_creator_registry_rows();
    $map = [];
    foreach ($rows as $row) {
        $uid = trim((string)($row['creator_uid'] ?? ''));
        if ($uid !== '') {
            $map[$uid] = $row;
        }
    }
    return $map;
}

function znews_monthly_performance_empty_row(string $uid, array $creator, int $expectedPeriods): array
{
    return [
        'creator_uid' => $uid,
        'creator_name' => trim((string)($creator['name'] ?? 'Z-Pay creator')),
        'creator_status' => znews_creator_normalize_status($creator['status'] ?? 'ACTIVE'),
        'wallet_currency_snapshot' => strtoupper(trim((string)($creator['wallet_currency_snapshot'] ?? ''))),
        'account_country_snapshot' => strtoupper(trim((string)($creator['account_country_snapshot'] ?? ''))),
        'expected_period_count' => $expectedPeriods,
        'generated_period_count' => 0,
        'approved_period_count' => 0,
        'under_review_period_count' => 0,
        'held_period_count' => 0,
        'missing_period_count' => $expectedPeriods,
        'raw_views' => 0,
        'eligible_views' => 0,
        'invalid_views' => 0,
        'spam_views' => 0,
        'duplicate_views' => 0,
        'pending_views' => 0,
        'creator_views_excluded' => 0,
        'self_views_excluded' => 0,
        'eligible_read_seconds' => 0,
        'settlement_eligible_views' => 0,
        'settlement_traffic_share_ppm' => 0,
        'settlement_traffic_share_percent' => 0.0,
        'payout_candidate' => false,
        'payout_block_reason' => '',
        'periods' => [],
    ];
}

function znews_monthly_performance_add_metrics(array &$target, array $row): void
{
    foreach ([
        'raw_views',
        'eligible_views',
        'invalid_views',
        'spam_views',
        'duplicate_views',
        'pending_views',
        'creator_views_excluded',
        'self_views_excluded',
        'eligible_read_seconds',
    ] as $field) {
        $target[$field] += max(0, (int)($row[$field] ?? 0));
    }
}

function znews_monthly_performance_allocate_shares(array &$rows): void
{
    $total = array_sum(array_map(
        static fn(array $row): int => max(0, (int)($row['settlement_eligible_views'] ?? 0)),
        $rows
    ));
    if ($total <= 0) {
        return;
    }

    $assigned = 0;
    $remainders = [];
    foreach ($rows as $index => &$row) {
        $eligible = max(0, (int)($row['settlement_eligible_views'] ?? 0));
        $numerator = $eligible * 1000000;
        $ppm = intdiv($numerator, $total);
        $row['settlement_traffic_share_ppm'] = $ppm;
        $assigned += $ppm;
        $remainders[] = [
            'index' => $index,
            'remainder' => $numerator % $total,
            'creator_uid' => (string)($row['creator_uid'] ?? ''),
        ];
    }
    unset($row);

    usort($remainders, static function (array $a, array $b): int {
        $result = ((int)$b['remainder']) <=> ((int)$a['remainder']);
        return $result !== 0 ? $result : strcmp((string)$a['creator_uid'], (string)$b['creator_uid']);
    });
    $remaining = max(0, 1000000 - $assigned);
    for ($i = 0; $i < $remaining && isset($remainders[$i]); $i++) {
        $rows[(int)$remainders[$i]['index']]['settlement_traffic_share_ppm']++;
    }
    foreach ($rows as &$row) {
        $row['settlement_traffic_share_percent'] = round(((int)$row['settlement_traffic_share_ppm']) / 10000, 4);
    }
    unset($row);
}

function znews_monthly_performance_preview(string $monthId = '', ?int $anchor = null): array
{
    $month = znews_monthly_performance_month($monthId, $anchor);
    if (empty($month['ok'])) {
        return ['ok' => false, 'code' => (string)($month['code'] ?? 'ZNEWS_MONTHLY_PERIOD_INVALID'), 'http_status' => 422];
    }

    $registry = znews_monthly_performance_registry_map();
    if (count($registry) > 500) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_CREATOR_SOURCE_LIMIT_EXCEEDED', 'http_status' => 503];
    }

    $rows = [];
    $expected = max(1, (int)$month['expected_period_count']);
    foreach ($registry as $uid => $creator) {
        $rows[$uid] = znews_monthly_performance_empty_row($uid, $creator, $expected);
    }

    $periodStates = [];
    $generatedPeriods = 0;
    foreach ((array)$month['period_ids'] as $periodId) {
        $state = znews_monthly_performance_period_state((string)$periodId);
        $periodStates[] = $state;
        if (empty($state['generated'])) {
            foreach ($rows as &$creatorRow) {
                $creatorRow['periods'][] = [
                    'period_id' => (string)$periodId,
                    'review_status' => 'NOT_GENERATED',
                    'eligible_views' => 0,
                ];
            }
            unset($creatorRow);
            continue;
        }

        $generatedPeriods++;
        foreach ($rows as $uid => &$creatorRow) {
            $review = fb_get(znews_weekly_review_creator_path((string)$periodId, (string)$uid));
            if (!is_array($review)
                || trim((string)($review['period_scheme'] ?? '')) !== znews_calendar_review_scheme()) {
                $creatorRow['periods'][] = [
                    'period_id' => (string)$periodId,
                    'review_status' => 'MISSING',
                    'eligible_views' => 0,
                ];
                continue;
            }

            $creatorRow['generated_period_count']++;
            $creatorRow['missing_period_count'] = max(0, $creatorRow['missing_period_count'] - 1);
            znews_monthly_performance_add_metrics($creatorRow, $review);
            $status = znews_weekly_review_status($review['review_status'] ?? 'UNDER_REVIEW');
            if ($status === 'APPROVED') {
                $creatorRow['approved_period_count']++;
                $creatorRow['settlement_eligible_views'] += max(0, (int)($review['eligible_views'] ?? 0));
            } elseif ($status === 'HELD') {
                $creatorRow['held_period_count']++;
            } else {
                $creatorRow['under_review_period_count']++;
            }
            $creatorRow['periods'][] = [
                'period_id' => (string)$periodId,
                'review_status' => $status,
                'eligible_views' => max(0, (int)($review['eligible_views'] ?? 0)),
                'reviewed_at' => max(0, (int)($review['reviewed_at'] ?? 0)),
            ];
        }
        unset($creatorRow);
    }

    $monthCompleted = !empty($month['completed']);
    foreach ($rows as &$row) {
        $currency = (string)($row['wallet_currency_snapshot'] ?? '');
        if (!in_array($currency, ['BDT', 'MYR'], true)) {
            $currency = '';
        }
        $row['wallet_currency_snapshot'] = $currency;

        if (!$monthCompleted) {
            $row['payout_block_reason'] = 'Month is still open.';
        } elseif ($row['creator_status'] !== 'ACTIVE') {
            $row['payout_block_reason'] = 'Creator account is blocked.';
        } elseif ($row['missing_period_count'] > 0) {
            $row['payout_block_reason'] = 'One or more calendar reviews are missing.';
        } elseif ($row['under_review_period_count'] > 0) {
            $row['payout_block_reason'] = 'One or more calendar reviews are still under review.';
        } elseif ($row['held_period_count'] > 0) {
            $row['payout_block_reason'] = 'One or more calendar reviews are held.';
        } elseif ($row['settlement_eligible_views'] <= 0) {
            $row['payout_block_reason'] = 'No approved eligible views in this month.';
        } elseif ($currency === '') {
            $row['payout_block_reason'] = 'Payout currency snapshot is unavailable.';
        } else {
            $row['payout_candidate'] = true;
            $row['payout_block_reason'] = '';
        }
    }
    unset($row);

    $rowValues = array_values($rows);
    znews_monthly_performance_allocate_shares($rowValues);
    usort($rowValues, static function (array $a, array $b): int {
        $eligible = ((int)$b['settlement_eligible_views']) <=> ((int)$a['settlement_eligible_views']);
        return $eligible !== 0 ? $eligible : strcmp((string)$a['creator_uid'], (string)$b['creator_uid']);
    });

    $totalRaw = array_sum(array_column($rowValues, 'raw_views'));
    $totalEligible = array_sum(array_column($rowValues, 'eligible_views'));
    $settlementEligible = array_sum(array_column($rowValues, 'settlement_eligible_views'));
    $candidateRows = array_values(array_filter($rowValues, static fn(array $row): bool => !empty($row['payout_candidate'])));
    $currencyCounts = [
        'BDT' => count(array_filter($candidateRows, static fn(array $row): bool => ($row['wallet_currency_snapshot'] ?? '') === 'BDT')),
        'MYR' => count(array_filter($candidateRows, static fn(array $row): bool => ($row['wallet_currency_snapshot'] ?? '') === 'MYR')),
    ];

    $allPeriodsGenerated = $generatedPeriods === $expected;
    $allCreatorsReviewComplete = count(array_filter($rowValues, static function (array $row): bool {
        return (int)$row['missing_period_count'] === 0
            && (int)$row['under_review_period_count'] === 0
            && (int)$row['held_period_count'] === 0;
    })) === count($rowValues);

    $settlementReady = $monthCompleted
        && $allPeriodsGenerated
        && $allCreatorsReviewComplete
        && $settlementEligible > 0
        && count($candidateRows) > 0;

    return [
        'ok' => true,
        'code' => 'ZNEWS_MONTHLY_PERFORMANCE_PREVIEW_OK',
        'month' => $month,
        'periods' => $periodStates,
        'items' => $rowValues,
        'summary' => [
            'creator_count' => count($rowValues),
            'expected_period_count' => $expected,
            'generated_period_count' => $generatedPeriods,
            'all_periods_generated' => $allPeriodsGenerated,
            'all_creator_reviews_complete' => $allCreatorsReviewComplete,
            'total_raw_views' => $totalRaw,
            'total_eligible_views' => $totalEligible,
            'settlement_eligible_views' => $settlementEligible,
            'payout_candidate_count' => count($candidateRows),
            'currency_snapshot_counts' => $currencyCounts,
            'settlement_ready' => $settlementReady,
            'read_only' => true,
            'revenue_amount_calculated' => false,
            'wallet_mutation_performed' => false,
            'requires_live_payout_preflight' => true,
        ],
    ];
}
