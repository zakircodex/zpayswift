<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_weekly_review_status($value, string $fallback = 'UNDER_REVIEW'): string
{
    $status = strtoupper(trim((string)$value));
    return in_array($status, ['UNDER_REVIEW', 'APPROVED', 'HELD'], true)
        ? $status
        : $fallback;
}

function znews_weekly_review_period_path(string $periodId): string
{
    return 'ZNEWS_WEEKLY_REVIEW_PERIODS/' . znews_firebase_key($periodId, 'period_id');
}

function znews_weekly_review_creator_path(string $periodId, string $creatorUid): string
{
    return 'ZNEWS_WEEKLY_REVIEWS/'
        . znews_firebase_key($periodId, 'period_id')
        . '/'
        . znews_firebase_key($creatorUid, 'creator_uid');
}

function znews_weekly_review_creator_index_path(string $creatorUid, string $periodId): string
{
    return 'ZNEWS_WEEKLY_REVIEWS_BY_CREATOR/'
        . znews_firebase_key($creatorUid, 'creator_uid')
        . '/'
        . znews_firebase_key($periodId, 'period_id');
}

function znews_weekly_review_lock_path(string $periodId): string
{
    return 'ZNEWS_WEEKLY_REVIEW_LOCKS/' . znews_firebase_key($periodId, 'period_id');
}

function znews_weekly_review_period(string $periodId = '', ?int $anchor = null, bool $previousCompleted = false): array
{
    $timezone = new DateTimeZone('UTC');
    $now = $anchor ?? znews_now();

    if ($periodId !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $periodId, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $invalid = $date === false
            || ($errors !== false && (((int)$errors['warning_count']) > 0 || ((int)$errors['error_count']) > 0))
            || $date->format('Y-m-d') !== $periodId
            || $date->format('N') !== '1';
        if ($invalid) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_PERIOD_INVALID'];
        }
        $start = $date->getTimestamp();
    } else {
        $date = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0, 0);
        $start = $date->modify('-' . (((int)$date->format('N')) - 1) . ' days')->getTimestamp();
        if ($previousCompleted) {
            $start -= 604800;
        }
    }

    $end = $start + 604800;
    return [
        'ok' => true,
        'period_id' => gmdate('Y-m-d', $start),
        'period_start_at' => $start,
        'period_end_at' => $end,
        'period_start_date' => gmdate('Y-m-d', $start),
        'period_end_date' => gmdate('Y-m-d', $end - 1),
        'timezone' => 'UTC',
        'completed' => $end <= $now,
    ];
}

function znews_weekly_review_public_row(array $row): array
{
    return [
        'period_id' => trim((string)($row['period_id'] ?? '')),
        'period_start_at' => max(0, (int)($row['period_start_at'] ?? 0)),
        'period_end_at' => max(0, (int)($row['period_end_at'] ?? 0)),
        'period_start_date' => trim((string)($row['period_start_date'] ?? '')),
        'period_end_date' => trim((string)($row['period_end_date'] ?? '')),
        'timezone' => trim((string)($row['timezone'] ?? 'UTC')),
        'creator_uid' => trim((string)($row['creator_uid'] ?? '')),
        'creator_name' => trim((string)($row['creator_name'] ?? 'Z-Pay creator')),
        'creator_status' => znews_creator_normalize_status($row['creator_status'] ?? 'ACTIVE'),
        'review_status' => znews_weekly_review_status($row['review_status'] ?? 'UNDER_REVIEW'),
        'review_reason' => trim((string)($row['review_reason'] ?? '')),
        'post_count' => max(0, (int)($row['post_count'] ?? 0)),
        'raw_views' => max(0, (int)($row['raw_views'] ?? 0)),
        'eligible_views' => max(0, (int)($row['eligible_views'] ?? 0)),
        'invalid_views' => max(0, (int)($row['invalid_views'] ?? 0)),
        'spam_views' => max(0, (int)($row['spam_views'] ?? 0)),
        'duplicate_views' => max(0, (int)($row['duplicate_views'] ?? 0)),
        'creator_views_excluded' => max(0, (int)($row['creator_views_excluded'] ?? 0)),
        'self_views_excluded' => max(0, (int)($row['self_views_excluded'] ?? 0)),
        'pending_views' => max(0, (int)($row['pending_views'] ?? 0)),
        'eligible_read_seconds' => max(0, (int)($row['eligible_read_seconds'] ?? 0)),
        'average_eligible_read_seconds' => max(0, (float)($row['average_eligible_read_seconds'] ?? 0)),
        'traffic_share_ppm' => max(0, min(1000000, (int)($row['traffic_share_ppm'] ?? 0))),
        'traffic_share_percent' => max(0, min(100, (float)($row['traffic_share_percent'] ?? 0))),
        'traffic_share_pending' => !empty($row['traffic_share_pending']),
        'generated_at' => max(0, (int)($row['generated_at'] ?? 0)),
        'reviewed_at' => max(0, (int)($row['reviewed_at'] ?? 0)),
        'reviewed_by' => trim((string)($row['reviewed_by'] ?? '')),
        'live_preview' => !empty($row['live_preview']),
    ];
}

