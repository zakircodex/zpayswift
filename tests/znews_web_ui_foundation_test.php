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
    if (!is_string($value)) {
        fwrite(STDERR, "FAIL: Could not read {$path}\n");
        exit(1);
    }
    return $value;
}

$required = [
    'znews/index.html', 'znews/.htaccess', 'znews/manifest.webmanifest', 'znews/manifest-root.webmanifest',
    'znews/sw.js', 'znews/sw-root.js',
    'znews/assets/znews-config.js', 'znews/assets/znews-api.js', 'znews/assets/znews-ads.js',
    'znews/assets/znews-bootstrap.js', 'znews/assets/znews-access.js', 'znews/assets/znews-header.js',
    'znews/assets/znews-feed-ui.js', 'znews/assets/znews-profile.js', 'znews/assets/znews-reader.js',
    'znews/assets/znews-instant-comments.js', 'znews/assets/znews-premium.css',
    'znews/assets/znews-reader.css', 'znews/assets/znews.js', 'znews/assets/znews-creator.js',
    'znews/assets/znews.css', 'api/znews/public/creator.php', 'api/znews/public/feed.php',
    'api/znews/public/impression.php', 'api/znews/lib/feed_ranking.php',
    'docs/znews-inmobi-integration.md', 'docs/znews-android-ui-contract.md',
];
foreach ($required as $relative) {
    check(is_file($root . '/' . $relative), "Required file missing: {$relative}");
}

$index = contents($root . '/znews/index.html');
check(str_contains($index, '<strong>Z Sky 24</strong>'), 'Z Sky 24 brand is missing');
check(str_contains($index, 'News • Stories • Community'), 'Z Sky 24 tagline is missing');
check(str_contains($index, 'src="/assets/brand/zpay-icon.png"'), 'Original Z-Pay logo asset is not used');
check(str_contains($index, 'interactive-widget=resizes-content'), 'Keyboard-resizing viewport mode is missing');
check(str_contains($index, 'id="searchToggle"') && str_contains($index, 'id="menuToggle"'), 'Header tools are missing');
check(strpos($index, 'id="searchToggle"') < strpos($index, 'id="menuToggle"'), 'Search must appear left of menu');
check(str_contains($index, 'data-menu-route="create" data-auth-only hidden'), 'Drawer creator guard is missing');
foreach (['feed', 'creator', 'create', 'mine', 'balance'] as $view) {
    check(str_contains($index, 'data-view="' . $view . '"'), "{$view} view is missing");
}
check(!str_contains($index, 'class="mobile-nav"'), 'Bottom navigation must remain removed');
check(!str_contains($index, '<h1>Latest stories</h1>'), 'Latest stories heading must remain removed');
check(str_contains($index, 'class="composer-card card" data-auth-only hidden'), 'Guest composer guard is missing');
check(str_contains($index, 'id="loadMoreButton" type="button" hidden'), 'Feed pagination source is missing');
check(str_contains($index, 'id="creatorLoadMoreButton" type="button" hidden'), 'Creator pagination source is missing');
check(str_contains($index, 'class="post-reader-shell"'), 'Full-screen post reader shell is missing');
check(str_contains($index, 'id="postReaderScroll"'), 'Reader scroll area is missing');
check(str_contains($index, 'class="comment-dock"'), 'Comment dock is missing');
check(str_contains($index, '<textarea id="commentText"'), 'Comment textarea is missing');
check(str_contains($index, 'id="commentGuestCta"'), 'Guest comment CTA is missing');
check(str_contains($index, 'znews-reader.css?v=2'), 'Latest reader stylesheet is not activated');
check(str_contains($index, 'znews-bootstrap.js?v=13'), 'Latest bootstrap is not activated');
check(!str_contains($index, 'znews-quick-login.js'), 'Removed standalone login remains loaded');
check(!str_contains($index, '<div class="header-actions">'), 'Visible Sign in header remains');
check(!preg_match('/\b(?:Earn|Income|Cash|Profit|Revenue|Job|Work)\b/i', strip_tags($index)), 'Forbidden public wording exists');

