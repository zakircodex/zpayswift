<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/rich_text.php';
require_once __DIR__ . '/ranking_snapshot.php';
require_once __DIR__ . '/categories.php';

function znews_public_projection_engagement_defaults(): array
{
    return [
        'like_count' => 0,
        'comment_count' => 0,
        'share_count' => 0,
        'source_revision' => 0,
        'updated_at' => 0,
    ];
}

function znews_public_projection_engagement(array $row): array
{
    $stored = is_array($row['engagement_snapshot'] ?? null)
        ? (array)$row['engagement_snapshot']
        : $row;
    $normalized = znews_public_projection_engagement_defaults();
    foreach (['like_count', 'comment_count', 'share_count', 'source_revision', 'updated_at'] as $field) {
        $normalized[$field] = max(0, (int)($stored[$field] ?? 0));
    }
    if (!isset($stored['source_revision']) && isset($stored['revision'])) {
        $normalized['source_revision'] = max(0, (int)$stored['revision']);
    }
    return $normalized;
}

function znews_public_projection_is_complete(array $row): bool
{
    foreach ([
        'post_id',
        'creator_uid',
        'creator_name',
        'creator_photo_url',
        'title',
        'text',
        'image_url',
        'content_type',
        'status',
        'visibility',
        'created_at',
        'updated_at',
    ] as $field) {
        if (!array_key_exists($field, $row)) {
            return false;
        }
    }

    $engagement = $row['engagement_snapshot'] ?? null;
    if (!is_array($engagement)) {
        return false;
    }
    foreach (['like_count', 'comment_count', 'share_count'] as $field) {
        if (!array_key_exists($field, $engagement)) {
            return false;
        }
    }
    return true;
}

function znews_public_projection_is_active(array $row): bool
{
    return strtoupper(trim((string)($row['status'] ?? ''))) === 'ACTIVE'
        && strtoupper(trim((string)($row['visibility'] ?? ''))) === 'PUBLIC';
}

function znews_public_projection_item(array $row): ?array
{
    if (!znews_public_projection_is_complete($row)
        || !znews_public_projection_is_active($row)) {
        return null;
    }

    $engagement = znews_public_projection_engagement($row);
    $public = [
        'post_id' => (string)$row['post_id'],
        'creator_uid' => (string)$row['creator_uid'],
        'creator_name' => (string)$row['creator_name'],
        'creator_photo_url' => (string)$row['creator_photo_url'],
        'title' => (string)$row['title'],
        'text' => (string)$row['text'],
        'bold_ranges' => znews_post_bold_ranges(
            $row['bold_ranges'] ?? [],
            (string)$row['text']
        ),
        'category' => strtoupper(trim((string)($row['category'] ?? ''))),
        'image_url' => (string)$row['image_url'],
        'image_width' => max(0, (int)($row['image_width'] ?? 0)),
        'image_height' => max(0, (int)($row['image_height'] ?? 0)),
        'content_type' => (string)$row['content_type'],
        'status' => (string)$row['status'],
        'visibility' => (string)$row['visibility'],
        'like_count' => (int)$engagement['like_count'],
        'comment_count' => (int)$engagement['comment_count'],
        'share_count' => (int)$engagement['share_count'],
        'created_at' => (int)$row['created_at'],
        'updated_at' => (int)$row['updated_at'],
    ];

    return znews_public_projection_format_public($public);
}

function znews_public_projection_format_public(array $post): array
{
    if (function_exists('znews_format_public_post')) {
        return znews_format_public_post($post);
    }

    return [
        'post_id' => trim((string)($post['post_id'] ?? '')),
        'creator_uid' => trim((string)($post['creator_uid'] ?? '')),
        'creator_name' => trim((string)($post['creator_name'] ?? 'Z-Pay User')),
        'creator_photo_url' => trim((string)($post['creator_photo_url'] ?? '')),
        'title' => trim((string)($post['title'] ?? '')),
        'text' => (string)($post['text'] ?? ''),
        'bold_ranges' => znews_post_bold_ranges(
            $post['bold_ranges'] ?? [],
            (string)($post['text'] ?? '')
        ),
        'category' => strtoupper(trim((string)($post['category'] ?? ''))),
        'image_url' => trim((string)($post['image_url'] ?? '')),
        'image_width' => max(0, (int)($post['image_width'] ?? 0)),
        'image_height' => max(0, (int)($post['image_height'] ?? 0)),
        'content_type' => strtoupper(trim((string)($post['content_type'] ?? 'TEXT'))),
        'visibility' => 'PUBLIC',
        'status' => strtoupper(trim((string)($post['status'] ?? 'REVIEW'))),
        'like_count' => max(0, (int)($post['like_count'] ?? 0)),
        'comment_count' => max(0, (int)($post['comment_count'] ?? 0)),
        'share_count' => max(0, (int)($post['share_count'] ?? 0)),
        'created_at' => (int)($post['created_at'] ?? 0),
        'updated_at' => (int)($post['updated_at'] ?? 0),
    ];
}