function znews_weekly_review_creator_row(array $row): array
{
    $safe = znews_weekly_review_public_row($row);
    unset($safe['reviewed_by']);
    return $safe;
}

function znews_weekly_review_view_is_spam(array $view): bool
{
    if (!empty($view['guest_spam']) || !empty($view['bot_detected'])) {
        return true;
    }
    if (strtoupper(trim((string)($view['status'] ?? ''))) === 'BLOCKED') {
        return true;
    }
    foreach ((array)($view['risk_reasons'] ?? []) as $reason) {
        $reason = strtoupper(trim((string)$reason));
        if ($reason !== '' && (
            str_contains($reason, 'BOT')
            || str_contains($reason, 'RATE_EXCEEDED')
            || str_contains($reason, 'GUEST_VIEW_WINDOW')
        )) {
            return true;
        }
    }
    return false;
}

function znews_weekly_review_view_timestamp(array $view): int
{
    $completed = max(0, (int)($view['completed_at'] ?? 0));
    return $completed > 0 ? $completed : max(0, (int)($view['created_at'] ?? 0));
}

function znews_weekly_review_load_view(string $viewId, array $indexRow): array
{
    $required = ['result', 'viewer_uid', 'self_view', 'duplicate', 'bot_detected', 'guest_spam', 'revenue_share_eligible'];
    $complete = true;
    foreach ($required as $field) {
        if (!array_key_exists($field, $indexRow)) {
            $complete = false;
            break;
        }
    }
    if ($complete) {
        return $indexRow;
    }

    $session = fb_get(znews_view_path($viewId));
    return is_array($session) ? array_merge($indexRow, $session) : $indexRow;
}

