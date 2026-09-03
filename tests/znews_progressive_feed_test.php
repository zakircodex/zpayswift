<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function progressive_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function progressive_source(string $relative): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relative);
    progressive_expect(is_string($source), 'Could not read ' . $relative);
    return (string)$source;
}

$controller = progressive_source('znews/assets/znews-progressive-feed.js');
$scheduler = progressive_source('znews/assets/znews-request-scheduler.js');
$app = progressive_source('znews/assets/znews.js');
$api = progressive_source('znews/assets/znews-api.js');
$config = progressive_source('znews/assets/znews-config.js');
$feedUi = progressive_source('znews/assets/znews-feed-ui.js');
$css = progressive_source('znews/assets/znews-premium.css');
$ranking = progressive_source('api/znews/lib/feed_ranking.php');
$endpoint = progressive_source('api/znews/public/feed.php');

progressive_expect(str_contains($config, 'feedPageSize: 3'), 'Feed batch size is not three.');
progressive_expect(str_contains($config, 'feedBufferLowWatermark: 1'), 'Feed low-watermark is missing.');
progressive_expect(str_contains($api, 'publicFeed(cursor = \'\', limit = this.config.feedPageSize, options = {})'), 'API client does not accept a bounded feed batch and request signal.');
progressive_expect(str_contains($api, 'params: { limit, cursor }'), 'Feed request does not send its bounded limit/cursor.');
progressive_expect(str_contains($scheduler, 'preemptBackground()'), 'Feed-priority background preemption is missing.');
progressive_expect(str_contains($scheduler, 'this.queues = [[], [], [], []]'), 'Central priority queues are missing.');
progressive_expect(str_contains($scheduler, 'this.active = null'), 'Single shared-origin active request guard is missing.');
progressive_expect(str_contains($controller, 'this.inFlight'), 'Single in-flight request guard is missing.');
progressive_expect(str_contains($controller, 'this.knownIds = new Set()'), 'Duplicate-post guard is missing.');
progressive_expect(str_contains($controller, 'this.renderItem(item, index)'), 'One-item renderer is missing.');
progressive_expect(str_contains($controller, 'this.buffer.length > this.lowWatermark'), 'Low-buffer prefetch is missing.');
progressive_expect(str_contains($controller, '!this.hasMore || this.error'), 'Feed completion guard is missing.');
progressive_expect(str_contains($feedUi, 'new IntersectionObserver'), 'Bottom sentinel does not use IntersectionObserver.');
progressive_expect(str_contains($feedUi, "button.dataset.autoLoadPaused === 'true'"), 'Automatic retry pause is missing.');
progressive_expect(str_contains($app, 'renderSkeletons(els.feedList, 1)'), 'Initial feed must show one skeleton.');
progressive_expect(str_contains($app, 'onPaginationError: () =>'), 'Pagination has no non-destructive error callback.');
progressive_expect(!str_contains($app, "else toast(errorMessage(error), 'error');\n    } finally {\n      state.feedLoading"), 'Automatic feed timeout still uses a global toast.');
progressive_expect(str_contains($app, 'data-feed-retry'), 'Initial feed retry is missing.');
progressive_expect(str_contains($app, 'feed-inline-retry'), 'Bottom pagination retry is missing.');
progressive_expect(str_contains($app, "if (!api.isAuthenticated() || observedLikeCards.has(card)) return;"), 'Guest Like-status requests are not blocked.');
progressive_expect(str_contains($app, "rootMargin: '320px 0px'"), 'Like status is not viewport-hydrated.');
progressive_expect(str_contains($app, 'data-media-src='), 'Feed media is still started directly from src.');
progressive_expect(str_contains($app, "rootMargin: '96px 0px'"), 'Feed media is not controlled by a near-viewport observer.');
progressive_expect(str_contains($app, 'requestPriority.MEDIA'), 'Protected media does not use the priority scheduler.');
progressive_expect(str_contains($app, 'const FEED_MEDIA_TIMEOUT_MS = 70000;'), 'Slow protected media does not have a bounded background-only timeout.');
progressive_expect(str_contains($api, 'config.requestTimeoutMs || 12000'), 'Public API timeout must remain twelve seconds.');
progressive_expect(str_contains($app, 'requestPriority.LIKE'), 'Like hydration does not use the priority scheduler.');
progressive_expect(str_contains($app, 'requestPriority.ANALYTICS'), 'View/share analytics do not use the priority scheduler.');
progressive_expect(str_contains($feedUi, 'feed-impression:'), 'Feed impressions do not use the deduplicated analytics queue.');
progressive_expect(str_contains($app, 'loading="lazy" decoding="async"'), 'Lazy asynchronous media loading is missing.');
progressive_expect(str_contains($app, 'fetchpriority="high"'), 'First-card media priority hint is missing.');
progressive_expect(str_contains($app, "api.recordShare(postId, channel, { signal, idempotencyKey })"), 'Share analytics is not queued with stable idempotency.');
progressive_expect(str_contains($css, '.feed-media-frame{aspect-ratio:16/9'), 'Feed media placeholder ratio is missing.');
progressive_expect(str_contains($ranking, 'znews_feed_session_candidate_page_size'), 'Small responses can still shrink the ranking pool.');
progressive_expect(str_contains($ranking, 'return max(12, min(30, $responsePageSize));'), 'Established 60-candidate Web ranking window is not preserved.');
progressive_expect(str_contains($endpoint, "api_response(true, 'ZNEWS_PUBLIC_FEED_OK', 'Feed loaded.', \$page)"), 'Public feed response envelope changed.');

$nodeCandidates = [];
$configuredNode = trim((string)getenv('NODE_BINARY'));
if ($configuredNode !== '') {
    $nodeCandidates[] = $configuredNode;
}
$nodeCandidates = array_merge($nodeCandidates, PHP_OS_FAMILY === 'Windows'
    ? (array)preg_split('/\R/', trim((string)shell_exec('where node 2>NUL')))
    : [trim((string)shell_exec('command -v node 2>/dev/null'))]);
$node = '';
foreach ((array)$nodeCandidates as $candidate) {
    if (is_string($candidate) && trim($candidate) !== '' && is_file(trim($candidate))) {
        $node = trim($candidate);
        break;
    }
}
progressive_expect($node !== '', 'Node.js is required for progressive-feed runtime assertions.');
foreach (['znews_progressive_feed_runtime.js', 'znews_request_priority_runtime.js'] as $runtime) {
    $command = escapeshellarg($node) . ' ' . escapeshellarg($root . '/tests/' . $runtime) . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    progressive_expect($exitCode === 0, $runtime . ' failed: ' . implode(' | ', $output));
    progressive_expect(str_contains(implode("\n", $output), 'PASS:'), $runtime . ' did not report success.');
}

echo "PASS: {$assertions} Z Sky progressive feed assertions.\n";
