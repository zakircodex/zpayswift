<?php
declare(strict_types=1);

function znews_view_hmac(string $value): string
{
    return hash_hmac('sha256', $value, 'unit-test-secret');
}

function znews_now(): int
{
    return 1800000000;
}

require_once dirname(__DIR__) . '/api/znews/lib/feed_ranking.php';

$assertions = 0;
function fair_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$candidates = [];
for ($i = 0; $i < 20; $i++) {
    $creator = $i < 8 ? 'CREATOR_A' : 'CREATOR_' . $i;
    $candidates[] = [
        'post_id' => 'NEW_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'creator_uid' => $creator,
        'created_at' => 1800000000 - ($i * 60),
        'fair_score' => 500 + $i,
        'impressions' => 100 + $i,
        'unique_impressions' => 50 + $i,
        'last_shown_at' => 1799990000 + $i,
        'tie' => hash('sha256', 'new-' . $i),
    ];
}
for ($i = 0; $i < 10; $i++) {
    $candidates[] = [
        'post_id' => 'OLD_' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'creator_uid' => 'OLD_CREATOR_' . $i,
        'created_at' => 1700000000 - ($i * 60),
        'fair_score' => $i / 10,
        'impressions' => $i,
        'unique_impressions' => 0,
        'last_shown_at' => 0,
        'tie' => hash('sha256', 'old-' . $i),
    ];
}

$order = znews_feed_rank_candidates($candidates, 'ZFSUNITTEST');
fair_expect(count($order) === count($candidates), 'Every candidate must remain reachable in the ranked session.');
fair_expect(count(array_unique($order)) === count($order), 'Ranked feed must not contain duplicate posts.');

$byId = [];
foreach ($candidates as $candidate) {
    $byId[$candidate['post_id']] = $candidate;
}
$fairnessWindow = array_slice($order, 0, 20);
for ($i = 1; $i < count($fairnessWindow); $i++) {
    $previous = $byId[$fairnessWindow[$i - 1]]['creator_uid'];
    $current = $byId[$fairnessWindow[$i]]['creator_uid'];
    fair_expect($previous !== $current, 'The same creator must not occupy consecutive slots in the first fairness window.');
}

$firstTwentyCounts = [];
foreach ($fairnessWindow as $postId) {
    $creator = $byId[$postId]['creator_uid'];
    $firstTwentyCounts[$creator] = ($firstTwentyCounts[$creator] ?? 0) + 1;
}
fair_expect(($firstTwentyCounts['CREATOR_A'] ?? 0) <= 2, 'A high-volume creator must be capped in the first twenty slots.');

$oldInFirstTen = count(array_filter(
    array_slice($order, 0, 10),
    static fn(string $postId): bool => str_starts_with($postId, 'OLD_')
));
fair_expect($oldInFirstTen >= 3, 'At least three underexposed posts must be mixed into the first ten slots.');

$sessionId = 'ZFS' . str_repeat('A', 32);
$cursor = znews_feed_cursor_encode($sessionId, 12, znews_now() + 3600);
$decoded = znews_feed_cursor_decode($cursor);
fair_expect(($decoded['session_id'] ?? '') === $sessionId, 'Signed cursor must preserve the feed session ID.');
fair_expect(($decoded['offset'] ?? -1) === 12, 'Signed cursor must preserve the offset.');

$source = file_get_contents(dirname(__DIR__) . '/api/znews/lib/feed_ranking.php');
$feedEndpoint = file_get_contents(dirname(__DIR__) . '/api/znews/public/feed.php');
$impressionEndpoint = file_get_contents(dirname(__DIR__) . '/api/znews/public/impression.php');
fair_expect(is_string($source), 'Fair feed source must be readable.');
fair_expect(str_contains($source, "['F', 'F', 'E', 'F', 'F', 'E', 'F', 'F', 'E', 'F']"), 'The 70/30 fresh-fair pattern is missing.');
fair_expect(str_contains($source, 'ZNEWS_FEED_SESSIONS/'), 'Stable server-side feed sessions are missing.');
fair_expect(str_contains($source, 'ZNEWS_FEED_EXPOSURE/'), 'Feed exposure counters are missing.');
fair_expect(str_contains($source, 'ZNEWS_FEED_SESSION_IMPRESSIONS/'), 'Per-session impression deduplication is missing.');
fair_expect(str_contains($source, 'hash_equals'), 'Timing-safe session and cursor checks are required.');
fair_expect(!str_contains($source, "fb_get('USERS/"), 'Fair ranking must not read private user records.');
fair_expect(!preg_match('/\b(?:wallet|ledger|transfer)\b/i', $source), 'Fair ranking must not touch financial modules.');
fair_expect(is_string($feedEndpoint) && str_contains($feedEndpoint, 'znews_fair_feed_page'), 'Public feed endpoint is not using fair ranking.');
fair_expect(is_string($impressionEndpoint) && str_contains($impressionEndpoint, 'znews_feed_record_impressions'), 'Impression endpoint is not wired.');
fair_expect(str_contains($impressionEndpoint, 'api_require_app_key()'), 'Impression writes must require the app-key contract.');

echo "PASS: {$assertions} Z News fair feed assertions.\n";