function znews_weekly_review_creator_metrics(string $creatorUid, array $period): array
{
    $creatorUid = znews_firebase_key($creatorUid, 'creator_uid');
    $postIndex = fb_get('ZNEWS_USER_POSTS/' . $creatorUid);
    $postIndex = is_array($postIndex) ? $postIndex : [];
    if (count($postIndex) > 500) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_POST_SOURCE_LIMIT_EXCEEDED'];
    }

    $metrics = [
        'post_count' => 0,
        'raw_views' => 0,
        'eligible_views' => 0,
        'invalid_views' => 0,
        'spam_views' => 0,
        'duplicate_views' => 0,
        'creator_views_excluded' => 0,
        'self_views_excluded' => 0,
        'pending_views' => 0,
        'eligible_read_seconds' => 0,
    ];
    $sourceViews = 0;
    $start = (int)$period['period_start_at'];
    $end = (int)$period['period_end_at'];

    foreach ($postIndex as $postKey => $postRef) {
        $postRef = is_array($postRef) ? $postRef : [];
        $postId = trim((string)($postRef['post_id'] ?? $postKey));
        if ($postId === '') {
            continue;
        }
        $postId = znews_firebase_key($postId, 'post_id');
        $postCreatedAt = max(0, (int)($postRef['created_at'] ?? 0));
        if ($postCreatedAt >= $start && $postCreatedAt < $end) {
            $metrics['post_count']++;
        }

        $viewIndex = fb_get('ZNEWS_POST_VIEWS/' . $postId);
        if (!is_array($viewIndex)) {
            continue;
        }
        $sourceViews += count($viewIndex);
        if ($sourceViews > 5000) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_VIEW_SOURCE_LIMIT_EXCEEDED'];
        }

        foreach ($viewIndex as $viewKey => $indexRow) {
            if (!is_array($indexRow)) {
                continue;
            }
            $viewId = trim((string)($indexRow['view_id'] ?? $viewKey));
            if ($viewId === '') {
                continue;
            }
            $viewId = znews_firebase_key($viewId, 'view_id');
            $view = znews_weekly_review_load_view($viewId, (array)$indexRow);
            $timestamp = znews_weekly_review_view_timestamp($view);
            if ($timestamp < $start || $timestamp >= $end) {
                continue;
            }

            $metrics['raw_views']++;
            $viewerUid = trim((string)($view['viewer_uid'] ?? ''));
            $selfView = !empty($view['self_view'])
                || ($viewerUid !== '' && hash_equals($creatorUid, $viewerUid));
            $creatorView = $viewerUid !== ''
                || strtoupper(trim((string)($view['viewer_class'] ?? ''))) === 'CREATOR';
            $status = strtoupper(trim((string)($view['status'] ?? '')));
            $result = strtoupper(trim((string)($view['result'] ?? 'PENDING')));

            if ($creatorView) {
                $metrics['creator_views_excluded']++;
                if ($selfView) {
                    $metrics['self_views_excluded']++;
                }
                continue;
            }

            if ($status !== 'COMPLETED' && $status !== 'BLOCKED') {
                $metrics['pending_views']++;
                continue;
            }

            $spam = znews_weekly_review_view_is_spam($view);
            $duplicate = !empty($view['duplicate']);
            $explicitRevenueEligibility = array_key_exists('revenue_share_eligible', $view)
                ? !empty($view['revenue_share_eligible'])
                : true;
            $eligible = $result === 'VALID'
                && $status === 'COMPLETED'
                && $explicitRevenueEligibility
                && !$duplicate
                && !$spam
                && empty($view['bot_detected'])
                && max(0, (int)($view['risk_score'] ?? 0)) < znews_view_risk_threshold();

            if ($eligible) {
                $metrics['eligible_views']++;
                $metrics['eligible_read_seconds'] += max(0, (int)($view['active_seconds'] ?? 0));
                continue;
            }

            $metrics['invalid_views']++;
            if ($spam) {
                $metrics['spam_views']++;
            }
            if ($duplicate) {
                $metrics['duplicate_views']++;
            }
        }
    }

    $eligible = max(0, (int)$metrics['eligible_views']);
    $metrics['average_eligible_read_seconds'] = $eligible > 0
        ? round(((int)$metrics['eligible_read_seconds']) / $eligible, 2)
        : 0.0;

    return [
        'ok' => true,
        'metrics' => $metrics,
        'source_view_count' => $sourceViews,
    ];
}

