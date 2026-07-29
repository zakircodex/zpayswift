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
    'znews/index.html', 'znews/.htaccess', 'znews/manifest.webmanifest', 'znews/sw.js',
    'znews/assets/znews-config.js', 'znews/assets/znews-api.js', 'znews/assets/znews-ads.js',
    'znews/assets/znews-bootstrap.js', 'znews/assets/znews-access.js', 'znews/assets/znews-header.js',
    'znews/assets/znews-feed-ui.js', 'znews/assets/znews-profile.js',
    'znews/assets/znews-instant-comments.js', 'znews/assets/znews-premium.css',
    'znews/assets/znews.js', 'znews/assets/znews-creator.js', 'znews/assets/znews.css',
    'api/znews/public/creator.php', 'api/znews/public/feed.php', 'api/znews/public/impression.php',
    'api/znews/lib/feed_ranking.php', 'docs/znews-inmobi-integration.md',
    'docs/znews-android-ui-contract.md',
];
foreach ($required as $relative) {
    check(is_file($root . '/' . $relative), "Required file missing: {$relative}");
}

$index = contents($root . '/znews/index.html');
check(str_contains($index, '<strong>Z News</strong>'), 'Z News brand is missing');
check(str_contains($index, 'Stories • Updates • Community'), 'Z News tagline is missing');
check(str_contains($index, 'src="/assets/brand/zpay-icon.png"'), 'Original Z-Pay logo asset is not used');
check(str_contains($index, 'id="searchToggle"'), 'Search icon is missing');
check(str_contains($index, 'id="menuToggle"'), 'Menu button is missing');
check(strpos($index, 'id="searchToggle"') < strpos($index, 'id="menuToggle"'), 'Search must appear left of the menu button');
check(str_contains($index, 'data-menu-route="create" data-auth-only hidden'), 'Drawer creator route guard is missing');
check(str_contains($index, 'data-view="feed"'), 'Feed view is missing');
check(str_contains($index, 'data-view="creator"'), 'Public creator view is missing');
check(str_contains($index, 'data-view="create"'), 'Create view is missing');
check(str_contains($index, 'data-view="mine"'), 'My posts view is missing');
check(str_contains($index, 'data-view="balance"'), 'Balance view is missing');
check(!str_contains($index, 'class="mobile-nav"'), 'Bottom mobile navigation must remain removed');
check(!str_contains($index, '<h1>Latest stories</h1>'), 'Latest stories heading must remain removed');
check(str_contains($index, 'class="composer-card card" data-auth-only hidden'), 'Guest composer guard is missing');
check(str_contains($index, 'id="loadMoreButton" type="button" hidden'), 'Hidden feed pagination source is missing');
check(str_contains($index, 'id="creatorLoadMoreButton" type="button" hidden'), 'Hidden creator pagination source is missing');
check(str_contains($index, 'id="postDialog"'), 'Post reader is missing');
check(str_contains($index, 'Clean posts publish immediately'), 'Instant-publish disclosure is missing');
check(str_contains($index, '>Publish post<'), 'Publish button is missing');
check(str_contains($index, 'znews-premium.css?v=4'), 'Latest feed styling is not activated');
check(str_contains($index, 'znews-bootstrap.js?v=4'), 'Latest bootstrap is not activated');
check(!str_contains($index, 'znews-quick-login.js'), 'Removed standalone login module is still loaded');
check(!str_contains($index, '<div class="header-actions">'), 'Visible Sign in/header actions are still present');
check(!preg_match('/\b(?:Earn|Income|Cash|Profit|Revenue|Job|Work)\b/i', strip_tags($index)), 'Forbidden public wording exists in HTML');

