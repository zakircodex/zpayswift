<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_weekly_live_projection_path(string $creatorUid, string $periodId): string
{
    return 'ZNEWS_WEEKLY_LIVE_VIEWS/'
        . znews_firebase_key($creatorUid, 'creator_uid')
        . '/'
        . znews_firebase_key($periodId, 'period_id');
}

function znews_weekly_live_projection_view_path(
    string $creatorUid,
    string $periodId,
    string $viewId
): string {
    return znews_weekly_live_projection_path($creatorUid, $periodId)
        . '/'
        . znews_firebase_key($viewId, 'view_id');
}

function znews_weekly_live_period_id(int $timestamp): string
{
    $timestamp = max(1, $timestamp);
    $date = (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('UTC'))
        ->setTime(0, 0, 0);
    $start = $date->modify('-' . (((int)$date->format('N')) - 1) . ' days');
    return $start->format('Y-m-d');
}

function znews_weekly_live_projection_timestamp(array $view): int
{
    $completedAt = max(0, (int)($view['completed_at'] ?? 0));
    return $completedAt > 0
        ? $completedAt
        : max(0, (int)($view['created_at'] ?? $view['started_at'] ?? 0));
}

function znews_weekly_live_projection_is_spam(array $view): bool
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

function znews_weekly_live_projection_row(array $view, string $creatorUid = ''): array
{
    $viewId = znews_firebase_key((string)($view['view_id'] ?? ''), 'view_id');
    $creatorUid = trim($creatorUid !== '' ? $creatorUid : (string)($view['creator_uid'] ?? ''));
    $creatorUid = znews_firebase_key($creatorUid, 'creator_uid');
    $createdAt = max(0, (int)($view['created_at'] ?? $view['started_at'] ?? 0));
    $completedAt = max(0, (int)($view['completed_at'] ?? 0));
    $viewerUid = trim((string)($view['viewer_uid'] ?? ''));
    $viewerClass = strtoupper(trim((string)($view['viewer_class'] ?? '')));

    return [
        'schema_version' => 1,
        'view_id' => $viewId,
        'status' => strtoupper(trim((string)($view['status'] ?? 'STARTED'))),
        'result' => strtoupper(trim((string)($view['result'] ?? 'PENDING'))),
        'creator_view' => $viewerUid !== '' || $viewerClass === 'CREATOR',
        'self_view' => !empty($view['self_view'])
            || ($viewerUid !== '' && hash_equals($creatorUid, $viewerUid)),
        'duplicate' => !empty($view['duplicate']),
        'spam_view' => znews_weekly_live_projection_is_spam($view),
        'bot_detected' => !empty($view['bot_detected']),
        'revenue_share_eligible' => array_key_exists('revenue_share_eligible', $view)
            ? !empty($view['revenue_share_eligible'])
            : true,
        'risk_blocked' => max(0, (int)($view['risk_score'] ?? 0)) >= znews_view_risk_threshold(),
        'active_seconds' => max(0, (int)($view['active_seconds'] ?? 0)),
        'created_at' => $createdAt,
        'completed_at' => $completedAt,
        'updated_at' => max($createdAt, $completedAt, max(0, (int)($view['updated_at'] ?? 0))),
    ];
}