function znews_weekly_review_creator_registry_rows(): array
{
    $root = fb_get('ZNEWS_CREATORS');
    if (!is_array($root)) {
        return [];
    }
    if (count($root) > 500) {
        return [];
    }

    $rows = [];
    foreach ($root as $uid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['creator_uid'] = trim((string)($row['creator_uid'] ?? $uid));
        if ($row['creator_uid'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int =>
        strcmp((string)($a['creator_uid'] ?? ''), (string)($b['creator_uid'] ?? ''))
    );
    return $rows;
}

function znews_weekly_review_allocate_shares(array &$rows): void
{
    $total = array_sum(array_map(
        static fn(array $row): int => max(0, (int)($row['eligible_views'] ?? 0)),
        $rows
    ));
    if ($total <= 0) {
        foreach ($rows as &$row) {
            $row['traffic_share_ppm'] = 0;
            $row['traffic_share_percent'] = 0.0;
        }
        unset($row);
        return;
    }

    $assigned = 0;
    $remainders = [];
    foreach ($rows as $index => &$row) {
        $eligible = max(0, (int)($row['eligible_views'] ?? 0));
        $numerator = $eligible * 1000000;
        $ppm = intdiv($numerator, $total);
        $row['traffic_share_ppm'] = $ppm;
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
        $rows[(int)$remainders[$i]['index']]['traffic_share_ppm']++;
    }
    foreach ($rows as &$row) {
        $row['traffic_share_percent'] = round(((int)$row['traffic_share_ppm']) / 10000, 4);
    }
    unset($row);
}

function znews_weekly_review_claim_lock(string $periodId, string $adminUid): array
{
    $path = znews_weekly_review_lock_path($periodId);
    $now = znews_now();
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $read = fb_get_with_etag($path);
        if (empty($read['ok']) || !is_string($read['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_LOCK_READ_FAILED'];
        }
        $existing = is_array($read['value'] ?? null) ? (array)$read['value'] : [];
        if ((int)($existing['expires_at'] ?? 0) > $now) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_BUSY', 'http_status' => 409];
        }
        $lockId = znews_make_id('ZWRL');
        $write = fb_put_if_match($path, [
            'lock_id' => $lockId,
            'period_id' => $periodId,
            'admin_uid' => trim($adminUid),
            'created_at' => $now,
            'expires_at' => $now + 180,
        ], (string)$read['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        return empty($write['ok'])
            ? ['ok' => false, 'code' => 'ZNEWS_WEEKLY_LOCK_WRITE_FAILED']
            : ['ok' => true, 'lock_id' => $lockId];
    }
    return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_BUSY', 'http_status' => 409];
}

function znews_weekly_review_release_lock(string $periodId, string $lockId): void
{
    $path = znews_weekly_review_lock_path($periodId);
    $row = fb_get($path);
    if (is_array($row) && hash_equals(trim((string)($row['lock_id'] ?? '')), trim($lockId))) {
        @fb_delete($path);
    }
}

function znews_weekly_review_generate(string $periodId, string $adminUid): array
{
    $period = znews_weekly_review_period($periodId);
    if (empty($period['ok'])) {
        return ['ok' => false, 'code' => (string)$period['code'], 'http_status' => 422];
    }
    if (empty($period['completed'])) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_PERIOD_NOT_COMPLETED', 'http_status' => 422];
    }

    $existingPeriod = fb_get(znews_weekly_review_period_path((string)$period['period_id']));
    if (is_array($existingPeriod)
        && strtoupper(trim((string)($existingPeriod['status'] ?? ''))) === 'LOCKED') {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_PERIOD_LOCKED', 'http_status' => 409];
    }

    $lock = znews_weekly_review_claim_lock((string)$period['period_id'], $adminUid);
    if (empty($lock['ok'])) {
        return $lock;
    }

    try {
        $generatedAt = znews_now();
        $rows = [];
        foreach (znews_weekly_review_creator_registry_rows() as $creator) {
            $creatorUid = znews_firebase_key((string)$creator['creator_uid'], 'creator_uid');
            $metricsResult = znews_weekly_review_creator_metrics($creatorUid, $period);
            if (empty($metricsResult['ok'])) {
                return [
                    'ok' => false,
                    'code' => (string)($metricsResult['code'] ?? 'ZNEWS_WEEKLY_METRICS_FAILED'),
                    'creator_uid' => $creatorUid,
                    'http_status' => 503,
                ];
            }
            $metrics = (array)$metricsResult['metrics'];
            $creatorStatus = znews_creator_normalize_status($creator['status'] ?? 'ACTIVE');
            $saved = fb_get(znews_weekly_review_creator_path((string)$period['period_id'], $creatorUid));
            $saved = is_array($saved) ? $saved : [];
            $reviewStatus = znews_weekly_review_status(
                $saved['review_status'] ?? ($creatorStatus === 'BLOCKED' ? 'HELD' : 'UNDER_REVIEW')
            );
            $reviewReason = trim((string)($saved['review_reason'] ?? ''));
            if ($creatorStatus === 'BLOCKED') {
                $reviewStatus = 'HELD';
                $reviewReason = trim((string)($creator['block_reason'] ?? 'Creator account is blocked.'));
            }

            $row = array_merge($period, $metrics, [
                'schema_version' => 1,
                'creator_uid' => $creatorUid,
                'creator_name' => trim((string)($creator['name'] ?? 'Z-Pay creator')),
                'creator_status' => $creatorStatus,
                'review_status' => $reviewStatus,
                'review_reason' => $reviewReason,
                'traffic_share_ppm' => 0,
                'traffic_share_percent' => 0.0,
                'traffic_share_pending' => false,
                'source_view_count' => max(0, (int)($metricsResult['source_view_count'] ?? 0)),
                'generated_at' => $generatedAt,
                'generated_by' => trim($adminUid),
                'reviewed_at' => max(0, (int)($saved['reviewed_at'] ?? 0)),
                'reviewed_by' => trim((string)($saved['reviewed_by'] ?? '')),
                'live_preview' => false,
            ]);
            $row['source_hash'] = hash('sha256', json_encode([
                $row['period_id'], $creatorUid, $metrics,
            ], JSON_UNESCAPED_SLASHES));
            $rows[] = $row;
        }

        znews_weekly_review_allocate_shares($rows);
        $statusCounts = ['UNDER_REVIEW' => 0, 'APPROVED' => 0, 'HELD' => 0];
        $totalEligible = 0;
        $totalRaw = 0;
        foreach ($rows as $row) {
            $creatorUid = (string)$row['creator_uid'];
            if (!fb_put(znews_weekly_review_creator_path((string)$period['period_id'], $creatorUid), $row)) {
                return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_CREATOR_WRITE_FAILED', 'creator_uid' => $creatorUid, 'http_status' => 503];
            }
            @fb_put(znews_weekly_review_creator_index_path($creatorUid, (string)$period['period_id']), [
                'period_id' => (string)$period['period_id'],
                'period_start_at' => (int)$period['period_start_at'],
                'period_end_at' => (int)$period['period_end_at'],
                'review_status' => (string)$row['review_status'],
                'eligible_views' => (int)$row['eligible_views'],
                'generated_at' => $generatedAt,
            ]);
            $statusCounts[(string)$row['review_status']]++;
            $totalEligible += (int)$row['eligible_views'];
            $totalRaw += (int)$row['raw_views'];
        }

        $summary = array_merge($period, [
            'schema_version' => 1,
            'status' => 'UNDER_REVIEW',
            'creator_count' => count($rows),
            'total_raw_views' => $totalRaw,
            'total_eligible_views' => $totalEligible,
            'under_review_count' => $statusCounts['UNDER_REVIEW'],
            'approved_count' => $statusCounts['APPROVED'],
            'held_count' => $statusCounts['HELD'],
            'generated_at' => $generatedAt,
            'generated_by' => trim($adminUid),
            'updated_at' => $generatedAt,
        ]);
        if (!fb_put(znews_weekly_review_period_path((string)$period['period_id']), $summary)) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_PERIOD_WRITE_FAILED', 'http_status' => 503];
        }

        return [
            'ok' => true,
            'code' => 'ZNEWS_WEEKLY_REVIEW_GENERATED',
            'period' => $summary,
            'items' => array_map('znews_weekly_review_public_row', $rows),
        ];
    } finally {
        znews_weekly_review_release_lock((string)$period['period_id'], (string)$lock['lock_id']);
    }
}

function znews_weekly_review_get_period(string $periodId): array
{
    $period = fb_get(znews_weekly_review_period_path($periodId));
    if (!is_array($period)) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_NOT_FOUND', 'http_status' => 404];
    }
    $root = fb_get('ZNEWS_WEEKLY_REVIEWS/' . znews_firebase_key($periodId, 'period_id'));
    $rows = [];
    if (is_array($root)) {
        foreach ($root as $row) {
            if (is_array($row)) {
                $rows[] = znews_weekly_review_public_row($row);
            }
        }
    }
    usort($rows, static function (array $a, array $b): int {
        $eligible = ((int)$b['eligible_views']) <=> ((int)$a['eligible_views']);
        return $eligible !== 0 ? $eligible : strcmp((string)$a['creator_uid'], (string)$b['creator_uid']);
    });
    return ['ok' => true, 'period' => $period, 'items' => $rows];
}