$config = contents($root . '/znews/assets/znews-config.js');
check(str_contains($config, "provider: 'INMOBI'"), 'InMobi is not the configured provider');
check(str_contains($config, "mode: existing.ads?.mode || 'TEST'"), 'Ad test mode default is missing');
check(str_contains($config, 'enabled: existing.ads?.enabled === true'), 'Ads must require explicit enablement');
check(!str_contains($config, 'persistentSessionStorageKey'), 'Standalone persistent login storage remains configured');
check(!preg_match('/(?:secret|private[_-]?key)\s*[:=]\s*[\'\"][^\'\"]{8,}/i', $config), 'Possible secret committed in public config');

$api = contents($root . '/znews/assets/znews-api.js');
foreach ([
    'znews/auth/handoff.php', 'znews/public/feed.php', 'znews/public/post.php',
    'znews/posts/create.php', 'znews/media/upload.php', 'znews/likes/set.php',
    'znews/comments/create.php', 'znews/shares/create.php', 'znews/views/start.php',
    'znews/views/heartbeat.php', 'znews/views/complete.php', 'znews/balance/summary.php',
    'znews/balance/ledger.php', 'znews/transfers/create.php',
] as $endpoint) {
    check(str_contains($api, $endpoint), "API endpoint missing from client: {$endpoint}");
}
check(str_contains($api, 'sessionStorage.getItem'), 'Session storage integration is missing');
check(str_contains($api, 'X-SESSION-TOKEN'), 'Session header is missing');
check(str_contains($api, 'X-APP-KEY'), 'App-key header is missing');
check(str_contains($api, "credentials: 'same-origin'"), 'Same-origin cookie policy is missing');
check(!str_contains($api, 'verifyPassword('), 'Standalone password login remains in Z News API client');
check(!str_contains($api, 'pinLogin('), 'Standalone PIN login remains in Z News API client');

$creator = contents($root . '/znews/assets/znews-creator.js');
foreach (['znews/media/upload.php', 'znews/posts/create.php', 'znews/posts/details.php', 'znews/posts/update.php', 'znews/posts/delete.php'] as $endpoint) {
    check(str_contains($creator, $endpoint), "Creator endpoint missing: {$endpoint}");
}
check(str_contains($creator, 'expected_updated_at'), 'Creator edit/delete version guard is missing');
check(str_contains($creator, 'idempotency_key'), 'Creator mutation idempotency is missing');
check(str_contains($creator, 'published_immediately'), 'Creator UI ignores publication result');
check(str_contains($creator, 'stopImmediatePropagation'), 'Duplicate create-submit protection is missing');
check(str_contains($creator, 'Remove current image'), 'Image removal UI is missing');

$access = contents($root . '/znews/assets/znews-access.js');
check(str_contains($access, "['create', 'mine', 'balance']"), 'Guest creator-route guard is missing');
check(str_contains($access, "'/user/register'"), 'Guest registration route is missing');
check(str_contains($access, '[data-action="like"]'), 'Guest authenticated-action cleanup is missing');

$header = contents($root . '/znews/assets/znews-header.js');
check(str_contains($header, 'function openSearch'), 'Search open state is missing');
check(str_contains($header, "znewsOverlay: 'search'"), 'Search does not create a browser history state');
check(str_contains($header, "window.addEventListener('popstate'"), 'Browser Back search handler is missing');
check(str_contains($header, 'history.back()'), 'Search close does not consume its history state');
check(str_contains($header, 'haystack.includes(query)'), 'Loaded-story search filtering is missing');
check(str_contains($header, "menuDrawer.classList.add('is-open')"), 'Right drawer open behavior is missing');
check(str_contains($header, '.desktop-nav [data-route='), 'Drawer does not work without the removed bottom navigation');

$profile = contents($root . '/znews/assets/znews-profile.js');
check(str_contains($profile, "wrapApiMethod('publicFeed')"), 'Feed creator identity capture is missing');
check(str_contains($profile, "znews/public/creator.php"), 'Public creator API integration is missing');
check(str_contains($profile, '/znews/creator/'), 'Clean creator route is missing from the UI');
check(str_contains($profile, 'data-profile-post-id'), 'Creator public post rendering is missing');

