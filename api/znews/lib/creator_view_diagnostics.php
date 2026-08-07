<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_view_diagnostic_reason_codes(array $view): array
{
    $reasons = [];
    foreach ((array)($view['risk_reasons'] ?? []) as $reason) {
        $reason = strtoupper(trim((string)$reason));
        if ($reason !== '') {
            $reasons[$reason] = true;
        }
    }
    return $reasons;
}

function znews_view_diagnostic_label(string $code): string
{
    return match ($code) {
        'GUEST_RATE_LIMIT' => 'Too many guest views in a short time',
        'BOT_TRAFFIC' => 'Automated or bot traffic',
        'DUPLICATE_VIEW' => 'Duplicate view',
        'SESSION_EXPIRED' => 'View session expired',
        'READ_TIME_TOO_SHORT' => 'Reading time under ' . znews_view_min_read() . ' seconds',
        'ACTIVE_TIME_TOO_SHORT' => 'Active reading under ' . znews_view_min_active() . ' seconds',
        'HEARTBEAT_REQUIRED' => 'Reading activity was not confirmed',
        'ACTIVITY_GAP' => 'Reading activity was interrupted',
        'VERIFICATION_UNAVAILABLE' => 'View verification was temporarily unavailable',
        'BROWSER_VERIFICATION' => 'Browser verification was incomplete',
        'POLICY_NOT_ELIGIBLE' => 'Guest revenue eligibility check failed',
        'RISK_CHECK_FAILED' => 'Traffic risk checks failed',
        default => 'View validation failed',
    };
}

function znews_view_diagnostic_primary_reason(array $view): string
{
    $reasons = znews_view_diagnostic_reason_codes($view);

    if (!empty($view['guest_spam'])
        || isset($reasons['GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'])
        || isset($reasons['MINUTE_RATE_EXCEEDED'])
        || isset($reasons['HOURLY_RATE_EXCEEDED'])) {
        return 'GUEST_RATE_LIMIT';
    }
    if (!empty($view['bot_detected'])
        || isset($reasons['BOT_DETECTED'])
        || isset($reasons['BOT_USER_AGENT'])) {
        return 'BOT_TRAFFIC';
    }
    if (!empty($view['duplicate'])
        || isset($reasons['DUPLICATE_VIEW'])
        || isset($reasons['DEDUPLICATE_WINDOW_MATCH'])) {
        return 'DUPLICATE_VIEW';
    }
    if (isset($reasons['SESSION_EXPIRED'])
        || strtoupper(trim((string)($view['status'] ?? ''))) === 'EXPIRED') {
        return 'SESSION_EXPIRED';
    }
    if (isset($reasons['READ_TIME_TOO_SHORT'])) {
        return 'READ_TIME_TOO_SHORT';
    }
    if (isset($reasons['ACTIVE_TIME_TOO_SHORT'])) {
        return 'ACTIVE_TIME_TOO_SHORT';
    }
    if (isset($reasons['HEARTBEAT_REQUIRED'])) {
        return 'HEARTBEAT_REQUIRED';
    }
    if (isset($reasons['HEARTBEAT_GAP_TOO_LARGE'])) {
        return 'ACTIVITY_GAP';
    }
    if (isset($reasons['DEDUP_CHECK_UNAVAILABLE'])
        || isset($reasons['GUEST_VIEW_WINDOW_UNAVAILABLE'])
        || isset($reasons['GUEST_VIEW_WINDOW_WRITE_FAILED'])
        || isset($reasons['GUEST_VIEW_WINDOW_BUSY'])) {
        return 'VERIFICATION_UNAVAILABLE';
    }
    if (isset($reasons['USER_AGENT_MISSING'])) {
        return 'BROWSER_VERIFICATION';
    }
    if (array_key_exists('revenue_share_eligible', $view)
        && empty($view['revenue_share_eligible'])) {
        return 'POLICY_NOT_ELIGIBLE';
    }
    if (max(0, (int)($view['risk_score'] ?? 0)) >= znews_view_risk_threshold()) {
        return 'RISK_CHECK_FAILED';
    }
    return 'OTHER';
}