function znews_weekly_review_list_periods(int $limit = 12): array
{
    $limit = max(1, min(52, $limit));
    $root = fb_get('ZNEWS_WEEKLY_REVIEW_PERIODS');
    $items = [];
    if (is_array($root)) {
        foreach ($root as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
    }
    usort($items, static fn(array $a, array $b): int =>
        ((int)($b['period_start_at'] ?? 0)) <=> ((int)($a['period_start_at'] ?? 0))
    );
    return array_slice($items, 0, $limit);
}

function znews_weekly_review_update_period_counts(string $periodId): void
{
    $root = fb_get('ZNEWS_WEEKLY_REVIEWS/' . znews_firebase_key($periodId, 'period_id'));
    $counts = ['UNDER_REVIEW' => 0, 'APPROVED' => 0, 'HELD' => 0];
    if (is_array($root)) {
        foreach ($root as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = znews_weekly_review_status($row['review_status'] ?? 'UNDER_REVIEW');
            $counts[$status]++;
        }
    }
    @fb_patch(znews_weekly_review_period_path($periodId), [
        'under_review_count' => $counts['UNDER_REVIEW'],
        'approved_count' => $counts['APPROVED'],
        'held_count' => $counts['HELD'],
        'updated_at' => znews_now(),
    ]);
}

function znews_weekly_review_set_status(
    string $periodId,
    string $creatorUid,
    string $status,
    string $reason,
    string $adminUid
): array {
    $status = znews_weekly_review_status($status, '');
    if (!in_array($status, ['APPROVED', 'HELD'], true)) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_STATUS_INVALID', 'http_status' => 422];
    }
    $reason = substr(znews_normalize_text($reason), 0, 300);
    if ($status === 'HELD' && $reason === '') {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_HOLD_REASON_REQUIRED', 'http_status' => 422];
    }

    $path = znews_weekly_review_creator_path($periodId, $creatorUid);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $read = fb_get_with_etag($path);
        if (empty($read['ok']) || !is_string($read['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_READ_FAILED', 'http_status' => 503];
        }
        if (!is_array($read['value'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_NOT_FOUND', 'http_status' => 404];
        }
        $row = (array)$read['value'];
        $registry = fb_get(znews_creator_registry_path($creatorUid));
        if ($status === 'APPROVED' && (!is_array($registry)
            || znews_creator_normalize_status($registry['status'] ?? 'BLOCKED') !== 'ACTIVE')) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_CREATOR_NOT_ACTIVE', 'http_status' => 422];
        }
        $row['review_status'] = $status;
        $row['review_reason'] = $status === 'HELD' ? $reason : '';
        $row['reviewed_at'] = znews_now();
        $row['reviewed_by'] = trim($adminUid);
        $row['updated_at'] = znews_now();
        $write = fb_put_if_match($path, $row, (string)$read['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_WRITE_FAILED', 'http_status' => 503];
        }
        @fb_patch(znews_weekly_review_creator_index_path($creatorUid, $periodId), [
            'review_status' => $status,
            'reviewed_at' => (int)$row['reviewed_at'],
        ]);
        znews_weekly_review_update_period_counts($periodId);
        return ['ok' => true, 'review' => znews_weekly_review_public_row($row)];
    }
    return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_REVIEW_BUSY', 'http_status' => 409];
}

function znews_weekly_review_history_cursor($value): string
{
    $cursor = trim((string)$value);
    if ($cursor === '') {
        return '';
    }
    if (strlen($cursor) !== 10 || empty(znews_weekly_review_period($cursor)['ok'])) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }
    return $cursor;
}

