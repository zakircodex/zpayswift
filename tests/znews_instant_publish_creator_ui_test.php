<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

define('ZNEWS_TEXT_REVIEW_TERMS', ['private-review-marker']);

function instant_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function instant_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    instant_expect(is_file($path), "missing file: {$relative}");
    $source = file_get_contents($path);
    instant_expect(is_string($source), "unreadable file: {$relative}");
    return (string)$source;
}

require_once $root . '/api/znews/lib/instant_publish.php';

$textDecision = znews_post_publication_decision([], 'A normal community update.');
instant_expect(($textDecision['publish'] ?? false) === true, 'clean text post is not instantly publishable');
instant_expect(($textDecision['status'] ?? '') === 'ACTIVE', 'clean text post is not ACTIVE');
instant_expect(($textDecision['moderation_status'] ?? '') === 'APPROVED', 'clean text post lacks automated approval state');
instant_expect(($textDecision['copyright_status'] ?? '') === 'NOT_APPLICABLE', 'text post copyright state is incorrect');

$termReview = znews_post_publication_decision([], 'Contains private-review-marker for testing.');
instant_expect(($termReview['publish'] ?? true) === false, 'configured text-review term bypasses review');
instant_expect(($termReview['mode'] ?? '') === 'AUTOMATED_TEXT_REVIEW', 'configured text review mode is missing');
instant_expect(($termReview['reason'] ?? '') === 'CONFIGURED_TEXT_REVIEW', 'configured term is exposed or reason is incorrect');

$linkReview = znews_post_publication_decision([], 'https://one.test https://two.test https://three.test');
instant_expect(($linkReview['publish'] ?? true) === false, 'excessive links bypass review');
instant_expect(($linkReview['reason'] ?? '') === 'EXCESSIVE_LINKS', 'excessive-link reason is missing');

$spamReview = znews_post_publication_decision([], 'aaaaaaaaaaaaaa');
instant_expect(($spamReview['publish'] ?? true) === false, 'repetitive spam bypasses review');
instant_expect(($spamReview['reason'] ?? '') === 'REPETITIVE_SPAM', 'repetitive-spam reason is missing');

$cleanMediaDecision = znews_post_publication_decision([
    'duplicate_status' => 'CLEAR',
    'near_duplicate_count' => 0,
    'moderation_status' => 'PENDING',
], 'Original image update.');
instant_expect(($cleanMediaDecision['publish'] ?? false) === true, 'clean media post is not instantly publishable');
instant_expect(($cleanMediaDecision['status'] ?? '') === 'ACTIVE', 'clean media post is not ACTIVE');
instant_expect(($cleanMediaDecision['copyright_status'] ?? '') === 'AUTO_CLEARED', 'clean media lacks restricted auto-clear verdict');

$riskyMediaDecision = znews_post_publication_decision([
    'duplicate_status' => 'NEAR_MATCH_REVIEW',
    'near_duplicate_count' => 1,
    'moderation_status' => 'REVIEW_REQUIRED',
], 'Image update.');
instant_expect(($riskyMediaDecision['publish'] ?? true) === false, 'near-duplicate media bypasses safety review');
instant_expect(($riskyMediaDecision['status'] ?? '') === 'REVIEW', 'risky media does not enter REVIEW');
instant_expect(($riskyMediaDecision['moderation_status'] ?? '') === 'PENDING', 'risky media does not remain PENDING');
instant_expect(($riskyMediaDecision['mode'] ?? '') === 'AUTOMATED_RISK_REVIEW', 'risk review mode is missing');

$now = 1760000000;
$post = znews_apply_publication_decision([
    'post_id' => 'ZNPTEST',
    'creator_uid' => 'USERTEST',
    'created_at' => $now,
    'updated_at' => $now,
], $textDecision, $now);
$publicIndex = znews_public_feed_index_for_post($post);
instant_expect(is_array($publicIndex), 'published post lacks public-feed index');
instant_expect(($publicIndex['status'] ?? '') === 'ACTIVE', 'public-feed index is not ACTIVE');
instant_expect(($post['published_at'] ?? 0) === $now, 'published timestamp is missing');

