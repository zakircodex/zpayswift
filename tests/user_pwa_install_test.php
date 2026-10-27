<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function user_pwa_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function user_pwa_read(string $path): string
{
    $source = file_get_contents($path);
    user_pwa_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$manifestPath = $root . '/api/user/manifest.webmanifest';
$manifest = json_decode(user_pwa_read($manifestPath), true);
user_pwa_expect(is_array($manifest), 'user manifest is not valid JSON');
user_pwa_expect(($manifest['id'] ?? '') === '/user/', 'manifest app id must stay inside the user panel');
user_pwa_expect(($manifest['start_url'] ?? '') === '/user/', 'manifest start URL is incorrect');
user_pwa_expect(($manifest['scope'] ?? '') === '/user/', 'manifest scope must not control admin or public pages');
user_pwa_expect(($manifest['display'] ?? '') === 'standalone', 'standalone display mode is missing');
user_pwa_expect(($manifest['theme_color'] ?? '') === '#07172f', 'manifest theme color does not match the user shell');

$icons = $manifest['icons'] ?? [];
user_pwa_expect(is_array($icons) && count($icons) === 2, 'manifest must provide the required install icons');
foreach ([192, 512] as $index => $size) {
    $icon = $icons[$index] ?? [];
    $expectedSrc = "/assets/brand/pwa-icon-{$size}.png";
    user_pwa_expect(($icon['src'] ?? '') === $expectedSrc, "missing {$size}px manifest icon");
    user_pwa_expect(($icon['purpose'] ?? '') === 'any', "{$size}px icon must preserve the full Z-Pay logo");

    $imagePath = $root . $expectedSrc;
    $imageInfo = is_file($imagePath) ? getimagesize($imagePath) : false;
    user_pwa_expect(
        is_array($imageInfo) && $imageInfo[0] === $size && $imageInfo[1] === $size,
        "{$size}px manifest icon has incorrect dimensions"
    );
}

$touchIconPath = $root . '/assets/brand/pwa-icon-180.png';
$touchIconInfo = is_file($touchIconPath) ? getimagesize($touchIconPath) : false;
user_pwa_expect(
    is_array($touchIconInfo) && $touchIconInfo[0] === 180 && $touchIconInfo[1] === 180,
    'Apple touch icon must be exactly 180x180'
);

$head = user_pwa_read($root . '/api/user/includes/pwa-head.php');
user_pwa_expect(str_contains($head, 'rel="manifest" href="/user/manifest.webmanifest"'), 'shared PWA manifest link is missing');
user_pwa_expect(str_contains($head, 'apple-mobile-web-app-capable" content="yes"'), 'iPhone standalone mode is missing');
user_pwa_expect(str_contains($head, '/assets/brand/pwa-icon-180.png'), 'iPhone Home Screen icon is incorrect');

foreach (['includes/head.php', 'index.php', 'register.php', 'forgot.php'] as $relativePath) {
    $source = user_pwa_read($root . '/api/user/' . $relativePath);
    user_pwa_expect(str_contains($source, 'pwa-head.php'), "{$relativePath} does not include install metadata");
}

foreach (['includes/common-scripts.php', 'index.php', 'register.php', 'forgot.php'] as $relativePath) {
    $source = user_pwa_read($root . '/api/user/' . $relativePath);
    user_pwa_expect(str_contains($source, 'pwa-script.php'), "{$relativePath} does not register install support");
}

$registration = user_pwa_read($root . '/api/user/assets/pwa-register.js');
user_pwa_expect(str_contains($registration, "register('/user/pwa-sw.js'"), 'service worker registration URL is incorrect');
user_pwa_expect(str_contains($registration, "scope: '/user/'"), 'service worker registration scope is too broad');
user_pwa_expect(str_contains($registration, "updateViaCache: 'none'"), 'service worker updates may be served stale');
user_pwa_expect(str_contains($registration, '.catch('), 'optional install support must fail without interrupting account use');

$worker = user_pwa_read($root . '/api/user/pwa-sw.js');
user_pwa_expect(str_contains($worker, "request.method !== 'GET'"), 'service worker must ignore all mutations');
user_pwa_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'service worker API exclusion is missing');
user_pwa_expect(str_contains($worker, 'event.respondWith(fetch(request))'), 'user pages are not explicitly network-only');
user_pwa_expect(!str_contains($worker, 'caches.'), 'financial user panel must not use CacheStorage');
user_pwa_expect(!str_contains($worker, 'cache.put'), 'authenticated responses must never be persisted');

$htaccess = user_pwa_read($root . '/.htaccess');
$manifestMime = 'AddType application/manifest+json .webmanifest';
$manifestRoute = strpos($htaccess, 'RewriteRule ^user/manifest\.webmanifest$');
$workerRoute = strpos($htaccess, 'RewriteRule ^user/pwa-sw\.js$');
$userFallback = strpos($htaccess, 'RewriteRule ^user(?:/.*)?$ /api/user/index.php');
user_pwa_expect($manifestRoute !== false && $manifestRoute < $userFallback, 'manifest route is shadowed by the user fallback');
user_pwa_expect($workerRoute !== false && $workerRoute < $userFallback, 'service worker route is shadowed by the user fallback');
user_pwa_expect(str_contains($htaccess, '<Files "pwa-sw.js">'), 'service worker no-cache headers are missing');
user_pwa_expect(str_contains($htaccess, $manifestMime), 'web manifest MIME type is not configured');

$browserRouter = user_pwa_read($root . '/tests/user_panel_browser_router.php');
user_pwa_expect(str_contains($browserRouter, "'/user/manifest.webmanifest'"), 'browser tests cannot resolve the manifest route');
user_pwa_expect(str_contains($browserRouter, "'/user/pwa-sw.js'"), 'browser tests cannot resolve the service worker route');

echo "User PWA install tests passed ({$assertions} assertions).\n";