$config = contents($root . '/znews/assets/znews-config.js');
check(str_contains($config, "provider: 'INMOBI'"), 'InMobi provider config is missing');
check(str_contains($config, "mode: existing.ads?.mode || 'TEST'"), 'Ad test-mode default is missing');
check(str_contains($config, 'enabled: existing.ads?.enabled === true'), 'Ads require no explicit enablement');
check(!str_contains($config, 'persistentSessionStorageKey'), 'Standalone persistent login config remains');
check(!preg_match('/(?:secret|private[_-]?key)\s*[:=]\s*[\'\"][^\'\"]{8,}/i', $config), 'Possible public secret detected');

$api = contents($root . '/znews/assets/znews-api.js');
foreach ([
    'znews/auth/handoff.php', 'znews/public/feed.php', 'znews/public/post.php',
    'znews/posts/create.php', 'znews/media/upload.php', 'znews/likes/set.php',
    'znews/comments/create.php', 'znews/comments/list.php', 'znews/shares/create.php',
    'znews/views/start.php', 'znews/views/heartbeat.php', 'znews/views/complete.php',
    'znews/balance/summary.php', 'znews/balance/ledger.php', 'znews/transfers/create.php',
] as $endpoint) {
    check(str_contains($api, $endpoint), "API endpoint missing: {$endpoint}");
}
check(str_contains($api, 'sessionStorage.getItem'), 'Session storage integration is missing');
check(str_contains($api, 'X-SESSION-TOKEN') && str_contains($api, 'X-APP-KEY'), 'Required API headers are missing');
check(str_contains($api, "credentials: 'same-origin'"), 'Same-origin cookie policy is missing');
check(!str_contains($api, 'verifyPassword(') && !str_contains($api, 'pinLogin('), 'Standalone login methods remain');

$creator = contents($root . '/znews/assets/znews-creator.js');
foreach (['znews/media/upload.php', 'znews/posts/create.php', 'znews/posts/details.php', 'znews/posts/update.php', 'znews/posts/delete.php'] as $endpoint) {
    check(str_contains($creator, $endpoint), "Creator endpoint missing: {$endpoint}");
}
check(str_contains($creator, 'expected_updated_at'), 'Creator version guard is missing');
check(str_contains($creator, 'idempotency_key'), 'Creator idempotency is missing');
check(str_contains($creator, 'published_immediately'), 'Creator publication result is ignored');
check(str_contains($creator, 'stopImmediatePropagation'), 'Duplicate creator submit protection is missing');

$access = contents($root . '/znews/assets/znews-access.js');
check(str_contains($access, "['create', 'mine', 'balance']"), 'Guest creator-route guard is missing');
check(str_contains($access, 'config.zpayRegisterUrl'), 'Guest registration route is missing');
check(str_contains($access, '[data-action="like"]'), 'Guest like cleanup is missing');

$header = contents($root . '/znews/assets/znews-header.js');
check(str_contains($header, 'function openSearch'), 'Search open state is missing');
check(str_contains($header, "znewsOverlay: 'search'"), 'Search history state is missing');
check(str_contains($header, "window.addEventListener('popstate'"), 'Search Back handler is missing');
check(str_contains($header, 'history.back()'), 'Search close does not consume history');
check(str_contains($header, 'haystack.includes(query)'), 'Loaded-story filtering is missing');
check(str_contains($header, "menuDrawer.classList.add('is-open')"), 'Menu open behavior is missing');

$profile = contents($root . '/znews/assets/znews-profile.js');
check(str_contains($profile, "wrapApiMethod('publicFeed')"), 'Creator identity capture is missing');
check(str_contains($profile, 'znews/public/creator.php'), 'Public creator API is missing');
check(str_contains($profile, "config.publicPath('creator', uid)"), 'Domain-aware creator route is missing');
check(str_contains($profile, 'data-profile-post-id'), 'Creator public post rendering is missing');

$feedUi = contents($root . '/znews/assets/znews-feed-ui.js');
check(str_contains($feedUi, 'patchPublicFeed()'), 'Fair feed response capture is missing');
check(str_contains($feedUi, 'feed_session_id'), 'Feed session ID is not captured');
check(str_contains($feedUi, 'znews/public/impression.php'), 'Feed impression reporting is missing');
check(str_contains($feedUi, 'IntersectionObserver'), 'Infinite scrolling is missing');
check(str_contains($feedUi, "rootMargin: '700px 0px'"), 'Infinite-scroll preload margin is missing');
check(str_contains($feedUi, "button.textContent = 'See more'"), 'See more is missing');
check(str_contains($feedUi, 'dedupeFeedCards()'), 'Feed duplicate protection is missing');
check(str_contains($feedUi, 'You’re all caught up.'), 'End-of-feed state is missing');

