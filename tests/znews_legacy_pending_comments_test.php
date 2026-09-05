<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$commentDb = [];
$commentVersions = [];
$commentCount = 0;

define('ZNEWS_COMMENT_REVIEW_TERMS', json_encode(['manual-review-term']));

function znews_legacy_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_now(): int
{
    return 1788134400;
}

function znews_firebase_key($value, string $field = 'id'): string
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid ' . $field);
    }
    return $value;
}

function znews_comment_is_public(array $comment): bool
{
    return strtoupper((string)($comment['status'] ?? '')) === 'ACTIVE'
        && strtoupper((string)($comment['moderation_status'] ?? '')) === 'APPROVED'
        && (int)($comment['deleted_at'] ?? 0) === 0;
}

function znews_comment_path(string $postId, string $commentId): string
{
    return 'ZNEWS_COMMENTS/' . $postId . '/' . $commentId;
}

function znews_comment_review_queue_path(string $commentId): string
{
    return 'ZNEWS_COMMENT_REVIEW_QUEUE/' . $commentId;
}

function znews_comment_user_index_path(string $uid, string $commentId): string
{
    return 'ZNEWS_COMMENTS_BY_USER/' . $uid . '/' . $commentId;
}

function fb_get(string $path, array $query = [])
{
    global $commentDb;
    if ($path === 'ZNEWS_COMMENT_REVIEW_QUEUE') {
        $rows = [];
        foreach ($commentDb as $key => $value) {
            if (str_starts_with($key, $path . '/') && is_array($value)) {
                $rows[substr($key, strlen($path) + 1)] = $value;
            }
        }
        return $rows;
    }
    return $commentDb[$path] ?? null;
}

function fb_get_with_etag(string $path): array
{
    global $commentDb, $commentVersions;
    return ['ok' => true, 'value' => $commentDb[$path] ?? null, 'etag' => 'v' . (int)($commentVersions[$path] ?? 0)];
}