$feedUi = contents($root . '/znews/assets/znews-feed-ui.js');
check(str_contains($feedUi, 'patchPublicFeed()'), 'Fair feed response capture is missing');
check(str_contains($feedUi, 'feed_session_id'), 'Feed session ID is not captured for impression binding');
check(str_contains($feedUi, 'znews/public/impression.php'), 'Visible feed impression reporting is missing');
check(str_contains($feedUi, 'IntersectionObserver'), 'Automatic pagination and visibility tracking require IntersectionObserver');
check(str_contains($feedUi, "rootMargin: '700px 0px'"), 'Infinite scrolling preload margin is missing');
check(str_contains($feedUi, "button.textContent = 'See more'"), 'Two-line preview lacks See more');
check(str_contains($feedUi, 'dedupeFeedCards()'), 'Client duplicate-card protection is missing');
check(str_contains($feedUi, 'You’re all caught up.'), 'End-of-feed state is missing');
check(str_contains($feedUi, "data-profile-action"), 'Creator profile preview must open the full post');

$publicCreator = contents($root . '/api/znews/public/creator.php');
check(str_contains($publicCreator, 'ZNEWS_USER_POSTS/'), 'Creator endpoint does not use the user post index');
check(str_contains($publicCreator, 'znews_post_is_public($post)'), 'Creator endpoint may expose non-public posts');
check(!str_contains($publicCreator, "fb_get('USERS/"), 'Creator endpoint must not expose private user records');

$feedEndpoint = contents($root . '/api/znews/public/feed.php');
$impressionEndpoint = contents($root . '/api/znews/public/impression.php');
$ranking = contents($root . '/api/znews/lib/feed_ranking.php');
check(str_contains($feedEndpoint, 'znews_fair_feed_page'), 'Public feed is not using the fair ranking engine');
check(str_contains($impressionEndpoint, 'znews_feed_record_impressions'), 'Feed impression endpoint is not wired');
check(str_contains($impressionEndpoint, 'api_require_app_key()'), 'Feed impression writes must require app-key validation');
check(str_contains($ranking, "['F', 'F', 'E', 'F', 'F', 'E', 'F', 'F', 'E', 'F']"), '70/30 fresh-fair pattern is missing');
check(str_contains($ranking, 'ZNEWS_FEED_SESSIONS/'), 'Stable server feed sessions are missing');
check(str_contains($ranking, 'ZNEWS_FEED_EXPOSURE/'), 'Underexposed-post metrics are missing');
check(str_contains($ranking, 'ZNEWS_FEED_SESSION_IMPRESSIONS/'), 'Per-session impression dedupe is missing');
check(!preg_match('/\b(?:wallet|ledger|transfer)\b/i', $ranking), 'Feed ranking must not touch financial modules');

$ads = contents($root . '/znews/assets/znews-ads.js');
check(str_contains($ads, 'registerProviderRenderer'), 'Provider renderer registration is missing');
check(str_contains($ads, 'Provider secrets, reported value and creator settlement never belong'), 'Ad trust-boundary note is missing');
check(!str_contains($ads, 'ZNEWS_AD_INGESTION_SECRET'), 'Private ingestion secret name leaked into browser adapter');

$app = contents($root . '/znews/assets/znews.js');
check(str_contains($app, 'beginView(postId)'), 'View lifecycle is not started by the reader');
check(str_contains($app, 'window.setTimeout(() => heartbeatView(), 5000)'), 'First heartbeat is missing');
check(str_contains($app, 'window.setTimeout(() => heartbeatView(), 15000)'), 'Second heartbeat is missing');
check(str_contains($app, 'await completeView()'), 'View completion guard is missing');
check(str_contains($app, 'Minimum transfer amount is ৳500.'), 'Minimum transfer disclosure is missing');
check(str_contains($app, 'state.balanceMicros < 500_000_000'), 'Transfer button threshold guard is missing');