function znews_weekly_review_creator_history_page(
    string $creatorUid,
    int $limit = 6,
    string $cursor = ''
): array
{
    $creatorUid = znews_firebase_key($creatorUid, 'creator_uid');
    $limit = max(1, min(12, $limit));
    $cursor = znews_weekly_review_history_cursor($cursor);
    $query = [
        'orderBy' => json_encode('$key'),
        'limitToLast' => $limit + 1 + ($cursor !== '' ? 1 : 0),
    ];
    if ($cursor !== '') {
        $query['endAt'] = json_encode($cursor);
    }

    $index = fb_get('ZNEWS_WEEKLY_REVIEWS_BY_CREATOR/' . $creatorUid, $query);
    $index = is_array($index) ? $index : [];
    if ($cursor !== '') {
        unset($index[$cursor]);
    }
    krsort($index, SORT_STRING);
    $periodIds = array_keys($index);
    $hasMore = count($periodIds) > $limit;
    $periodIds = array_slice($periodIds, 0, $limit);

    $items = [];
    foreach ($periodIds as $periodId) {
        $row = fb_get(znews_weekly_review_creator_path((string)$periodId, $creatorUid));
        if (is_array($row)) {
            $items[] = znews_weekly_review_creator_row($row);
        }
    }
    $nextCursor = $hasMore && $items
        ? (string)($items[count($items) - 1]['period_id'] ?? '')
        : '';

    return [
        'items' => $items,
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore && $nextCursor !== '',
    ];
}

