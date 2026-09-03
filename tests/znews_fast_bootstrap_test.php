<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function fast_boot_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function fast_boot_source(string $relative): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relative);
    fast_boot_expect(is_string($source), 'Could not read ' . $relative);
    return (string)$source;
}

$index = fast_boot_source('znews/index.html');
$bootstrap = fast_boot_source('znews/assets/znews-bootstrap.js');
$app = fast_boot_source('znews/assets/znews.js');
$access = fast_boot_source('znews/assets/znews-access.js');
$reader = fast_boot_source('znews/assets/znews-reader.js');
$embeddedWorker = fast_boot_source('znews/sw.js');
$standaloneWorker = fast_boot_source('znews/sw-root.js');

$critical = [
    'znews-config.js?v=8',
    'znews-api.js?v=9',
    'znews-request-scheduler.js?v=1',
    'znews-progressive-feed.js?v=2',
    'znews-feed-ui.js?v=3',
    'znews-ads.js?v=2',
    'znews-bootstrap.js?v=23',
    'znews.js?v=21',
];
$last = -1;
foreach ($critical as $asset) {
    $needle = 'defer src="/znews/assets/' . $asset . '"';
    $position = strpos($index, $needle);
    fast_boot_expect($position !== false, "Critical defer asset is missing: {$asset}");
    fast_boot_expect($position > $last, "Critical dependency order is wrong: {$asset}");
    $last = (int)$position;
}

fast_boot_expect(!str_contains($index, '<script src="/znews/assets/znews-config.js'), 'Critical scripts remain parser-blocking.');
fast_boot_expect(!str_contains($bootstrap, "await loadScript('/znews/assets/znews-request-scheduler.js"), 'Scheduler remains in the sequential dynamic waterfall.');
fast_boot_expect(!str_contains($bootstrap, "await loadScript('/znews/assets/znews.js"), 'Main app remains in the sequential dynamic waterfall.');
fast_boot_expect(str_contains($bootstrap, 'const authReady = publicContentReady.then'), 'Auth is not separated from public feed startup.');
fast_boot_expect(str_contains($bootstrap, 'window.ZNEWS_AUTH_VERIFIED = verified === true'), 'Verified creator gate is missing.');
fast_boot_expect(str_contains($access, 'window.ZNEWS_AUTH_VERIFIED === true'), 'Access module can expose unverified creator controls.');
fast_boot_expect(str_contains($reader, 'window.ZNewsAccess?.authenticated === true'), 'Reader composer can expose an unverified session.');
fast_boot_expect(str_contains($app, 'hasVerifiedSession()'), 'Main app does not enforce the verified session gate.');
fast_boot_expect(str_contains($app, "markBoot('feed_request_start')"), 'Feed-start timing instrumentation is missing.');
fast_boot_expect(str_contains($app, "markBoot('first_card_dom_append')"), 'First-card timing instrumentation is missing.');
fast_boot_expect(str_contains($app, "new CustomEvent('znews:first-card'"), 'First-card post-paint trigger is missing.');
fast_boot_expect(str_contains($app, 'window.ZNEWS_APP_INITIALIZED = true'), 'Duplicate app initialization guard is missing.');
fast_boot_expect(str_contains($bootstrap, 'publicContentReady.then(() => {')
    && str_contains($bootstrap, 'void prepareServiceWorker();'), 'Service worker can still block public feed startup.');
fast_boot_expect(!str_contains($index, 'rel="stylesheet" href="/znews/assets/znews-reader.css'), 'Post-reader CSS remains render-blocking.');
fast_boot_expect(!str_contains($index, 'rel="stylesheet" href="/znews/assets/znews-weekly-review.css'), 'Auth-only weekly CSS remains render-blocking.');
fast_boot_expect(str_contains($bootstrap, "loadStylesheet('/znews/assets/znews-reader.css?v=2')"), 'Reader CSS post-paint loader is missing.');
fast_boot_expect(str_contains($bootstrap, "loadScript('/znews/assets/znews-creator.js?v=7')"), 'Creator module is unavailable after verified auth.');
fast_boot_expect(str_contains($bootstrap, "const creatorModules = authenticated ? ["), 'Auth-only modules are not verification-gated.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    fast_boot_expect(str_contains($worker, 'shell-v18'), 'Service-worker cache generation was not advanced.');
    fast_boot_expect(str_contains($worker, "SHELL_REVISION = 'fast-boot-1'"), 'Fast-boot shell revision is missing.');
    fast_boot_expect(str_contains($worker, 'znews-bootstrap.js?v=23'), 'Service worker has a stale bootstrap URL.');
    fast_boot_expect(str_contains($worker, 'znews.js?v=21'), 'Service worker has a stale app URL.');
    fast_boot_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'Service worker may intercept API requests.');
}

$node = trim((string)getenv('NODE_BINARY'));
if ($node !== '' && is_file($node)) {
    $command = escapeshellarg($node) . ' '
        . escapeshellarg($root . '/tests/znews_fast_bootstrap_runtime.js') . ' 2>&1';
    $output = [];
    $status = 1;
    exec($command, $output, $status);
    fast_boot_expect($status === 0, 'Fast-bootstrap browser runtime failed: ' . implode(' | ', $output));
    fast_boot_expect(str_contains(implode("\n", $output), 'PASS:')
        || str_contains(implode("\n", $output), 'SKIP:'), 'Fast-bootstrap runtime produced no result.');
}

echo "PASS: {$assertions} Z Sky fast-bootstrap assertions.\n";