function znews_weekly_live_projection_mirror(array $view, string $creatorUid = ''): bool
{
    $creatorUid = trim($creatorUid !== '' ? $creatorUid : (string)($view['creator_uid'] ?? ''));
    $viewId = trim((string)($view['view_id'] ?? ''));
    $timestamp = max(0, (int)($view['created_at'] ?? $view['started_at'] ?? 0));
    if ($creatorUid === '' || $viewId === '' || $timestamp <= 0) {
        return false;
    }

    try {
        $row = znews_weekly_live_projection_row($view, $creatorUid);
        $effectiveTimestamp = znews_weekly_live_projection_timestamp($view);
        $effectivePeriodId = znews_weekly_live_period_id($effectiveTimestamp);
        $createdPeriodId = znews_weekly_live_period_id($timestamp);
        if ($createdPeriodId !== $effectivePeriodId) {
            @fb_delete(znews_weekly_live_projection_view_path($creatorUid, $createdPeriodId, $viewId));
        }
        $ok = fb_put(znews_weekly_live_projection_view_path(
            $creatorUid,
            $effectivePeriodId,
            $viewId
        ), $row);
        if (!$ok) {
            error_log('ZNEWS_WEEKLY_PROJECTION_MIRROR_FAILED:WRITE');
        } else {
            znews_weekly_preview_cache_forget($creatorUid, $effectivePeriodId);
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('ZNEWS_WEEKLY_PROJECTION_MIRROR_FAILED:EXCEPTION');
        return false;
    }
}

function znews_weekly_live_projection_mirror_policy(
    array $session,
    string $creatorUid,
    array $policy,
    array $state = []
): bool {
    $viewId = trim((string)($session['view_id'] ?? ''));
    $createdAt = max(0, (int)($session['created_at'] ?? 0));
    if ($creatorUid === '' || $viewId === '' || $createdAt <= 0) {
        return false;
    }
    $viewerClass = strtoupper(trim((string)($policy['viewer_class'] ?? '')));
    $updates = [
        'creator_view' => $viewerClass === 'CREATOR',
        'self_view' => !empty($session['self_view']),
        'spam_view' => !empty($policy['guest_spam']),
        'revenue_share_eligible' => !empty($policy['revenue_share_eligible']),
        'updated_at' => max($createdAt, (int)($state['updated_at'] ?? znews_now())),
    ];
    foreach (['status', 'result', 'completed_at'] as $field) {
        if (array_key_exists($field, $state)) {
            $updates[$field] = $state[$field];
        }
    }
    if (!empty($policy['guest_spam'])) {
        $updates['risk_blocked'] = true;
    }

    try {
        $effectiveTimestamp = max(0, (int)($state['completed_at'] ?? 0));
        $effectiveTimestamp = $effectiveTimestamp > 0 ? $effectiveTimestamp : $createdAt;
        $effectivePeriodId = znews_weekly_live_period_id($effectiveTimestamp);
        $createdPeriodId = znews_weekly_live_period_id($createdAt);
        if ($createdPeriodId !== $effectivePeriodId) {
            @fb_delete(znews_weekly_live_projection_view_path($creatorUid, $createdPeriodId, $viewId));
        }
        $ok = fb_patch(znews_weekly_live_projection_view_path(
            $creatorUid,
            $effectivePeriodId,
            $viewId
        ), $updates);
        if (!$ok) {
            error_log('ZNEWS_WEEKLY_PROJECTION_MIRROR_FAILED:POLICY');
        } else {
            znews_weekly_preview_cache_forget($creatorUid, $effectivePeriodId);
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('ZNEWS_WEEKLY_PROJECTION_MIRROR_FAILED:POLICY_EXCEPTION');
        return false;
    }
}

function znews_weekly_preview_cache_ttl(): int
{
    $ttl = defined('ZNEWS_WEEKLY_PREVIEW_CACHE_TTL')
        ? (int)constant('ZNEWS_WEEKLY_PREVIEW_CACHE_TTL')
        : 45;
    return max(30, min(60, $ttl));
}

function znews_weekly_preview_cache_path(string $creatorUid, string $periodId): string
{
    $base = defined('ZNEWS_WEEKLY_PREVIEW_CACHE_DIR')
        ? trim((string)constant('ZNEWS_WEEKLY_PREVIEW_CACHE_DIR'))
        : rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'zsky24-weekly-preview';
    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
    }
    @chmod($base, 0700);
    $key = hash('sha256', $creatorUid . '|' . $periodId);
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'zsky-weekly-preview-' . $key . '.json';
}

function znews_weekly_preview_cache_read(string $creatorUid, string $periodId, int $now): ?array
{
    $path = znews_weekly_preview_cache_path($creatorUid, $periodId);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $cached = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cached)
        || (int)($cached['expires_at'] ?? 0) < $now
        || !is_array($cached['review'] ?? null)) {
        return null;
    }
    return (array)$cached['review'];
}

function znews_weekly_preview_cache_forget(string $creatorUid, string $periodId): void
{
    $path = znews_weekly_preview_cache_path($creatorUid, $periodId);
    if (is_file($path)) {
        @unlink($path);
    }
}

function znews_weekly_preview_cache_write(
    string $creatorUid,
    string $periodId,
    array $review,
    int $now
): bool {
    $path = znews_weekly_preview_cache_path($creatorUid, $periodId);
    $payload = json_encode([
        'schema_version' => 1,
        'expires_at' => $now + znews_weekly_preview_cache_ttl(),
        'review' => $review,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return false;
    }
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle)) {
        return false;
    }
    @chmod($path, 0600);
    $locked = @flock($handle, LOCK_EX);
    $written = false;
    if ($locked) {
        @ftruncate($handle, 0);
        @rewind($handle);
        $written = @fwrite($handle, $payload);
        @fflush($handle);
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);
    return $written === strlen($payload);
}
