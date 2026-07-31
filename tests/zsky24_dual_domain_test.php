<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function zsky_source(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
}

function zsky_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$rootRewrite = zsky_source('.htaccess');
$config = zsky_source('znews/assets/znews-config.js');
$bootstrap = zsky_source('znews/assets/znews-bootstrap.js');
$api = zsky_source('znews/assets/znews-api.js');
$launcher = zsky_source('api/user/znews.php');
$handoff = zsky_source('api/znews/auth/handoff.php');
$domain = zsky_source('api/znews/lib/domain.php');
$embeddedWorker = zsky_source('znews/sw.js');
$standaloneWorker = zsky_source('znews/sw-root.js');
$index = zsky_source('znews/index.html');

zsky_expect(str_contains($rootRewrite, 'zsky24\.com'), 'Standalone host routing is missing.');
zsky_expect(str_contains($rootRewrite, 'RewriteRule ^(?:post|creator)/'), 'Standalone clean routes are missing.');
zsky_expect(str_contains($rootRewrite, 'api/(?!znews'), 'Standalone host API restriction is missing.');
zsky_expect(str_contains($rootRewrite, 'RewriteRule ^deploy_version\\.txt$ - [L,NC]'), 'Standalone deployment marker allowlist is missing.');
zsky_expect(str_contains($rootRewrite, 'admin|subadmin|user|wallet|worker|private'), 'Sensitive route restriction is missing.');

zsky_expect(str_contains($config, "standaloneHost = 'zsky24.com'"), 'Standalone hostname config is missing.');
zsky_expect(str_contains($config, "routeBase = standalone ? '' : '/znews'"), 'Dual route base is missing.');
zsky_expect(str_contains($config, 'canonicalUrl:'), 'Canonical URL builder is missing.');
zsky_expect(str_contains($bootstrap, "document.querySelector('link[rel=\"canonical\"]')"), 'Canonical metadata sync is missing.');
zsky_expect(str_contains($index, 'https://zsky24.com/'), 'Primary canonical host is missing from HTML.');
zsky_expect(str_contains($index, 'Z Sky 24') && !str_contains($index, '<strong>Z News</strong>'), 'Final public branding is incomplete.');
zsky_expect(str_contains($api, "credentials: 'same-origin'"), 'API must remain same-origin on both hosts.');

zsky_expect(str_contains($launcher, 'ZNEWS_HANDOFF_GRANTS') || str_contains($domain, 'ZNEWS_HANDOFF_GRANTS'), 'Server-side grant namespace is missing.');
zsky_expect(str_contains($launcher, 'znews_handoff_encrypt_token'), 'Session token is not protected server-side.');
zsky_expect(str_contains($launcher, "'target_host' => znews_handoff_target_host()"), 'Grant target host binding is missing.');
zsky_expect(str_contains($launcher, "'expires_at' => \$now + 90"), 'Grant expiry is not 90 seconds.');
zsky_expect(!str_contains($launcher, 'session_token='), 'Real session token appears in the redirect URL.');
zsky_expect(str_contains($handoff, 'fb_get_with_etag') && str_contains($handoff, 'fb_put_if_match'), 'Atomic single-use grant claim is missing.');
zsky_expect(str_contains($handoff, 'ZNEWS_HANDOFF_REPLAYED'), 'Replay response is missing.');
zsky_expect(str_contains($handoff, 'auth_session_epoch') && str_contains($handoff, 'device_id'), 'Session/device binding is missing.');
zsky_expect(str_contains($handoff, 'znews_request_host()'), 'Intended-host validation is missing.');

zsky_expect(str_contains($embeddedWorker, 'zsky24-embedded-shell-v8'), 'Embedded PWA namespace is missing.');
zsky_expect(str_contains($standaloneWorker, 'zsky24-standalone-shell-v8'), 'Standalone PWA namespace is missing.');
zsky_expect(str_contains($embeddedWorker, "url.pathname.startsWith('/api/')"), 'Embedded worker may cache API responses.');
zsky_expect(str_contains($standaloneWorker, "url.pathname.startsWith('/api/')"), 'Standalone worker may cache API responses.');

if ($failures) {
    fwrite(STDERR, "Z Sky 24 dual-domain contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z Sky 24 dual-domain contract passed.\n";
