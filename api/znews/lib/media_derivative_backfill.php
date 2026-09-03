<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/media.php';

function znews_media_backfill_limit(int $limit): int
{
    return max(1, min(25, $limit));
}

function znews_media_derivative_is_current(array $row): bool
{
    $key = trim((string)($row['optimized_storage_key'] ?? ''));
    if ($key === '' || (string)($row['optimization_version'] ?? '') !== 'WEB_DERIVATIVE_V1') {
        return false;
    }
    try {
        $path = znews_media_resolve_path($key);
    } catch (Throwable $e) {
        return false;
    }
    return is_file($path)
        && is_readable($path)
        && max(0, (int)filesize($path)) === max(0, (int)($row['optimized_size_bytes'] ?? 0));
}

function znews_media_derivative_backfill_run(array $options = []): array
{
    $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
    $limit = znews_media_backfill_limit((int)($options['limit'] ?? 10));
    $cursor = trim((string)($options['cursor'] ?? ''));
    if ($cursor !== '') {
        $cursor = znews_firebase_key($cursor, 'cursor');
    }
    $query = [
        'orderBy' => json_encode('$key'),
        'limitToFirst' => $limit + ($cursor !== '' ? 2 : 1),
    ];
    if ($cursor !== '') {
        $query['startAt'] = json_encode($cursor);
    }
    $rows = fb_get('ZNEWS_MEDIA', $query);
    if ($rows !== null && !is_array($rows)) {
        return ['ok' => false, 'code' => 'ZNEWS_MEDIA_BACKFILL_READ_FAILED'];
    }
    $rows = is_array($rows) ? $rows : [];
    ksort($rows, SORT_STRING);
    if ($cursor !== '') {
        unset($rows[$cursor]);
    }
    $hasMore = count($rows) > $limit;
    $batch = array_slice($rows, 0, $limit, true);
    $scanned = 0;
    $wouldUpdate = 0;
    $unchanged = 0;
    $skipped = 0;
    $errors = 0;
    $originalBytes = 0;
    $optimizedBytes = 0;
    $lastKey = '';

    foreach ($batch as $mediaKey => $raw) {
        $scanned++;
        $lastKey = (string)$mediaKey;
        $row = is_array($raw) ? (array)$raw : [];
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if (!$row || $status === 'DELETED' || (int)($row['deleted_at'] ?? 0) > 0) {
            $skipped++;
            continue;
        }
        if (znews_media_derivative_is_current($row)) {
            $unchanged++;
            continue;
        }
        $mime = strtolower(trim((string)($row['mime'] ?? '')));
        if (!isset(znews_media_allowed_types()[$mime])) {
            $skipped++;
            continue;
        }
        try {
            $source = znews_media_resolve_path((string)($row['storage_key'] ?? ''));
        } catch (Throwable $e) {
            $errors++;
            continue;
        }
        if (!is_file($source) || !is_readable($source)) {
            $errors++;
            continue;
        }
        $optimized = znews_media_optimize_file($source, $mime);
        if (empty($optimized['ok'])) {
            $errors++;
            continue;
        }
        $originalBytes += max(0, (int)filesize($source));
        $optimizedBytes += max(0, (int)($optimized['size_bytes'] ?? 0));
        $wouldUpdate++;
        if ($dryRun) {
            @unlink((string)($optimized['tmp'] ?? ''));
            continue;
        }
        try {
            $metadata = znews_media_store_optimized((string)($row['media_id'] ?? $mediaKey), $optimized);
        } catch (Throwable $e) {
            @unlink((string)($optimized['tmp'] ?? ''));
            $errors++;
            continue;
        }
        if (!fb_patch(znews_media_path((string)($row['media_id'] ?? $mediaKey)), $metadata)) {
            try {
                @unlink(znews_media_resolve_path((string)$metadata['optimized_storage_key']));
            } catch (Throwable $e) {
            }
            $errors++;
        }
    }

    return [
        'ok' => $errors === 0,
        'mode' => $dryRun ? 'dry-run' : 'apply',
        'scanned' => $scanned,
        'would_update' => $wouldUpdate,
        'unchanged' => $unchanged,
        'skipped' => $skipped,
        'errors' => $errors,
        'original_bytes' => $originalBytes,
        'optimized_bytes' => $optimizedBytes,
        'saved_bytes' => max(0, $originalBytes - $optimizedBytes),
        'has_more' => $hasMore,
        'next_cursor' => $hasMore ? $lastKey : '',
    ];
}
