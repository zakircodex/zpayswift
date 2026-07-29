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
    'znews/index.html',
    'znews/.htaccess',
    'znews/manifest.webmanifest',
    'znews/sw.js',
    'znews/assets/znews-config.js',
    'znews/assets/znews-api.js',
    'znews/assets/znews-ads.js',
    'znews/assets/znews.js',
    'znews/assets/znews-creator.js',
    'znews/assets/znews.css',
    'docs/znews-inmobi-integration.md',
    'docs/znews-android-ui-contract.md',
];

foreach ($required as $relative) {
    check(is_file($root . '/' . $relative), "Required file missing: {$relative}");
}

$index = contents($root . '/znews/index.html');
check(str_contains($index, '<strong>Z News</strong>'), 'Z News brand is missing');
check(str_contains($index, 'Stories • Updates • Community'), 'Z News tagline is missing');
check(str_contains($index, 'data-view="feed"'), 'Feed view is missing');
check(str_contains($index, 'data-view="create"'), 'Create view is missing');
check(str_contains($index, 'data-view="mine"'), 'My posts view is missing');
check(str_contains($index, 'data-view="balance"'), 'Balance view is missing');
check(str_contains($index, 'data-znews-ad-slot="feed_sidebar"'), 'Sidebar ad slot is missing');
check(str_contains($index, 'id="authDialog"'), 'Authentication dialog is missing');
check(str_contains($index, 'id="postDialog"'), 'Post reader is missing');
check(str_contains($index, 'Clean posts publish immediately'), 'Instant-publish disclosure is missing');
check(str_contains($index, '>Publish post<'), 'Publish button is missing');
check(str_contains($index, 'znews-creator.js?v=1'), 'Creator management module is missing');
check(!str_contains($index, 'Submit for review'), 'Every post is still presented as requiring pre-approval');
check(!preg_match('/\b(?:Earn|Income|Cash|Profit|Revenue|Job|Work)\b/i', strip_tags($index)), 'Forbidden public wording exists in HTML');

$config = contents($root . '/znews/assets/znews-config.js');
check(str_contains($config, "provider: 'INMOBI'"), 'InMobi is not the configured provider');
check(str_contains($config, "mode: existing.ads?.mode || 'TEST'"), 'Ad test mode default is missing');
check(str_contains($config, 'enabled: existing.ads?.enabled === true'), 'Ads must require explicit enablement');
check(!preg_match('/(?:secret|private[_-]?key)\s*[:=]\s*[\'\"][^\'\"]{8,}/i', $config), 'Possible secret committed in public config');

$api = contents($root . '/znews/assets/znews-api.js');
foreach ([
    'znews/public/feed.php',
    'znews/public/post.php',
    'znews/posts/create.php',
    'znews/media/upload.php',
    'znews/likes/set.php',
    'znews/comments/create.php',
    'znews/shares/create.php',
    'znews/views/start.php',
    'znews/views/heartbeat.php',
    'znews/views/complete.php',
    'znews/balance/summary.php',
    'znews/balance/ledger.php',
    'znews/transfers/create.php',
] as $endpoint) {
    check(str_contains($api, $endpoint), "API endpoint missing from client: {$endpoint}");
}
check(str_contains($api, "sessionStorage.getItem"), 'Session storage integration is missing');
check(str_contains($api, "X-SESSION-TOKEN"), 'Session header is missing');
check(str_contains($api, "X-APP-KEY"), 'App-key header is missing');
check(str_contains($api, "credentials: 'same-origin'"), 'Same-origin cookie policy is missing');