$reader = contents($root . '/znews/assets/znews-reader.js');
check(str_contains($reader, "wrapApiMethod('comments'"), 'Reader comment response capture is missing');
check(str_contains($reader, 'mergeComments('), 'Comment dedupe is missing');
check(str_contains($reader, 'commentLoadMoreButton'), 'Comment pagination UI is missing');
check(str_contains($reader, 'znewsFeedScrollY'), 'Feed scroll restore state is missing');
check(str_contains($reader, 'window.history.back()'), 'Reader Back history behavior is missing');
check(str_contains($reader, 'form.requestSubmit()'), 'Keyboard comment submit is missing');
check(str_contains($reader, 'window.visualViewport?.addEventListener'), 'Visual viewport integration is missing');
check(str_contains($reader, '--znews-reader-vv-height'), 'Visual viewport height bridge is missing');
check(str_contains($reader, 'lockUnderlyingPage()'), 'Underlying page scroll lock is missing');
check(!str_contains($reader, 'Reply'), 'Fake reply action exists without backend support');

$instant = contents($root . '/znews/assets/znews-instant-comments.js');
check(str_contains($instant, 'appendPublishedComment(comment)'), 'Instant comment append is missing');
check(str_contains($instant, "new CustomEvent('znews:comment-created'"), 'Instant comment event is missing');
check(!str_contains($instant, 'await api.comments(postId)'), 'Comment submit still reloads all comments');
check(str_contains($instant, 'updateCommentCount'), 'Comment count reconciliation is missing');

$publicCreator = contents($root . '/api/znews/public/creator.php');
check(str_contains($publicCreator, 'ZNEWS_USER_POSTS/'), 'Creator endpoint does not use user post index');
check(str_contains($publicCreator, 'znews_post_is_public($post)'), 'Creator endpoint may expose non-public posts');
check(!str_contains($publicCreator, "fb_get('USERS/"), 'Creator endpoint reads private user records');

$feedEndpoint = contents($root . '/api/znews/public/feed.php');
$impressionEndpoint = contents($root . '/api/znews/public/impression.php');
$ranking = contents($root . '/api/znews/lib/feed_ranking.php');
check(str_contains($feedEndpoint, 'znews_fair_feed_page'), 'Public feed is not using fair ranking');
check(str_contains($impressionEndpoint, 'znews_feed_record_impressions'), 'Feed impression endpoint is not wired');
check(str_contains($impressionEndpoint, 'api_require_app_key()'), 'Feed impression write lacks app-key validation');
check(str_contains($ranking, "['F', 'F', 'E', 'F', 'F', 'E', 'F', 'F', 'E', 'F']"), '70/30 fair pattern is missing');
check(str_contains($ranking, 'ZNEWS_FEED_SESSIONS/') && str_contains($ranking, 'ZNEWS_FEED_EXPOSURE/'), 'Fair feed namespaces are missing');
check(!preg_match('/\b(?:wallet|ledger|transfer)\b/i', $ranking), 'Feed ranking touches financial modules');

$app = contents($root . '/znews/assets/znews.js');
check(str_contains($app, 'beginView(postId)'), 'Reader view lifecycle does not start');
check(str_contains($app, 'window.setTimeout(() => heartbeatView(), 5000)'), 'First view heartbeat is missing');
check(str_contains($app, 'window.setTimeout(() => heartbeatView(), 15000)'), 'Second view heartbeat is missing');
check(str_contains($app, 'await completeView()'), 'View completion guard is missing');
check(str_contains($app, 'Minimum transfer amount is ৳500.'), 'Transfer minimum disclosure is missing');
check(str_contains($app, 'state.balanceMicros < 500_000_000'), 'Transfer threshold guard is missing');

$premium = contents($root . '/znews/assets/znews-premium.css');
check(str_contains($premium, '#feedView>.composer-card{margin-bottom:18px}'), 'Composer/feed spacing is missing');
check(str_contains($premium, '-webkit-line-clamp:2'), 'Feed text is not clamped');
check(str_contains($premium, '.see-more-button'), 'See more styling is missing');
check(str_contains($premium, '.feed-scroll-sentinel'), 'Infinite-scroll sentinel styling is missing');