function fb_put_if_match(string $path, $value, string $etag): array
{
    global $commentDb, $commentVersions;
    if ($etag !== 'v' . (int)($commentVersions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    $commentDb[$path] = $value;
    $commentVersions[$path] = (int)($commentVersions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

function fb_patch(string $path, array $patch): bool
{
    global $commentDb, $commentVersions;
    if ($path !== '') {
        return false;
    }
    foreach ($patch as $childPath => $value) {
        if ($value === null) {
            unset($commentDb[$childPath]);
        } else {
            $commentDb[$childPath] = $value;
        }
        $commentVersions[$childPath] = (int)($commentVersions[$childPath] ?? 0) + 1;
    }
    return true;
}

function znews_comment_recount(string $postId): array
{
    global $commentCount, $commentDb;
    $commentCount = 0;
    foreach ($commentDb as $path => $row) {
        if (str_starts_with($path, 'ZNEWS_COMMENTS/' . $postId . '/')
            && is_array($row)
            && znews_comment_is_public($row)) {
            $commentCount++;
        }
    }
    return ['ok' => true, 'counts' => ['comment_count' => $commentCount]];
}

require_once $root . '/api/znews/lib/comments/publication.php';
require_once $root . '/api/znews/lib/comments/legacy_pending_audit.php';

$base = [
    'post_id' => 'POST_A',
    'author_uid' => 'USER_A',
    'status' => 'REVIEW',
    'moderation_status' => 'PENDING',
    'publication_mode' => 'PRE_PUBLICATION_REVIEW',
    'created_at' => 100,
    'updated_at' => 100,
    'deleted_at' => 0,
];
$commentDb['ZNEWS_COMMENTS/POST_A/CLEAN_A'] = $base + ['comment_id' => 'CLEAN_A', 'text' => 'Nice post'];
$commentDb['ZNEWS_COMMENTS/POST_A/RISK_A'] = $base + ['comment_id' => 'RISK_A', 'text' => 'https://a.test https://b.test https://c.test'];
$commentDb['ZNEWS_COMMENT_REVIEW_QUEUE/CLEAN_A'] = ['comment_id' => 'CLEAN_A', 'post_id' => 'POST_A'];
$commentDb['ZNEWS_COMMENT_REVIEW_QUEUE/RISK_A'] = ['comment_id' => 'RISK_A', 'post_id' => 'POST_A'];

$dry = znews_legacy_comment_audit_run(['dry_run' => true, 'limit' => 10]);
znews_legacy_test_expect(!empty($dry['ok']) && (int)$dry['scanned'] === 2, 'Dry-run did not scan the bounded queue.');
znews_legacy_test_expect((int)$dry['would_approve'] === 1 && (int)$dry['remain_pending'] === 1, 'Dry-run decisions are wrong.');
znews_legacy_test_expect(($commentDb['ZNEWS_COMMENTS/POST_A/CLEAN_A']['status'] ?? '') === 'REVIEW', 'Dry-run mutated a comment.');

$apply = znews_legacy_comment_audit_run(['dry_run' => false, 'limit' => 10]);
znews_legacy_test_expect(!empty($apply['ok']) && (int)$apply['approved'] === 1, 'Clean legacy comment was not approved.');
$clean = $commentDb['ZNEWS_COMMENTS/POST_A/CLEAN_A'];
znews_legacy_test_expect(($clean['status'] ?? '') === 'ACTIVE' && ($clean['moderation_status'] ?? '') === 'APPROVED', 'Clean legacy state is not ACTIVE/APPROVED.');
znews_legacy_test_expect(($clean['publication_mode'] ?? '') === 'INSTANT_PUBLISH', 'Clean legacy publication mode is wrong.');
znews_legacy_test_expect(!isset($commentDb['ZNEWS_COMMENT_REVIEW_QUEUE/CLEAN_A']), 'Approved legacy queue item was not removed.');
znews_legacy_test_expect(isset($commentDb['ZNEWS_COMMENTS_BY_USER/USER_A/CLEAN_A']), 'Approved legacy user index was not restored.');
znews_legacy_test_expect($commentCount === 1, 'Approved comment count was not adjusted once.');

$risky = $commentDb['ZNEWS_COMMENTS/POST_A/RISK_A'];
znews_legacy_test_expect(($risky['status'] ?? '') === 'REVIEW' && ($risky['moderation_status'] ?? '') === 'PENDING', 'Risky legacy comment was blanket-approved.');
znews_legacy_test_expect(isset($commentDb['ZNEWS_COMMENT_REVIEW_QUEUE/RISK_A']), 'Risky legacy queue item was removed.');
$riskResult = array_values(array_filter($apply['results'], static fn(array $row): bool => ($row['comment_id'] ?? '') === 'RISK_A'))[0] ?? [];
znews_legacy_test_expect(($riskResult['reason'] ?? '') === 'EXCESSIVE_LINKS', 'Risky legacy reason is not exact.');

$again = znews_legacy_comment_audit_run(['dry_run' => false, 'limit' => 10]);
znews_legacy_test_expect((int)$again['approved'] === 0 && $commentCount === 1, 'Legacy apply is not idempotent.');

$commentDb['ZNEWS_COMMENTS/POST_A/PARTIAL_A'] = [
    'comment_id' => 'PARTIAL_A',
    'post_id' => 'POST_A',
    'author_uid' => 'USER_B',
    'text' => 'Already published before a side-effect interruption',
    'status' => 'ACTIVE',
    'moderation_status' => 'APPROVED',
    'publication_mode' => 'INSTANT_PUBLISH',
    'created_at' => 101,
    'updated_at' => 101,
    'published_at' => 101,
    'deleted_at' => 0,
    'legacy_review_audited_at' => 101,
    'legacy_review_reconciliation_reason' => 'COMMENT_CHECKS_CLEAR',
];
$commentDb['ZNEWS_COMMENT_REVIEW_QUEUE/PARTIAL_A'] = ['comment_id' => 'PARTIAL_A', 'post_id' => 'POST_A'];
$partial = znews_legacy_comment_audit_run(['dry_run' => false, 'limit' => 10]);
znews_legacy_test_expect((int)$partial['approved'] === 1, 'Interrupted legacy publication was not reconciled.');
znews_legacy_test_expect(!isset($commentDb['ZNEWS_COMMENT_REVIEW_QUEUE/PARTIAL_A']), 'Reconciled queue item was not removed.');
znews_legacy_test_expect($commentCount === 2, 'Exact recount did not include both public comments.');
$partialAgain = znews_legacy_comment_audit_run(['dry_run' => false, 'limit' => 10]);
znews_legacy_test_expect((int)$partialAgain['approved'] === 0 && $commentCount === 2, 'Reconciliation retry changed the exact comment count.');

$tool = (string)file_get_contents($root . '/api/tools/audit_znews_pending_comments.php');
znews_legacy_test_expect(str_contains($tool, "PHP_SAPI !== 'cli'") && str_contains($tool, '$dryRun = true'), 'Legacy audit tool is not CLI-only/dry-run by default.');
znews_legacy_test_expect(str_contains($tool, "app_private_config_path()"), 'Legacy audit tool does not require private backend configuration.');

echo "Z Sky legacy pending comment tests passed ({$assertions} assertions).\n";
