<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/public_projection.php';

function znews_public_projection_backfill_limit(int $limit): int
{
    return max(1, min(100, $limit));
}

function znews_public_projection_backfill_query(int $limit, string $cursor = ''): array
{
    $query = [
        'orderBy' => json_encode('$key'),
        'limitToFirst' => znews_public_projection_backfill_limit($limit) + ($cursor !== '' ? 2 : 1),
    ];
    if ($cursor !== '') {
        $query['startAt'] = json_encode(znews_firebase_key($cursor, 'cursor'));
    }
    return $query;
}

function znews_public_projection_backfill_updates(
    string $postId,
    array $existing,
    array $desired
): array {
    $path = 'ZNEWS_PUBLIC_FEED/' . znews_firebase_key($postId, 'post_id');
    $updates = [];
    foreach ($desired as $field => $value) {
        if (!array_key_exists($field, $existing)
            || znews_public_projection_backfill_comparable($existing[$field])
                !== znews_public_projection_backfill_comparable($value)) {
            $updates[$path . '/' . $field] = $value;
        }
    }
    return $updates;
}

function znews_public_projection_backfill_comparable(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $normalized = [];
    foreach ($value as $key => $child) {
        $normalized[$key] = znews_public_projection_backfill_comparable($child);
    }
    if (!array_is_list($normalized)) {
        ksort($normalized, SORT_STRING);
    }
    return $normalized;
}

function znews_public_projection_backfill_run(array $options = []): array
{
    $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
    $limit = znews_public_projection_backfill_limit((int)($options['limit'] ?? 50));
    $cursor = trim((string)($options['cursor'] ?? ''));
    if ($cursor !== '') {
        $cursor = znews_firebase_key($cursor, 'cursor');
    }

    $rows = fb_get('ZNEWS_PUBLIC_FEED', znews_public_projection_backfill_query($limit, $cursor));
    if ($rows !== null && !is_array($rows)) {
        return ['ok' => false, 'code' => 'ZNEWS_PROJECTION_BACKFILL_READ_FAILED'];
    }
    $rows = is_array($rows) ? $rows : [];
    ksort($rows, SORT_STRING);
    if ($cursor !== '') {
        unset($rows[$cursor]);
    }

    $hasMore = count($rows) > $limit;
    $batch = array_slice($rows, 0, $limit, true);
    $updates = [];
    $scanned = 0;
    $wouldUpdate = 0;
    $wouldRemove = 0;
    $unchanged = 0;
    $payloadBytes = 0;
    $lastKey = '';

    foreach ($batch as $postKey => $existingRaw) {
        $scanned++;
        $lastKey = (string)$postKey;
        $existing = is_array($existingRaw) ? (array)$existingRaw : [];
        $postId = znews_firebase_key(
            (string)($existing['post_id'] ?? $postKey),
            'post_id'
        );
        $post = fb_get(znews_path_post($postId));
        if (!is_array($post) || !znews_post_is_public($post)) {
            $updates['ZNEWS_PUBLIC_FEED/' . $postId] = null;
            $wouldRemove++;
            continue;
        }

        $engagement = fb_get('ZNEWS_ENGAGEMENT/' . $postId);
        $desired = znews_public_projection_for_post(
            $post,
            $existing,
            is_array($engagement) ? (array)$engagement : null
        );
        if (!is_array($desired)) {
            $updates['ZNEWS_PUBLIC_FEED/' . $postId] = null;
            $wouldRemove++;
            continue;
        }

        $rowUpdates = znews_public_projection_backfill_updates($postId, $existing, $desired);
        if (!$rowUpdates) {
            $unchanged++;
            continue;
        }
        $updates = array_merge($updates, $rowUpdates);
        $wouldUpdate++;
        $encoded = json_encode($desired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadBytes += is_string($encoded) ? strlen($encoded) : 0;
    }

    if (!$dryRun && $updates && !fb_patch('', $updates)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_PROJECTION_BACKFILL_WRITE_FAILED',
            'scanned' => $scanned,
            'would_update' => $wouldUpdate,
            'would_remove' => $wouldRemove,
        ];
    }

    return [
        'ok' => true,
        'mode' => $dryRun ? 'dry-run' : 'apply',
        'scanned' => $scanned,
        'would_update' => $wouldUpdate,
        'would_remove' => $wouldRemove,
        'unchanged' => $unchanged,
        'applied_paths' => $dryRun ? 0 : count($updates),
        'projection_payload_bytes' => $payloadBytes,
        'has_more' => $hasMore,
        'next_cursor' => $hasMore ? $lastKey : '',
    ];
}
