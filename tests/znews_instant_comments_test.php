<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

define('ZNEWS_COMMENT_REVIEW_TERMS', json_encode(['manual-review-term']));

function znews_comment_is_public(array $comment): bool
{
    return strtoupper(trim((string)($comment['status'] ?? ''))) === 'ACTIVE'
        && strtoupper(trim((string)($comment['moderation_status'] ?? ''))) === 'APPROVED'
        && (int)($comment['deleted_at'] ?? 0) === 0;
}

require_once $root . '/api/znews/lib/comments/publication.php';

function znews_comment_test_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function znews_comment_test_read(string $path): string
{
    $value = file_get_contents($path);
    if (!is_string($value)) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $value;
}

$clean = znews_comment_publication_decision('Thanks for sharing this update.');
znews_comment_test_expect(!empty($clean['publish']), 'Clean comment must publish immediately.');
znews_comment_test_expect(($clean['status'] ?? '') === 'ACTIVE', 'Clean comment status must be ACTIVE.');
znews_comment_test_expect(($clean['moderation_status'] ?? '') === 'APPROVED', 'Clean comment moderation must be APPROVED.');

$links = znews_comment_publication_decision('https://one.test https://two.test https://three.test');
znews_comment_test_expect(empty($links['publish']), 'Comment with more than two links must require review.');
znews_comment_test_expect(($links['reason'] ?? '') === 'EXCESSIVE_LINKS', 'Excessive link reason must be recorded.');

$spam = znews_comment_publication_decision(str_repeat('x', 12));
znews_comment_test_expect(empty($spam['publish']), 'Repetitive spam must require review.');
znews_comment_test_expect(($spam['reason'] ?? '') === 'REPETITIVE_SPAM', 'Repetitive spam reason must be recorded.');

$configured = znews_comment_publication_decision('This contains manual-review-term inside it.');
znews_comment_test_expect(empty($configured['publish']), 'Configured review terms must hold a comment.');
znews_comment_test_expect(($configured['reason'] ?? '') === 'CONFIGURED_COMMENT_REVIEW', 'Configured review reason must not expose the matched term.');

$now = 1234567890;
$publishedRow = znews_apply_comment_publication_decision([
    'comment_id' => 'ZNC1',
    'post_id' => 'ZNP1',
    'deleted_at' => 0,
], $clean, $now);
znews_comment_test_expect(znews_comment_is_public($publishedRow), 'Applied clean decision must create a public comment.');
znews_comment_test_expect((int)($publishedRow['published_at'] ?? 0) === $now, 'Instant comment must record published_at.');
znews_comment_test_expect(znews_comment_review_queue_row($publishedRow) === null, 'Public comment must not enter the review queue.');

$reviewRow = znews_apply_comment_publication_decision([
    'comment_id' => 'ZNC2',
    'post_id' => 'ZNP1',
    'author_uid' => 'USER1',
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => 0,
], $links, $now);
znews_comment_test_expect(!znews_comment_is_public($reviewRow), 'Risky comment must not be public.');
znews_comment_test_expect(is_array(znews_comment_review_queue_row($reviewRow)), 'Risky comment must enter the review queue.');

$create = znews_comment_test_read($root . '/api/znews/lib/comments/create.php');
$update = znews_comment_test_read($root . '/api/znews/lib/comments/update.php');
$moderation = znews_comment_test_read($root . '/api/znews/lib/comments/moderation.php');
$endpoint = znews_comment_test_read($root . '/api/znews/comments/create.php');
$ui = znews_comment_test_read($root . '/znews/assets/znews-instant-comments.js');

znews_comment_test_expect(str_contains($create, 'znews_comment_publication_decision($text)'), 'Create flow must use automated publication decision.');
znews_comment_test_expect(str_contains($create, "znews_engagement_adjust_counter(\$postId, 'comment_count', 1)"), 'Instant create must increment public comment count.');
znews_comment_test_expect(str_contains($update, 'znews_comment_publication_decision($text)'), 'Edit flow must re-run automated comment checks.');
znews_comment_test_expect(str_contains($update, '$wasPublic !== $isPublic'), 'Edit flow must reconcile public comment count transitions.');
znews_comment_test_expect(str_contains($moderation, '$postPublicationReject'), 'Admin moderation must identify live-comment rejection.');
znews_comment_test_expect(str_contains($moderation, "'comment_count', -1"), 'Blocking a live comment must decrement the public count.');
znews_comment_test_expect(str_contains($endpoint, "'published_immediately' => \$published"), 'Comment endpoint must expose instant-publication result.');
znews_comment_test_expect(str_contains($ui, "toast('Comment published.')"), 'Web UI must confirm instant comment publication.');
znews_comment_test_expect(!str_contains($ui, 'Unlock with PIN'), 'Comment UI must not reference removed PIN login.');

if ($failures) {
    fwrite(STDERR, "Z News instant comment contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z News instant comment contract passed.\n";
