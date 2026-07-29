<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_instant_comments_enabled(): bool
{
    if (!defined('ZNEWS_INSTANT_COMMENTS_ENABLED')) {
        return true;
    }

    return filter_var(
        constant('ZNEWS_INSTANT_COMMENTS_ENABLED'),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true;
}

function znews_comment_review_terms(): array
{
    if (!defined('ZNEWS_COMMENT_REVIEW_TERMS')) {
        return [];
    }

    $value = constant('ZNEWS_COMMENT_REVIEW_TERMS');
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

function znews_comment_review_reason(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return 'COMMENT_REQUIRED';
    }

    $linkCount = preg_match_all('/(?:https?:\/\/|www\.)/iu', $text);
    if (is_int($linkCount) && $linkCount > 2) {
        return 'EXCESSIVE_LINKS';
    }

    if (preg_match('/(.)\1{11,}/u', $text) === 1) {
        return 'REPETITIVE_SPAM';
    }

    foreach (znews_comment_review_terms() as $term) {
        if (stripos($text, $term) !== false) {
            return 'CONFIGURED_COMMENT_REVIEW';
        }
    }

    return '';
}

function znews_comment_publication_decision(string $text): array
{
    if (!znews_instant_comments_enabled()) {
        return [
            'publish' => false,
            'status' => 'REVIEW',
            'moderation_status' => 'PENDING',
            'mode' => 'PRE_PUBLICATION_REVIEW',
            'reason' => 'INSTANT_COMMENTS_DISABLED',
        ];
    }

    $reason = znews_comment_review_reason($text);
    if ($reason !== '') {
        return [
            'publish' => false,
            'status' => 'REVIEW',
            'moderation_status' => 'PENDING',
            'mode' => 'AUTOMATED_COMMENT_REVIEW',
            'reason' => $reason,
        ];
    }

    return [
        'publish' => true,
        'status' => 'ACTIVE',
        'moderation_status' => 'APPROVED',
        'mode' => 'INSTANT_PUBLISH',
        'reason' => 'COMMENT_CHECKS_CLEAR',
    ];
}

function znews_apply_comment_publication_decision(array $comment, array $decision, int $now): array
{
    $publish = !empty($decision['publish']);
    $comment['status'] = (string)($decision['status'] ?? ($publish ? 'ACTIVE' : 'REVIEW'));
    $comment['moderation_status'] = (string)($decision['moderation_status'] ?? ($publish ? 'APPROVED' : 'PENDING'));
    $comment['publication_mode'] = (string)($decision['mode'] ?? ($publish ? 'INSTANT_PUBLISH' : 'PRE_PUBLICATION_REVIEW'));
    $comment['publication_reason'] = (string)($decision['reason'] ?? '');
    $comment['published_at'] = $publish ? $now : 0;
    $comment['auto_moderated_at'] = $now;

    return $comment;
}

function znews_comment_review_queue_row(array $comment): ?array
{
    if (znews_comment_is_public($comment)) {
        return null;
    }

    return [
        'comment_id' => (string)($comment['comment_id'] ?? ''),
        'post_id' => (string)($comment['post_id'] ?? ''),
        'author_uid' => (string)($comment['author_uid'] ?? ''),
        'created_at' => (int)($comment['created_at'] ?? 0),
        'updated_at' => (int)($comment['updated_at'] ?? 0),
    ];
}
