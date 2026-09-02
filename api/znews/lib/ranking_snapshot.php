<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_ranking_metrics_defaults(): array
{
    return [
        'impressions' => 0,
        'unique_impressions' => 0,
        'valid_views' => 0,
        'unique_views' => 0,
        'total_opens' => 0,
        'last_shown_at' => 0,
        'updated_at' => 0,
    ];
}

function znews_ranking_metrics_from_index_row(array $row): array
{
    $metrics = is_array($row['ranking_metrics'] ?? null)
        ? (array)$row['ranking_metrics']
        : [];
    $normalized = znews_ranking_metrics_defaults();
    foreach ($normalized as $field => $_value) {
        $normalized[$field] = max(0, (int)($metrics[$field] ?? 0));
    }
    return $normalized;
}

function znews_ranking_metrics_log_failure(string $category): void
{
    $category = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $category) ?? 'UNKNOWN');
    error_log('ZNEWS_RANKING_CACHE_MIRROR_FAILED:' . ($category !== '' ? $category : 'UNKNOWN'));
}

function znews_ranking_metrics_mirror(string $postId, array $values, string $category): bool
{
    $postId = znews_firebase_key($postId, 'post_id');
    $feedPath = 'ZNEWS_PUBLIC_FEED/' . $postId;
    $feedRow = fb_get($feedPath);
    if (!is_array($feedRow)
        || strtoupper(trim((string)($feedRow['status'] ?? ''))) !== 'ACTIVE'
        || strtoupper(trim((string)($feedRow['visibility'] ?? ''))) !== 'PUBLIC') {
        return true;
    }

    $allowed = array_keys(znews_ranking_metrics_defaults());
    $provided = [];
    foreach ($values as $field => $value) {
        if (in_array((string)$field, $allowed, true)) {
            $provided[(string)$field] = max(0, (int)$value);
        }
    }
    if (!$provided) {
        return true;
    }

    $metricsPath = $feedPath . '/ranking_metrics';
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $snapshot = fb_get_with_etag($metricsPath);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            znews_ranking_metrics_log_failure($category . '_READ');
            return false;
        }

        $current = znews_ranking_metrics_defaults();
        $stored = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        foreach ($current as $field => $_value) {
            $current[$field] = max(0, (int)($stored[$field] ?? 0));
        }
        foreach ($provided as $field => $value) {
            $current[$field] = max($current[$field], $value);
        }

        $write = fb_put_if_match($metricsPath, $current, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(30000);
            continue;
        }
        if (empty($write['ok'])) {
            znews_ranking_metrics_log_failure($category . '_WRITE');
            return false;
        }
        return true;
    }

    znews_ranking_metrics_log_failure($category . '_BUSY');
    return false;
}

function znews_ranking_metrics_mirror_analytics(string $postId, array $analytics): bool
{
    return znews_ranking_metrics_mirror($postId, [
        'valid_views' => $analytics['valid_views'] ?? 0,
        'unique_views' => $analytics['unique_viewers'] ?? 0,
        'total_opens' => $analytics['total_opens'] ?? 0,
        'updated_at' => $analytics['updated_at'] ?? znews_now(),
    ], 'ANALYTICS');
}

function znews_ranking_metrics_mirror_exposure(string $postId, array $exposure): bool
{
    return znews_ranking_metrics_mirror($postId, [
        'impressions' => $exposure['impressions'] ?? 0,
        'unique_impressions' => $exposure['unique_viewers'] ?? 0,
        'last_shown_at' => $exposure['last_shown_at'] ?? 0,
        'updated_at' => $exposure['updated_at'] ?? znews_now(),
    ], 'EXPOSURE');
}