function znews_view_diagnostic_is_creator_view(array $view, string $creatorUid): bool
{
    $viewerUid = trim((string)($view['viewer_uid'] ?? ''));
    return $viewerUid !== ''
        || strtoupper(trim((string)($view['viewer_class'] ?? ''))) === 'CREATOR'
        || !empty($view['self_view'])
        || ($viewerUid !== '' && hash_equals($creatorUid, $viewerUid));
}

function znews_view_diagnostic_is_invalid_guest(array $view): bool
{
    $status = strtoupper(trim((string)($view['status'] ?? '')));
    if ($status !== 'COMPLETED' && $status !== 'BLOCKED' && $status !== 'EXPIRED') {
        return false;
    }

    $result = strtoupper(trim((string)($view['result'] ?? 'PENDING')));
    $duplicate = !empty($view['duplicate']);
    $spam = znews_weekly_review_view_is_spam($view);
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

    return !$eligible;
}

function znews_view_diagnostics_creator_period(string $creatorUid, array $period): array
{
    $creatorUid = znews_firebase_key($creatorUid, 'creator_uid');
    $start = max(0, (int)($period['period_start_at'] ?? 0));
    $end = max(0, (int)($period['period_end_at'] ?? 0));
    if ($start <= 0 || $end <= $start) {
        return ['ok' => false, 'code' => 'ZNEWS_DIAGNOSTIC_PERIOD_INVALID'];
    }

    $postIndex = fb_get('ZNEWS_USER_POSTS/' . $creatorUid);
    $postIndex = is_array($postIndex) ? $postIndex : [];
    if (count($postIndex) > 500) {
        return ['ok' => false, 'code' => 'ZNEWS_DIAGNOSTIC_POST_SOURCE_LIMIT_EXCEEDED'];
    }

    $counts = [];
    $invalidTotal = 0;
    $sourceViews = 0;

    foreach ($postIndex as $postKey => $postRef) {
        $postRef = is_array($postRef) ? $postRef : [];
        $postId = trim((string)($postRef['post_id'] ?? $postKey));
        if ($postId === '') {
            continue;
        }
        $postId = znews_firebase_key($postId, 'post_id');
        $viewIndex = fb_get('ZNEWS_POST_VIEWS/' . $postId);
        if (!is_array($viewIndex)) {
            continue;
        }

        $sourceViews += count($viewIndex);
        if ($sourceViews > 5000) {
            return ['ok' => false, 'code' => 'ZNEWS_DIAGNOSTIC_VIEW_SOURCE_LIMIT_EXCEEDED'];
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
            $view = znews_weekly_review_load_view($viewId, $indexRow);
            $timestamp = znews_weekly_review_view_timestamp($view);
            if ($timestamp < $start || $timestamp >= $end) {
                continue;
            }
            if (znews_view_diagnostic_is_creator_view($view, $creatorUid)) {
                continue;
            }
            if (!znews_view_diagnostic_is_invalid_guest($view)) {
                continue;
            }

            $code = znews_view_diagnostic_primary_reason($view);
            $invalidTotal++;
            $counts[$code] = max(0, (int)($counts[$code] ?? 0)) + 1;
        }
    }

    arsort($counts, SORT_NUMERIC);
    $items = [];
    foreach ($counts as $code => $count) {
        $items[] = [
            'code' => (string)$code,
            'label' => znews_view_diagnostic_label((string)$code),
            'count' => max(0, (int)$count),
        ];
    }

    $parts = [];
    foreach (array_slice($items, 0, 3) as $item) {
        $parts[] = (string)$item['label'] . ' (' . (int)$item['count'] . ')';
    }
    $summary = $invalidTotal > 0
        ? 'Invalid reason: ' . implode(' • ', $parts)
        : '';

    return [
        'ok' => true,
        'invalid_total' => $invalidTotal,
        'items' => $items,
        'summary' => $summary,
        'privacy_safe' => true,
    ];
}