$reviewPost = znews_apply_publication_decision([
    'post_id' => 'ZNPREVIEW',
    'creator_uid' => 'USERTEST',
    'created_at' => $now,
    'updated_at' => $now,
], $riskyMediaDecision, $now);
instant_expect(znews_public_feed_index_for_post($reviewPost) === null, 'review post leaked into public feed');

$createService = instant_read('api/znews/lib/post_media_create.php');
$updateService = instant_read('api/znews/lib/post_media_update.php');
$policySource = instant_read('api/znews/lib/instant_publish.php');
$createEndpoint = instant_read('api/znews/posts/create.php');
$updateEndpoint = instant_read('api/znews/posts/update.php');
$mediaPolicy = instant_read('api/znews/lib/media_policy.php');
$webIndex = instant_read('znews/index.html');
$webBootstrap = instant_read('znews/assets/znews-bootstrap.js');
$webCreator = instant_read('znews/assets/znews-creator.js');

foreach ([$createService, $updateService, $policySource, $createEndpoint, $updateEndpoint, $mediaPolicy, $webCreator] as $source) {
    instant_expect(
        !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'wallet_credit_available(')
        && !str_contains($source, 'WALLET_LEDGER/'),
        'creator publication flow touches wallet business logic'
    );
}

instant_expect(str_contains($policySource, 'ZNEWS_TEXT_REVIEW_TERMS'), 'private-config text review terms are unsupported');
instant_expect(str_contains($policySource, 'EXCESSIVE_LINKS'), 'link-spam review gate is missing');
instant_expect(str_contains($policySource, 'REPETITIVE_SPAM'), 'repetitive-spam review gate is missing');
instant_expect(str_contains($createService, 'znews_post_publication_decision($mediaRow, $text)'), 'create service does not check post text');
instant_expect(str_contains($createService, 'znews_path_public_feed($postId)'), 'create service lacks public-feed write');
instant_expect(str_contains($updateService, 'znews_post_publication_decision($newMediaRow, $text)'), 'edited text is not rechecked');
instant_expect(str_contains($updateService, 'znews_public_feed_index_for_post($updated)'), 'update service lacks dynamic feed decision');
instant_expect(str_contains($updateService, 'expectedUpdatedAt'), 'edit version protection is missing');
instant_expect(str_contains($mediaPolicy, "'AUTO_CLEARED'"), 'strict public media policy rejects auto-cleared media');
instant_expect(str_contains($createEndpoint, 'published_immediately'), 'create endpoint does not disclose publication state');
instant_expect(str_contains($updateEndpoint, 'requires_review'), 'update endpoint does not disclose review state');

instant_expect(str_contains($webIndex, 'Clean posts publish immediately'), 'web UI does not explain instant publishing');
instant_expect(str_contains($webIndex, '>Publish post<'), 'web publish action label is missing');
instant_expect(str_contains($webBootstrap, 'znews-creator.js?v=3'), 'web creator management module is not loaded by bootstrap');
instant_expect(!str_contains($webIndex, 'Submit for review'), 'web UI still presents every post as pre-moderated');
instant_expect(str_contains($webCreator, 'znews/posts/update.php'), 'web edit endpoint is missing');
instant_expect(str_contains($webCreator, 'znews/posts/delete.php'), 'web delete endpoint is missing');
instant_expect(str_contains($webCreator, 'expected_updated_at'), 'web edit/delete version protection is missing');
instant_expect(str_contains($webCreator, 'published_immediately'), 'web creator UI ignores publication state');
instant_expect(str_contains($webCreator, 'stopImmediatePropagation'), 'web creator handler does not prevent duplicate form submission');
instant_expect(!preg_match('/(?:secret|private[_-]?key)\s*[:=]\s*[\'\"][^\'\"]{8,}/i', $webCreator), 'possible secret committed in creator browser module');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $command = escapeshellarg($node) . ' --check '
        . escapeshellarg($root . '/znews/assets/znews-creator.js') . ' 2>&1';
    exec($command, $output, $status);
    instant_expect($status === 0, 'creator JavaScript syntax failed: ' . implode("\n", $output));
}

echo "PASS: {$assertions} Z News instant-publish creator assertions.\n";