$premium = contents($root . '/znews/assets/znews-premium.css');
check(str_contains($premium, '#feedView>.composer-card{margin-bottom:18px}'), 'Composer-to-feed spacing is missing');
check(str_contains($premium, '-webkit-line-clamp:2'), 'Feed text is not clamped to two lines');
check(str_contains($premium, '.see-more-button'), 'See more styling is missing');
check(str_contains($premium, '#feedList .post-media,#creatorList .post-media'), 'Full feed image override is missing');
check(str_contains($premium, 'max-height:none'), 'Feed images are still height-cropped');
check(str_contains($premium, '.auto-load-source'), 'Manual load control is not visually hidden');
check(str_contains($premium, '.feed-scroll-sentinel'), 'Infinite scroll sentinel styling is missing');

$bootstrap = contents($root . '/znews/assets/znews-bootstrap.js');
check(str_contains($bootstrap, 'znews-feed-ui.js?v=1'), 'Fair feed UI module is not loaded');
check(strpos($bootstrap, 'znews-feed-ui.js?v=1') < strpos($bootstrap, 'znews-profile.js?v=1'), 'Fair feed capture must load before profile capture');
check(strpos($bootstrap, 'znews-profile.js?v=1') < strpos($bootstrap, 'znews.js?v=3'), 'Creator identity capture must load before the feed app');
check(str_contains($bootstrap, 'znews-header.js?v=2'), 'Updated header interaction module is not loaded');

$serviceWorker = contents($root . '/znews/sw.js');
check(str_contains($serviceWorker, "const CACHE_NAME = 'znews-shell-v7'"), 'Fair feed shell cache version is stale');
check(str_contains($serviceWorker, 'znews-bootstrap.js?v=4'), 'Latest bootstrap is missing from shell cache');
check(str_contains($serviceWorker, 'znews-feed-ui.js?v=1'), 'Fair feed UI module is missing from shell cache');
check(str_contains($serviceWorker, 'znews-premium.css?v=4'), 'Latest feed CSS is missing from shell cache');
check(str_contains($serviceWorker, "url.pathname.startsWith('/api/')"), 'Service worker API exclusion is missing');
check(str_contains($serviceWorker, "request.method !== 'GET'"), 'Service worker mutation exclusion is missing');

$manifest = json_decode(contents($root . '/znews/manifest.webmanifest'), true);
check(is_array($manifest), 'Web manifest is invalid JSON');
check(($manifest['start_url'] ?? '') === '/znews/', 'Manifest start URL is incorrect');
check(($manifest['display'] ?? '') === 'standalone', 'Manifest standalone mode is missing');

$htaccess = contents($root . '/znews/.htaccess');
check(str_contains($htaccess, 'RewriteRule ^post/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean post route is missing');
check(str_contains($htaccess, 'RewriteRule ^creator/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean creator route is missing');
check(str_contains($htaccess, 'Options -Indexes'), 'Directory listing protection is missing');

$deployment = contents($root . '/.cpanel.yml');
check(preg_match('/\bassets docs images logo znews\b/', $deployment) === 1, 'cPanel deployment does not include znews');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    foreach ([
        'znews/assets/znews-config.js', 'znews/assets/znews-api.js', 'znews/assets/znews-ads.js',
        'znews/assets/znews-bootstrap.js', 'znews/assets/znews-access.js', 'znews/assets/znews-feed-ui.js',
        'znews/assets/znews-profile.js', 'znews/assets/znews.js', 'znews/assets/znews-header.js',
        'znews/assets/znews-creator.js', 'znews/assets/znews-instant-comments.js', 'znews/sw.js',
    ] as $relative) {
        $command = escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/' . $relative) . ' 2>&1';
        exec($command, $output, $status);
        check($status === 0, "JavaScript syntax failed: {$relative} " . implode("\n", $output));
        $output = [];
    }
}

echo "PASS: {$assertions} Z News web UI assertions.\n";
