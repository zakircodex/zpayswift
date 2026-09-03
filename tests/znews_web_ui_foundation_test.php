<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function contents(string $path): string
{
    $value = file_get_contents($path);
    check(is_string($value), 'Could not read ' . $path);
    return (string)$value;
}

$required = [
    'znews/index.html',
    'znews/.htaccess',
    'znews/manifest.webmanifest',
    'znews/manifest-root.webmanifest',
    'znews/sw.js',
    'znews/sw-root.js',
    'znews/assets/znews-config.js',
    'znews/assets/znews-api.js',
    'znews/assets/znews-bootstrap.js',
    'znews/assets/znews-access.js',
    'znews/assets/znews-header.js',
    'znews/assets/znews-feed-ui.js',
    'znews/assets/znews-profile.js',
    'znews/assets/znews-reader.js',
    'znews/assets/znews-instant-comments.js',
    'znews/assets/znews.js',
    'znews/assets/znews-creator.js',
    'znews/assets/znews.css',
    'znews/assets/znews-premium.css',
    'znews/assets/znews-reader.css',
    'api/znews/public/creator.php',
    'api/znews/public/feed.php',
    'api/znews/public/impression.php',
    'api/znews/lib/feed_ranking.php',
    'api/znews/lib/creator_registry.php',
    'api/znews/lib/creator_view_policy.php',
    'api/znews/lib/creator_payout_batches.php',
];
foreach ($required as $relative) {
    check(is_file($root . '/' . $relative), 'Required file missing: ' . $relative);
}

$index = contents($root . '/znews/index.html');
check(str_contains($index, '<strong>Z Sky 24</strong>'), 'Z Sky 24 brand is missing');
check(str_contains($index, 'News • Stories • Community'), 'Z Sky 24 tagline is missing');
check(str_contains($index, 'interactive-widget=resizes-content'), 'Keyboard-resizing viewport mode is missing');
check(str_contains($index, 'id="searchToggle"') && str_contains($index, 'id="menuToggle"'), 'Header tools are missing');
check(strpos($index, 'id="searchToggle"') < strpos($index, 'id="menuToggle"'), 'Search must appear before menu');
check(str_contains($index, 'data-menu-route="create" data-auth-only hidden'), 'Drawer creator guard is missing');
foreach (['feed', 'creator', 'create', 'mine', 'policy'] as $view) {
    check(str_contains($index, 'data-view="' . $view . '"'), $view . ' view is missing');
}
check(str_contains($index, 'class="composer-card card" data-auth-only hidden'), 'Guest composer guard is missing');
check(str_contains($index, 'id="loadMoreButton" type="button" hidden'), 'Feed pagination source is missing');
check(str_contains($index, 'class="post-reader-shell"'), 'Full-screen post reader shell is missing');
check(str_contains($index, 'class="comment-dock"'), 'Comment dock is missing');
check(!str_contains($index, 'class="mobile-nav"'), 'Removed bottom navigation returned');
check(!str_contains($index, 'znews-quick-login.js'), 'Removed standalone login returned');

$config = contents($root . '/znews/assets/znews-config.js');
check(str_contains($config, "provider: 'NONE'"), 'Ad provider must remain disabled before Adsterra integration');
check(str_contains($config, "mode: 'DISABLED'"), 'Disabled ad mode is missing');
check(str_contains($config, 'enabled: false'), 'Ads are not fail-closed');
check(str_contains($config, "creatorRevenueMode: 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT'"), 'Period payout mode is missing');
check(str_contains($config, 'creatorBalanceEnabled: false'), 'Creator balance feature is not disabled');
check(str_contains($config, '[data-route="balance"]'), 'Legacy balance route is not hidden');
check(str_contains($config, "document.querySelectorAll('.ad-slot')"), 'Ad slots are not hidden until provider integration');
check(str_contains($config, 'Weekly review • Monthly payout'), 'Creator period policy UI is missing');
check(!str_contains($config, 'persistentSessionStorageKey'), 'Standalone persistent login config remains');
check(!preg_match('/(?:secret|private[_-]?key)\s*[:=]\s*[\'\"][^\'\"]{8,}/i', $config), 'Possible public secret detected');

$api = contents($root . '/znews/assets/znews-api.js');
foreach ([
    'znews/auth/handoff.php',
    'znews/public/feed.php',
    'znews/public/post.php',
    'znews/posts/create.php',
    'znews/media/upload.php',
    'znews/comments/create.php',
    'znews/views/start.php',
    'znews/views/heartbeat.php',
    'znews/views/complete.php',
] as $endpoint) {
    check(str_contains($api, $endpoint), 'API endpoint missing: ' . $endpoint);
}
check(str_contains($api, 'sessionStorage.getItem'), 'Session storage integration is missing');
check(str_contains($api, 'X-SESSION-TOKEN') && str_contains($api, 'X-APP-KEY'), 'Required API headers are missing');
check(str_contains($api, "credentials: 'same-origin'"), 'Same-origin cookie policy is missing');
check(!str_contains($api, 'verifyPassword(') && !str_contains($api, 'pinLogin('), 'Standalone login methods remain');

$creator = contents($root . '/znews/assets/znews-creator.js');
foreach (['znews/media/upload.php', 'znews/posts/create.php', 'znews/posts/update.php', 'znews/posts/delete.php'] as $endpoint) {
    check(str_contains($creator, $endpoint), 'Creator endpoint missing: ' . $endpoint);
}
check(str_contains($creator, 'expected_updated_at'), 'Creator version guard is missing');
check(str_contains($creator, 'idempotency_key'), 'Creator idempotency is missing');
check(str_contains($creator, 'stopImmediatePropagation'), 'Duplicate creator submit protection is missing');