$readerCss = contents($root . '/znews/assets/znews-reader.css');
check(str_contains($readerCss, 'height:var(--znews-reader-vv-height,100dvh)'), 'Mobile reader does not follow visual viewport height');
check(str_contains($readerCss, 'width:var(--znews-reader-vv-width,100%)'), 'Mobile reader does not follow visual viewport width');
check(!str_contains($readerCss, 'width:100vw'), 'Mobile reader still risks right-side clipping');
check(str_contains($readerCss, 'overflow-x:hidden'), 'Horizontal reader overflow is not blocked');
check(str_contains($readerCss, '.comment-bubble'), 'Comment bubble styling is missing');
check(str_contains($readerCss, '.comment-dock'), 'Sticky composer dock styling is missing');
check(str_contains($readerCss, 'object-fit:contain'), 'Reader image is still cropped');
check(str_contains($readerCss, '#postDetail>.ad-slot:empty{display:none}'), 'Empty reader ad gap is not hidden');

$bootstrap = contents($root . '/znews/assets/znews-bootstrap.js');
check(str_contains($bootstrap, 'znews-feed-ui.js?v=1'), 'Fair feed UI module is not loaded');
check(str_contains($bootstrap, 'znews-reader.js?v=3'), 'Latest reader UI module is not loaded');
check(strpos($bootstrap, 'znews-reader.js?v=3') < strpos($bootstrap, 'znews.js?v=10'), 'Reader capture must load before app');
check(str_contains($bootstrap, 'znews-instant-comments.js?v=4'), 'Latest comment module is not loaded');

$serviceWorker = contents($root . '/znews/sw.js');
check(str_contains($serviceWorker, "const CACHE_NAME = 'zsky24-embedded-shell-v9'"), 'Embedded reader shell cache is stale');
check(str_contains($serviceWorker, 'znews-bootstrap.js?v=13'), 'Latest bootstrap is missing from cache');
check(str_contains($serviceWorker, 'znews-reader.css?v=2'), 'Latest reader CSS is missing from cache');
check(str_contains($serviceWorker, 'znews-reader.js?v=3'), 'Latest reader JS is missing from cache');
check(str_contains($serviceWorker, 'znews-instant-comments.js?v=4'), 'Latest comments JS is missing from cache');
check(str_contains($serviceWorker, "url.pathname.startsWith('/api/')"), 'Service worker API exclusion is missing');
check(str_contains($serviceWorker, "request.method !== 'GET'"), 'Service worker mutation exclusion is missing');

$manifest = json_decode(contents($root . '/znews/manifest.webmanifest'), true);
check(is_array($manifest), 'Web manifest is invalid JSON');
check(($manifest['start_url'] ?? '') === '/znews/', 'Manifest start URL is incorrect');
check(($manifest['display'] ?? '') === 'standalone', 'Manifest standalone mode is missing');
$rootManifest = json_decode(contents($root . '/znews/manifest-root.webmanifest'), true);
check(($rootManifest['start_url'] ?? '') === '/', 'Standalone manifest start URL is incorrect');
check(($rootManifest['scope'] ?? '') === '/', 'Standalone manifest scope is incorrect');

$htaccess = contents($root . '/znews/.htaccess');
check(str_contains($htaccess, 'RewriteRule ^post/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean post route is missing');
check(str_contains($htaccess, 'RewriteRule ^creator/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean creator route is missing');
check(str_contains($htaccess, 'Options -Indexes'), 'Directory listing protection is missing');

$deployment = contents($root . '/.cpanel.yml');
check(preg_match('/\bassets docs images logo znews\b/', $deployment) === 1, 'cPanel deployment omits znews');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    foreach ([
        'znews/assets/znews-config.js', 'znews/assets/znews-api.js', 'znews/assets/znews-ads.js',
        'znews/assets/znews-bootstrap.js', 'znews/assets/znews-access.js', 'znews/assets/znews-feed-ui.js',
        'znews/assets/znews-profile.js', 'znews/assets/znews-reader.js', 'znews/assets/znews.js',
        'znews/assets/znews-header.js', 'znews/assets/znews-creator.js',
        'znews/assets/znews-instant-comments.js', 'znews/sw.js',
    ] as $relative) {
        $command = escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/' . $relative) . ' 2>&1';
        exec($command, $output, $status);
        check($status === 0, "JavaScript syntax failed: {$relative} " . implode("\n", $output));
        $output = [];
    }
}

echo "PASS: {$assertions} Z News web UI assertions.\n";
