<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function zsky24_reload_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function zsky24_reload_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    zsky24_reload_expect(is_file($path), 'missing file: ' . $relative);
    $source = file_get_contents($path);
    zsky24_reload_expect(is_string($source), 'could not read: ' . $relative);
    return $source;
}

$index = zsky24_reload_read('znews/index.html');
$api = zsky24_reload_read('znews/assets/znews-api.js');
$bootstrap = zsky24_reload_read('znews/assets/znews-bootstrap.js');
$standaloneSw = zsky24_reload_read('znews/sw-root.js');
$embeddedSw = zsky24_reload_read('znews/sw.js');

zsky24_reload_expect(str_contains($index, 'znews-config.js?v=8'), 'config cache-busting revision was not advanced');
zsky24_reload_expect(str_contains($index, 'znews-api.js?v=10'), 'API cache-busting revision was not advanced');
zsky24_reload_expect(str_contains($index, 'znews-bootstrap.js?v=25'), 'bootstrap cache-busting revision was not advanced');
zsky24_reload_expect(str_contains($bootstrap, 'znews-weekly-review.js?v=1'), 'weekly creator report cache-busting revision is missing');

zsky24_reload_expect(str_contains($api, 'new AbortController()'), 'API requests do not have an abort controller');
zsky24_reload_expect(str_contains($api, "code: 'REQUEST_TIMEOUT'"), 'API timeout error mapping is missing');
zsky24_reload_expect(str_contains($api, 'timeoutMs = this.defaultTimeoutMs'), 'API default timeout is missing');
zsky24_reload_expect(str_contains($api, 'timeoutMs: 6000'), 'stored creator session validation is not time-bounded');
zsky24_reload_expect(str_contains($api, 'timeoutMs: 30000'), 'media upload does not have an explicit extended timeout');

zsky24_reload_expect(str_contains($bootstrap, 'publicContentReady.then(() => {'), 'post-paint work is not gated behind public content');
zsky24_reload_expect(str_contains($bootstrap, 'void prepareServiceWorker();'), 'service worker update is not started in the background');
zsky24_reload_expect(str_contains($bootstrap, 'await registration.update();'), 'service worker registration does not request an immediate update');
zsky24_reload_expect(str_contains($bootstrap, 'Service-worker failures must never block the public feed.'), 'service worker failure is not explicitly non-blocking');
zsky24_reload_expect(str_contains($bootstrap, 'Timed out loading'), 'dynamic script loading is not time-bounded');
zsky24_reload_expect(str_contains($bootstrap, "deferred: true"), 'transient creator session validation failure cannot fall through to the public shell');
zsky24_reload_expect(str_contains($index, 'defer src="/znews/assets/znews-request-scheduler.js?v=1"'), 'priority request scheduler is not defer-loaded');
zsky24_reload_expect(str_contains($index, 'defer src="/znews/assets/znews-progressive-feed.js?v=3"'), 'progressive feed controller is not defer-loaded');
zsky24_reload_expect(str_contains($index, 'defer src="/znews/assets/znews.js?v=22"'), 'deferred app shell revision was not advanced');

foreach ([$standaloneSw, $embeddedSw] as $sw) {
    zsky24_reload_expect(str_contains($sw, 'shell-v20'), 'service worker cache generation was not advanced');
    zsky24_reload_expect(str_contains($sw, 'networkFirst(request'), 'service worker is not network-first');
    zsky24_reload_expect(str_contains($sw, "fetch(request, { cache: 'no-store' })"), 'service worker online refresh bypass is missing');
    zsky24_reload_expect(str_contains($sw, 'Promise.allSettled'), 'one missing shell asset can still fail the whole service-worker install');
    zsky24_reload_expect(str_contains($sw, 'znews-config.js?v=8'), 'service worker config revision does not match index');
    zsky24_reload_expect(str_contains($sw, 'znews-api.js?v=10'), 'service worker API revision does not match index');
    zsky24_reload_expect(str_contains($sw, 'znews-weekly-review.js?v=1'), 'service worker weekly report revision does not match index');
    zsky24_reload_expect(str_contains($sw, 'znews-weekly-review.css?v=1'), 'service worker weekly report stylesheet does not match index');
    zsky24_reload_expect(str_contains($sw, 'znews-bootstrap.js?v=25'), 'service worker bootstrap revision does not match index');
    zsky24_reload_expect(str_contains($sw, 'znews-ads.js?v=2'), 'service worker ad shell revision does not match index');
    zsky24_reload_expect(str_contains($sw, 'znews-request-scheduler.js?v=1'), 'service worker priority scheduler revision is missing');
    zsky24_reload_expect(str_contains($sw, 'znews-progressive-feed.js?v=3'), 'service worker progressive feed revision is missing');
    zsky24_reload_expect(str_contains($sw, 'znews.js?v=22'), 'service worker app revision does not match index');
    zsky24_reload_expect(!str_contains($sw, 'cached || fetch(request)'), 'legacy cache-first script strategy remains active');
}

echo "Z Sky 24 reload and guest bootstrap regression passed.\n";