function znews_weekly_review_creator_history(string $creatorUid, int $limit = 12): array
{
    return znews_weekly_review_creator_history_page($creatorUid, $limit)['items'];
}

function znews_weekly_review_creator_live_preview(string $creatorUid): array
{
    $period = znews_weekly_review_period();
    $metrics = znews_weekly_review_creator_metrics($creatorUid, $period);
    if (empty($metrics['ok'])) {
        return ['ok' => false, 'code' => (string)($metrics['code'] ?? 'ZNEWS_WEEKLY_METRICS_FAILED')];
    }
    $registry = fb_get(znews_creator_registry_path($creatorUid));
    $registry = is_array($registry) ? $registry : [];
    $row = array_merge($period, (array)$metrics['metrics'], [
        'creator_uid' => $creatorUid,
        'creator_name' => trim((string)($registry['name'] ?? 'Z-Pay creator')),
        'creator_status' => znews_creator_normalize_status($registry['status'] ?? 'ACTIVE'),
        'review_status' => 'UNDER_REVIEW',
        'review_reason' => '',
        'traffic_share_ppm' => 0,
        'traffic_share_percent' => 0.0,
        'traffic_share_pending' => true,
        'generated_at' => znews_now(),
        'reviewed_at' => 0,
        'reviewed_by' => '',
        'live_preview' => true,
    ]);
    return ['ok' => true, 'review' => znews_weekly_review_creator_row($row)];
}
