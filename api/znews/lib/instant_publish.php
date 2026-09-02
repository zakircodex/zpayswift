<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/public_projection.php';

function znews_instant_publish_enabled(): bool
{
    if (!defined('ZNEWS_INSTANT_PUBLISH_ENABLED')) {
        return true;
    }

    return filter_var(
        constant('ZNEWS_INSTANT_PUBLISH_ENABLED'),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true;
}

function znews_text_review_terms(): array
{
    if (!defined('ZNEWS_TEXT_REVIEW_TERMS')) {
        return [];
    }

    $value = constant('ZNEWS_TEXT_REVIEW_TERMS');
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded)
            ? $decoded
            : preg_split('/[\r\n,]+/', $value);
    }
    if (!is_array($value)) {
        return [];
    }

    $terms = [];
    foreach ($value as $term) {
        $term = trim((string)$term);
        if ($term !== '' && strlen($term) >= 3 && strlen($term) <= 100) {
            $terms[] = $term;
        }
    }

    return array_values(array_unique($terms));
}

function znews_text_review_reason(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $linkCount = preg_match_all('/(?:https?:\/\/|www\.)/iu', $text);
    if (is_int($linkCount) && $linkCount > 2) {
        return 'EXCESSIVE_LINKS';
    }

    if (preg_match('/(.)\1{11,}/u', $text) === 1) {
        return 'REPETITIVE_SPAM';
    }

    foreach (znews_text_review_terms() as $term) {
        if (stripos($text, $term) !== false) {
            return 'CONFIGURED_TEXT_REVIEW';
        }
    }

    return '';
}

function znews_post_publication_decision(array $mediaRow = [], string $text = ''): array
{
    if (!znews_instant_publish_enabled()) {
        return [
            'publish' => false,
            'status' => 'REVIEW',
            'moderation_status' => 'PENDING',
            'copyright_status' => 'PENDING',
            'mode' => 'PRE_PUBLICATION_REVIEW',
            'reason' => 'INSTANT_PUBLISH_DISABLED',
        ];
    }

    $textReviewReason = znews_text_review_reason($text);
    if ($textReviewReason !== '') {
        return [
            'publish' => false,
            'status' => 'REVIEW',
            'moderation_status' => 'PENDING',
            'copyright_status' => $mediaRow ? 'PENDING' : 'NOT_APPLICABLE',
            'mode' => 'AUTOMATED_TEXT_REVIEW',
            'reason' => $textReviewReason,
        ];
    }

    if (!$mediaRow) {
        return [
            'publish' => true,
            'status' => 'ACTIVE',
            'moderation_status' => 'APPROVED',
            'copyright_status' => 'NOT_APPLICABLE',
            'mode' => 'INSTANT_PUBLISH',
            'reason' => 'TEXT_CHECKS_CLEAR',
        ];
    }

    $duplicateStatus = strtoupper(trim((string)($mediaRow['duplicate_status'] ?? 'CLEAR')));
    $nearDuplicateCount = max(0, (int)($mediaRow['near_duplicate_count'] ?? 0));
    $mediaModeration = strtoupper(trim((string)($mediaRow['moderation_status'] ?? 'PENDING')));

    $requiresReview = $duplicateStatus !== 'CLEAR'
        || $nearDuplicateCount > 0
        || $mediaModeration === 'REVIEW_REQUIRED';

    if ($requiresReview) {
        return [
            'publish' => false,
            'status' => 'REVIEW',
            'moderation_status' => 'PENDING',
            'copyright_status' => 'PENDING',
            'mode' => 'AUTOMATED_RISK_REVIEW',
            'reason' => $duplicateStatus !== 'CLEAR'
                ? $duplicateStatus
                : 'NEAR_DUPLICATE_REVIEW',
        ];
    }

    return [
        'publish' => true,
        'status' => 'ACTIVE',
        'moderation_status' => 'APPROVED',
        'copyright_status' => 'AUTO_CLEARED',
        'mode' => 'INSTANT_PUBLISH',
        'reason' => 'MEDIA_CHECKS_CLEAR',
    ];
}

function znews_apply_publication_decision(array $post, array $decision, int $now): array
{
    $publish = !empty($decision['publish']);
    $post['status'] = (string)($decision['status'] ?? ($publish ? 'ACTIVE' : 'REVIEW'));
    $post['moderation_status'] = (string)($decision['moderation_status'] ?? ($publish ? 'APPROVED' : 'PENDING'));
    $post['copyright_status'] = (string)($decision['copyright_status'] ?? ($publish ? 'NOT_APPLICABLE' : 'PENDING'));
    $post['publication_mode'] = (string)($decision['mode'] ?? ($publish ? 'INSTANT_PUBLISH' : 'PRE_PUBLICATION_REVIEW'));
    $post['publication_reason'] = (string)($decision['reason'] ?? '');
    $post['published_at'] = $publish ? $now : 0;
    $post['auto_published_at'] = $publish ? $now : 0;
    $post['auto_moderated_at'] = $now;

    return $post;
}

function znews_public_feed_index_for_post(
    array $post,
    array $existingIndex = [],
    ?array $canonicalEngagement = null
): ?array
{
    return znews_public_projection_for_post($post, $existingIndex, $canonicalEngagement);
}

function znews_public_feed_index_updates_for_post(array $post): array
{
    return znews_public_projection_updates_for_post($post);
}

function znews_apply_media_publication_decision(array $row, array $decision, int $now): array
{
    if (empty($decision['publish'])) {
        return $row;
    }

    $row['moderation_status'] = 'APPROVED';
    $row['copyright_status'] = (string)($decision['copyright_status'] ?? 'AUTO_CLEARED');
    $row['reviewed_by_uid'] = 'SYSTEM';
    $row['reviewed_by_name'] = 'Automated checks';
    $row['reviewed_at'] = $now;
    $row['auto_published_at'] = $now;
    $row['updated_at'] = $now;

    return $row;
}