$creator = contents($root . '/znews/assets/znews-creator.js');
foreach ([
    'znews/media/upload.php',
    'znews/posts/create.php',
    'znews/posts/details.php',
    'znews/posts/update.php',
    'znews/posts/delete.php',
] as $endpoint) {
    check(str_contains($creator, $endpoint), "Creator endpoint missing: {$endpoint}");
}
check(str_contains($creator, 'expected_updated_at'), 'Creator edit/delete version guard is missing');
check(str_contains($creator, 'idempotency_key'), 'Creator mutation idempotency is missing');
check(str_contains($creator, 'published_immediately'), 'Creator UI ignores publication result');
check(str_contains($creator, 'stopImmediatePropagation'), 'Duplicate create-submit protection is missing');
check(str_contains($creator, 'Remove current image'), 'Image removal UI is missing');
check(!str_contains($creator, 'ZNEWS_AD_NETWORK_SECRETS'), 'Server ad secrets leaked into creator client');

$ads = contents($root . '/znews/assets/znews-ads.js');
check(str_contains($ads, "registerProviderRenderer"), 'Provider renderer registration is missing');
check(str_contains($ads, "Provider secrets, reported value and creator settlement never belong"), 'Ad trust-boundary note is missing');
check(!str_contains($ads, 'ZNEWS_AD_INGESTION_SECRET'), 'Private ingestion secret name leaked into browser adapter');
check(!str_contains($ads, 'X-ZNEWS-AD-SIGNATURE'), 'Browser must not sign provider callbacks');

$app = contents($root . '/znews/assets/znews.js');
check(str_contains($app, 'beginView(postId)'), 'View lifecycle is not started by the reader');
check(str_contains($app, 'window.setTimeout(() => heartbeatView(), 5000)'), 'First heartbeat is missing');
check(str_contains($app, 'window.setTimeout(() => heartbeatView(), 15000)'), 'Second heartbeat is missing');
check(str_contains($app, 'await completeView()'), 'View completion guard is missing');
check(str_contains($app, 'Minimum transfer amount is ৳500.'), 'Minimum transfer disclosure is missing');
check(str_contains($app, 'state.balanceMicros < 500_000_000'), 'Transfer button threshold guard is missing');
check(!str_contains($app, 'ZNEWS_AD_NETWORK_SECRETS'), 'Server ad-network map leaked into browser app');

$serviceWorker = contents($root . '/znews/sw.js');
check(str_contains($serviceWorker, "const CACHE_NAME = 'znews-shell-v2'"), 'Creator shell cache version is stale');
check(str_contains($serviceWorker, 'znews-creator.js?v=1'), 'Creator module is missing from shell cache');
check(str_contains($serviceWorker, "url.pathname.startsWith('/api/')"), 'Service worker API exclusion is missing');
check(str_contains($serviceWorker, "request.method !== 'GET'"), 'Service worker mutation exclusion is missing');

$manifest = json_decode(contents($root . '/znews/manifest.webmanifest'), true);
check(is_array($manifest), 'Web manifest is invalid JSON');
check(($manifest['start_url'] ?? '') === '/znews/', 'Manifest start URL is incorrect');
check(($manifest['display'] ?? '') === 'standalone', 'Manifest standalone mode is missing');

$htaccess = contents($root . '/znews/.htaccess');
check(str_contains($htaccess, 'RewriteRule ^post/([A-Za-z0-9_-]+)/?$ index.html [L]'), 'Clean post route is missing');
check(str_contains($htaccess, 'Options -Indexes'), 'Directory listing protection is missing');

$deployment = contents($root . '/.cpanel.yml');
check(preg_match('/\bassets docs images logo znews\b/', $deployment) === 1, 'cPanel deployment does not include znews');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    foreach ([
        'znews/assets/znews-config.js',
        'znews/assets/znews-api.js',
        'znews/assets/znews-ads.js',
        'znews/assets/znews.js',
        'znews/assets/znews-creator.js',
        'znews/sw.js',
    ] as $relative) {
        $command = escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/' . $relative) . ' 2>&1';
        exec($command, $output, $status);
        check($status === 0, "JavaScript syntax failed: {$relative} " . implode("\n", $output));
        $output = [];
    }
}

echo "PASS: {$assertions} Z News web UI assertions.\n";