$access = contents($root . '/znews/assets/znews-access.js');
check(str_contains($access, "['create', 'mine', 'balance']"), 'Guest creator-route guard is missing');
check(str_contains($access, 'config.zpayLoginUrl'), 'Guest login route is missing');

$feedUi = contents($root . '/znews/assets/znews-feed-ui.js');
check(str_contains($feedUi, 'patchPublicFeed()'), 'Fair feed response capture is missing');
check(str_contains($feedUi, 'feed_session_id'), 'Feed session ID is not captured');
check(str_contains($feedUi, 'IntersectionObserver'), 'Infinite scrolling is missing');
check(str_contains($feedUi, 'dedupeFeedCards()'), 'Feed duplicate protection is missing');

$reader = contents($root . '/znews/assets/znews-reader.js');
check(str_contains($reader, 'mergeComments('), 'Comment dedupe is missing');
check(str_contains($reader, 'window.history.back()'), 'Reader Back behavior is missing');
check(str_contains($reader, 'window.visualViewport?.addEventListener'), 'Visual viewport integration is missing');
check(str_contains($reader, 'lockUnderlyingPage()'), 'Underlying page scroll lock is missing');

$app = contents($root . '/znews/assets/znews.js');
check(str_contains($app, 'beginView(postId)'), 'Reader view lifecycle does not start');
check(str_contains($app, 'window.setInterval(() => heartbeatView(), 10000)'), 'Periodic view heartbeat is missing');
check(str_contains($app, 'api.startView(postId, idempotencyKey, { signal })'), 'Stable per-open view idempotency is missing');
check(str_contains($app, 'await completeView()'), 'View completion guard is missing');

$viewStart = contents($root . '/api/znews/views/start.php');
$viewPolicy = contents($root . '/api/znews/lib/creator_view_policy.php');
check(str_contains($viewStart, "'ad_policy'"), 'Server ad-policy response is missing');
check(str_contains($viewStart, 'znews_creator_view_gate($viewerUid, $postId, $idempotencyKey)'), 'Retry-safe guest policy is not wired');
check(str_contains($viewPolicy, "'viewer_class' => 'CREATOR'"), 'Creator viewer class is missing');
check(str_contains($viewPolicy, "'ad_eligible' => false"), 'Authenticated creators can still qualify for ads');
check(str_contains($viewPolicy, 'GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'), 'Guest spam state is missing');
check(str_contains($viewPolicy, "'events' => \$events"), 'Guest event idempotency ledger is missing');

$registry = contents($root . '/api/znews/lib/creator_registry.php');
check(str_contains($registry, 'ZNEWS_CREATORS_BY_STATUS/'), 'Creator status indexes are missing');
check(str_contains($registry, "['ACTIVE', 'BLOCKED']"), 'Creator status allowlist is missing');

$payout = contents($root . '/api/znews/lib/creator_payout_batches.php');
check(str_contains($payout, 'min(5, $value)'), 'Payout batch can exceed five creators');
check(str_contains($payout, "\$creatorStatus !== 'ACTIVE'"), 'Blocked creator payout rejection is missing');
check(str_contains($payout, "\$accountStatus !== 'ACTIVE'"), 'Inactive Z-Pay account rejection is missing');
check(str_contains($payout, "['BDT', 'MYR']"), 'BDT/MYR payout currency support is missing');

$serviceWorker = contents($root . '/znews/sw.js');
check(str_contains($serviceWorker, "url.pathname.startsWith('/api/')"), 'Service worker API exclusion is missing');
check(str_contains($serviceWorker, "request.method !== 'GET'"), 'Service worker mutation exclusion is missing');

$manifest = json_decode(contents($root . '/znews/manifest.webmanifest'), true);
check(is_array($manifest), 'Web manifest is invalid JSON');
check(($manifest['display'] ?? '') === 'standalone', 'Manifest standalone mode is missing');
$rootManifest = json_decode(contents($root . '/znews/manifest-root.webmanifest'), true);
check(($rootManifest['start_url'] ?? '') === '/', 'Standalone manifest start URL is incorrect');
check(($rootManifest['scope'] ?? '') === '/', 'Standalone manifest scope is incorrect');

$htaccess = contents($root . '/znews/.htaccess');
check(str_contains($htaccess, 'RewriteRule ^post/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean post route is missing');
check(str_contains($htaccess, 'RewriteRule ^creator/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean creator route is missing');
check(str_contains($htaccess, 'Options -Indexes'), 'Directory listing protection is missing');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    foreach ([
        'znews/assets/znews-config.js',
        'znews/assets/znews-api.js',
        'znews/assets/znews-bootstrap.js',
        'znews/assets/znews-access.js',
        'znews/assets/znews-feed-ui.js',
        'znews/assets/znews-profile.js',
        'znews/assets/znews-reader.js',
        'znews/assets/znews.js',
        'znews/assets/znews-header.js',
        'znews/assets/znews-creator.js',
        'znews/assets/znews-instant-comments.js',
        'znews/sw.js',
        'znews/sw-root.js',
    ] as $relative) {
        $output = [];
        $command = escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/' . $relative) . ' 2>&1';
        exec($command, $output, $status);
        check($status === 0, 'JavaScript syntax failed: ' . $relative . ' ' . implode("\n", $output));
    }
}

echo "PASS: {$assertions} Z News web UI assertions.\n";
