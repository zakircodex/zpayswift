<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/weekly_live_projection.php';

function znews_weekly_live_projection_backfill(
    string $creatorUid,
    array $period,
    bool $dryRun = true
): array {
    $creatorUid = znews_firebase_key($creatorUid, 'creator_uid');
    $periodId = znews_firebase_key((string)($period['period_id'] ?? ''), 'period_id');
    $start = max(0, (int)($period['period_start_at'] ?? 0));
    $end = max(0, (int)($period['period_end_at'] ?? 0));
    if ($periodId === '' || $start <= 0 || $end <= $start) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_PERIOD_INVALID'];
    }

    $postIndex = fb_get('ZNEWS_USER_POSTS/' . $creatorUid, [
        'orderBy' => json_encode('$key'),
        'limitToFirst' => 501,
    ]);
    $postIndex = is_array($postIndex) ? $postIndex : [];
    if (count($postIndex) > 500) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_POST_SOURCE_LIMIT_EXCEEDED'];
    }

    $existing = fb_get(znews_weekly_live_projection_path($creatorUid, $periodId), [
        'orderBy' => json_encode('$key'),
        'limitToFirst' => 5001,
    ]);
    $existing = is_array($existing) ? $existing : [];
    $updates = [];
    $scannedPosts = 0;
    $scannedViews = 0;
    $periodViews = 0;
    $wouldUpdate = 0;
    $unchanged = 0;
    $malformed = 0;

    foreach ($postIndex as $postKey => $postRef) {
        $postRef = is_array($postRef) ? $postRef : [];
        $postId = trim((string)($postRef['post_id'] ?? $postKey));
        if ($postId === '') {
            $malformed++;
            continue;
        }
        $scannedPosts++;
        $viewIndex = fb_get('ZNEWS_POST_VIEWS/' . znews_firebase_key($postId, 'post_id'), [
            'orderBy' => json_encode('$key'),
            'limitToFirst' => 5001,
        ]);
        if (!is_array($viewIndex)) {
            continue;
        }
        $scannedViews += count($viewIndex);
        if ($scannedViews > 5000) {
            return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_VIEW_SOURCE_LIMIT_EXCEEDED'];
        }

        foreach ($viewIndex as $viewKey => $indexRow) {
            if (!is_array($indexRow)) {
                $malformed++;
                continue;
            }
            $timestamp = max(0, (int)($indexRow['completed_at'] ?? 0));
            if ($timestamp <= 0) {
                $timestamp = max(0, (int)($indexRow['created_at'] ?? 0));
            }
            if ($timestamp < $start || $timestamp >= $end) {
                continue;
            }
            $viewId = trim((string)($indexRow['view_id'] ?? $viewKey));
            if ($viewId === '') {
                $malformed++;
                continue;
            }
            $session = fb_get(znews_view_path(znews_firebase_key($viewId, 'view_id')));
            if (!is_array($session)) {
                $malformed++;
                continue;
            }
            $periodViews++;
            $desired = znews_weekly_live_projection_row(
                array_merge($indexRow, $session),
                $creatorUid
            );
            $saved = is_array($existing[$viewId] ?? null) ? (array)$existing[$viewId] : [];
            if ($saved === $desired) {
                $unchanged++;
                continue;
            }
            $updates[znews_weekly_live_projection_view_path($creatorUid, $periodId, $viewId)] = $desired;
            $wouldUpdate++;
        }
    }

    if (!$dryRun && $updates && !fb_patch('', $updates)) {
        return ['ok' => false, 'code' => 'ZNEWS_WEEKLY_PROJECTION_BACKFILL_WRITE_FAILED'];
    }
    if (!$dryRun && $updates) {
        znews_weekly_preview_cache_forget($creatorUid, $periodId);
    }

    return [
        'ok' => $malformed === 0,
        'code' => $malformed === 0 ? 'ZNEWS_WEEKLY_PROJECTION_BACKFILL_OK' : 'ZNEWS_WEEKLY_PROJECTION_BACKFILL_MALFORMED',
        'mode' => $dryRun ? 'dry-run' : 'apply',
        'period_id' => $periodId,
        'posts_scanned' => $scannedPosts,
        'view_index_rows_scanned' => $scannedViews,
        'current_period_views' => $periodViews,
        'would_update' => $wouldUpdate,
        'unchanged' => $unchanged,
        'malformed_or_missing' => $malformed,
        'applied_paths' => $dryRun ? 0 : count($updates),
        'canonical_roots_modified' => [],
    ];
}