function znews_public_projection_for_post(
    array $post,
    array $existingProjection = [],
    ?array $canonicalEngagement = null
): ?array {
    if (strtoupper(trim((string)($post['status'] ?? ''))) !== 'ACTIVE'
        || strtoupper(trim((string)($post['visibility'] ?? 'PUBLIC'))) !== 'PUBLIC'
        || (int)($post['deleted_at'] ?? 0) > 0) {
        return null;
    }

    $formatted = znews_public_projection_format_public($post);
    $category = strtoupper(trim((string)($formatted['category'] ?? '')));
    if ($category !== '') {
        $category = znews_normalize_category($category, false);
    }
    $engagement = $canonicalEngagement !== null
        ? znews_public_projection_engagement($canonicalEngagement)
        : (is_array($existingProjection['engagement_snapshot'] ?? null)
            ? znews_public_projection_engagement($existingProjection)
            : znews_public_projection_engagement($post));

    return [
        'post_id' => (string)$formatted['post_id'],
        'creator_uid' => (string)$formatted['creator_uid'],
        'creator_name' => (string)$formatted['creator_name'],
        'creator_photo_url' => (string)$formatted['creator_photo_url'],
        'title' => (string)$formatted['title'],
        'text' => (string)$formatted['text'],
        'bold_ranges' => (array)($formatted['bold_ranges'] ?? []),
        'category' => $category,
        'category_created_at' => $category !== ''
            ? znews_category_created_at($category, (int)$formatted['created_at'])
            : '',
        'image_url' => (string)$formatted['image_url'],
        'image_width' => max(0, (int)($formatted['image_width'] ?? 0)),
        'image_height' => max(0, (int)($formatted['image_height'] ?? 0)),
        'content_type' => (string)$formatted['content_type'],
        'status' => 'ACTIVE',
        'visibility' => 'PUBLIC',
        'created_at' => (int)$formatted['created_at'],
        'updated_at' => (int)$formatted['updated_at'],
        'published_at' => (int)($post['published_at'] ?? $post['updated_at'] ?? 0),
        'engagement_snapshot' => $engagement,
        'ranking_metrics' => znews_ranking_metrics_from_index_row($existingProjection),
    ];
}

function znews_public_projection_updates_for_post(array $post): array
{
    $postId = znews_firebase_key((string)($post['post_id'] ?? ''), 'post_id');
    $path = znews_path_public_feed($postId);
    $row = znews_public_projection_for_post($post);
    if ($row === null) {
        return [$path => null];
    }

    unset($row['ranking_metrics'], $row['engagement_snapshot']);
    $updates = [];
    foreach ($row as $field => $value) {
        $updates[$path . '/' . $field] = $value;
    }
    return $updates;
}

function znews_public_projection_log_failure(string $category): void
{
    $category = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $category) ?? 'UNKNOWN');
    error_log('ZNEWS_PUBLIC_PROJECTION_MIRROR_FAILED:' . ($category !== '' ? $category : 'UNKNOWN'));
}

function znews_public_projection_mirror_engagement(string $postId, array $canonical): bool
{
    $postId = znews_firebase_key($postId, 'post_id');
    $feedPath = 'ZNEWS_PUBLIC_FEED/' . $postId;
    $feedRow = fb_get($feedPath);
    if (!is_array($feedRow) || !znews_public_projection_is_active($feedRow)) {
        return true;
    }

    $incoming = znews_public_projection_engagement($canonical);
    $snapshotPath = $feedPath . '/engagement_snapshot';
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $snapshot = fb_get_with_etag($snapshotPath);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            znews_public_projection_log_failure('ENGAGEMENT_READ');
            return false;
        }

        $current = is_array($snapshot['value'] ?? null)
            ? znews_public_projection_engagement((array)$snapshot['value'])
            : znews_public_projection_engagement_defaults();
        if ((int)$current['source_revision'] > (int)$incoming['source_revision']) {
            return true;
        }

        $write = fb_put_if_match($snapshotPath, $incoming, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(30000);
            continue;
        }
        if (empty($write['ok'])) {
            znews_public_projection_log_failure('ENGAGEMENT_WRITE');
            return false;
        }
        return true;
    }

    znews_public_projection_log_failure('ENGAGEMENT_BUSY');
    return false;
}
