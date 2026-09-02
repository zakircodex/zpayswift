<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_social_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_social_read(string $path): string
{
    znews_social_expect(is_file($path), "missing file: {$path}");
    $source = file_get_contents($path);
    znews_social_expect($source !== false, "unable to read: {$path}");
    return (string)$source;
}

$files = [
    'api/znews/lib/engagement.php',
    'api/znews/lib/likes.php',
    'api/znews/lib/comments.php',
    'api/znews/lib/shares.php',
    'api/znews/likes/set.php',
    'api/znews/likes/status.php',
    'api/znews/comments/create.php',
    'api/znews/comments/update.php',
    'api/znews/comments/delete.php',
    'api/znews/comments/list.php',
    'api/znews/shares/create.php',
    'api/znews/engagement/summary.php',
    'api/admin/znews/comments/queue.php',
    'api/admin/znews/comments/details.php',
    'api/admin/znews/comments/approve.php',
    'api/admin/znews/comments/reject.php',
    'api/znews/public/feed.php',
    'api/znews/public/post.php',
    'api/znews/posts/details.php',
    'api/znews/posts/mine.php',
];

foreach ($files as $relative) {
    $source = znews_social_read($root . '/' . $relative);
    znews_social_expect(
        str_contains($source, 'declare(strict_types=1);'),
        "{$relative} missing strict types"
    );
    znews_social_expect(
        !str_contains($source, 'lib/wallet.php')
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/')
        && !str_contains($source, 'wallet_credit_available')
        && !str_contains($source, 'wallet_debit_available'),
        "{$relative} touches existing wallet business logic"
    );
}

$engagement = znews_social_read($root . '/api/znews/lib/engagement.php');
znews_social_expect(str_contains($engagement, "return 'ZNEWS_ENGAGEMENT/'"), 'separate engagement counter namespace missing');
znews_social_expect(str_contains($engagement, 'fb_get_with_etag') && str_contains($engagement, 'fb_put_if_match'), 'engagement counters are not CAS protected');
znews_social_expect(str_contains($engagement, "['like_count', 'comment_count', 'share_count']"), 'engagement counter allowlist missing');
znews_social_expect(str_contains($engagement, 'ZNEWS_ENGAGEMENT_IDEMPOTENCY/'), 'engagement idempotency namespace missing');
znews_social_expect(str_contains($engagement, 'lease_expires_at'), 'engagement stale-claim lease missing');
znews_social_expect(str_contains($engagement, 'reconciliation_required'), 'engagement reconciliation replay handling missing');
znews_social_expect(!str_contains($engagement, "znews_path_post(\$postId), \$row"), 'engagement counters appear to overwrite post content node');

$likes = znews_social_read($root . '/api/znews/lib/likes.php');
znews_social_expect(str_contains($likes, 'ZNEWS_LIKES/') || str_contains($engagement, "return 'ZNEWS_LIKES/'"), 'like namespace missing');
znews_social_expect(str_contains($likes, 'znews_like_path($postId, $uid)'), 'per-user per-post like key missing');
znews_social_expect(str_contains($likes, 'fb_put_if_match'), 'like state lacks optimistic concurrency');
znews_social_expect(str_contains($likes, "'LIKE_SET'"), 'like idempotency action missing');
znews_social_expect(str_contains($likes, "'like_count'") && str_contains($likes, '$liked ? 1 : -1'), 'like counter adjustment missing');
znews_social_expect(str_contains($likes, 'hash_equals') === false, 'like library should rely on authenticated UID path, not compare client UID');

$likeSet = znews_social_read($root . '/api/znews/likes/set.php');
znews_social_expect(str_contains($likeSet, "api_require_method('POST')") && str_contains($likeSet, 'api_require_app_key();') && str_contains($likeSet, 'znews_require_creator(true)'), 'like mutation endpoint is not protected');
znews_social_expect(str_contains($likeSet, "array_key_exists('liked'"), 'like endpoint does not require explicit liked value');
znews_social_expect(!str_contains($likeSet, "['uid']") && !str_contains($likeSet, "['creator_uid']"), 'like endpoint appears to trust client UID');

$commentsAggregator = znews_social_read($root . '/api/znews/lib/comments.php');
$comments = $commentsAggregator
    . znews_social_read($root . '/api/znews/lib/comments/common.php')
    . znews_social_read($root . '/api/znews/lib/comments/publication.php')
    . znews_social_read($root . '/api/znews/lib/comments/create.php')
    . znews_social_read($root . '/api/znews/lib/comments/update.php')
    . znews_social_read($root . '/api/znews/lib/comments/delete.php')
    . znews_social_read($root . '/api/znews/lib/comments/access.php')
    . znews_social_read($root . '/api/znews/lib/comments/moderation.php');
znews_social_expect(str_contains($comments, 'ZNEWS_COMMENTS/') && str_contains($comments, 'ZNEWS_USER_COMMENTS/') && str_contains($comments, 'ZNEWS_COMMENT_REVIEW_QUEUE/'), 'comment namespaces or review queue missing');
znews_social_expect(str_contains($comments, 'znews_comment_publication_decision') && str_contains($comments, "'mode' => 'INSTANT_PUBLISH'"), 'clean comments are not eligible for instant publication');
znews_social_expect(str_contains($comments, "'status' => 'REVIEW'") && str_contains($comments, "'moderation_status' => 'PENDING'"), 'risky-comment review fallback is missing');
znews_social_expect(str_contains($comments, "status'] = 'DELETED'") && str_contains($comments, 'deleted_at'), 'comment delete is not soft delete');
znews_social_expect(str_contains($comments, 'expectedUpdatedAt') && str_contains($comments, 'ZNEWS_COMMENT_VERSION_CONFLICT') && str_contains($comments, 'fb_put_if_match'), 'comment edit/delete/moderation version protection missing');
znews_social_expect(str_contains($comments, "=== 'ACTIVE'") && str_contains($comments, "=== 'APPROVED'"), 'public comment eligibility does not require approval');
znews_social_expect(str_contains($comments, 'znews_engagement_adjust_counter') && str_contains($comments, "'comment_count'"), 'public comment counter update missing');
znews_social_expect(str_contains($comments, '$postPublicationReject') && str_contains($comments, "'comment_count', -1"), 'post-publication comment blocking/count reconciliation missing');
znews_social_expect(str_contains($comments, 'auth_require_admin_session') === false, 'comment library should not start admin auth side effects');
znews_social_expect(str_contains($comments, 'ZNEWS_COMMENT_MODERATION_ACTIONS/'), 'comment moderation audit trail missing');
znews_social_expect(
    str_contains($commentsAggregator, "\$_SERVER['SCRIPT_FILENAME'] = __FILE__ . '.aggregate';"),
    'comment aggregator does not neutralize create/update/delete basename collisions'
);
znews_social_expect(
    str_contains($commentsAggregator, "\$_SERVER['SCRIPT_FILENAME'] = \$znewsOriginalScriptFilename;"),
    'comment aggregator does not restore SCRIPT_FILENAME'
);

foreach (['create.php', 'update.php', 'delete.php'] as $name) {
    $source = znews_social_read($root . '/api/znews/comments/' . $name);
    znews_social_expect(str_contains($source, 'api_require_app_key();') && str_contains($source, 'znews_require_creator(true)'), "{$name} comment endpoint lacks creator protection");

    $librarySource = znews_social_read($root . '/api/znews/lib/comments/' . $name);
    znews_social_expect(
        str_contains($librarySource, "basename(__FILE__) === basename(\$_SERVER['SCRIPT_FILENAME'] ?? '')"),
        "{$name} comment library lost direct-execution protection"
    );
}

$publicComments = znews_social_read($root . '/api/znews/comments/list.php');
znews_social_expect(str_contains($publicComments, "api_require_method('GET')") && !str_contains($publicComments, 'api_require_app_key();') && !str_contains($publicComments, 'auth_require_user('), 'public comments list is not public GET-only');

foreach (['queue.php', 'details.php', 'approve.php', 'reject.php'] as $name) {
    $source = znews_social_read($root . '/api/admin/znews/comments/' . $name);
    znews_social_expect(str_contains($source, 'auth_require_admin_session(true)'), "{$name} lacks admin session protection");
}
foreach (['approve.php', 'reject.php'] as $name) {
    $source = znews_social_read($root . '/api/admin/znews/comments/' . $name);
    znews_social_expect(str_contains($source, "api_require_method('POST')") && str_contains($source, 'expected_updated_at') && str_contains($source, 'znews_idempotency_key'), "{$name} lacks POST, version, or idempotency protection");
}

$shares = znews_social_read($root . '/api/znews/lib/shares.php');
znews_social_expect(str_contains($shares, 'ZNEWS_SHARES/') || str_contains($engagement, "return 'ZNEWS_SHARES/'"), 'share namespace missing');
znews_social_expect(str_contains($shares, "gmdate('YmdH'"), 'share abuse-control hourly bucket missing');
znews_social_expect(str_contains($shares, "'FACEBOOK'") && str_contains($shares, "'WHATSAPP'") && str_contains($shares, "'TELEGRAM'") && str_contains($shares, "'COPY_LINK'"), 'share channel allowlist missing');
znews_social_expect(str_contains($shares, "'share_count'") && str_contains($shares, 'znews_engagement_adjust_counter'), 'share counter update missing');
znews_social_expect(str_contains($shares, 'fb_put_if_match'), 'share record lacks duplicate/concurrency claim');

$shareEndpoint = znews_social_read($root . '/api/znews/shares/create.php');
znews_social_expect(str_contains($shareEndpoint, 'api_require_app_key();') && str_contains($shareEndpoint, 'znews_require_creator(true)') && str_contains($shareEndpoint, 'znews_idempotency_key'), 'share endpoint lacks authentication or idempotency');

foreach (['api/znews/public/post.php','api/znews/posts/details.php','api/znews/posts/mine.php'] as $relative) {
    $source = znews_social_read($root . '/' . $relative);
    znews_social_expect(str_contains($source, 'znews_engagement_overlay'), "{$relative} does not overlay canonical engagement counts");
}
$publicFeed = znews_social_read($root . '/api/znews/public/feed.php');
$feedRanking = znews_social_read($root . '/api/znews/lib/feed_ranking.php');
znews_social_expect(str_contains($publicFeed, 'znews_fair_feed_page'), 'public feed is not delegated to the fair ranking page');
znews_social_expect(
    !str_contains($feedRanking, "fb_get('ZNEWS_ENGAGEMENT/' . \$postId)")
    && !str_contains($feedRanking, 'znews_post_load($postId)')
    && str_contains($feedRanking, 'znews_feed_session_projection_map')
    && str_contains($feedRanking, 'znews_public_projection_item'),
    'fair feed must render from the bounded public projection without per-item reads'
);

$summary = znews_social_read($root . '/api/znews/engagement/summary.php');
znews_social_expect(str_contains($summary, 'api_require_app_key();') && str_contains($summary, 'znews_require_creator(true)') && str_contains($summary, "'liked'"), 'engagement summary lacks authenticated like state');

echo "Z News social engagement tests passed ({$assertions} assertions).\n";
