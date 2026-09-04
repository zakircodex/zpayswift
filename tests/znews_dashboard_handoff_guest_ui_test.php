<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function znews_contract_read(string $path): string
{
    $value = file_get_contents($path);
    if (!is_string($value)) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $value;
}

function znews_contract_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$launcher = znews_contract_read($root . '/api/user/znews.php');
$handoff = znews_contract_read($root . '/api/znews/auth/handoff.php');
$session = znews_contract_read($root . '/api/znews/auth/session.php');
$dashboard = znews_contract_read($root . '/api/user/dashboard.php');
$rewrite = znews_contract_read($root . '/.htaccess');
$index = znews_contract_read($root . '/znews/index.html');
$config = znews_contract_read($root . '/znews/assets/znews-config.js');
$api = znews_contract_read($root . '/znews/assets/znews-api.js');
$bootstrap = znews_contract_read($root . '/znews/assets/znews-bootstrap.js');
$access = znews_contract_read($root . '/znews/assets/znews-access.js');
$serviceWorker = znews_contract_read($root . '/znews/sw.js');

znews_contract_expect(str_contains($launcher, 'user_page_require_auth()'), 'Dashboard launcher must require the existing Z-Pay web session.');
znews_contract_expect(str_contains($launcher, 'random_bytes(32)'), 'Handoff code must use cryptographic randomness.');
znews_contract_expect(str_contains($launcher, "'expires_at' => \$now + 90"), 'Handoff must be short-lived.');
znews_contract_expect(str_contains($launcher, 'session_hash($sessionToken)'), 'Handoff must be bound to the existing Z-Pay session.');
znews_contract_expect(str_contains($launcher, "znews_handoff_target_host() . '/#handoff='"), 'Handoff code must travel to the standalone host in the URL fragment.');

znews_contract_expect(str_contains($handoff, "api_require_method('POST')"), 'Handoff exchange must require POST.');
znews_contract_expect(str_contains($handoff, 'api_require_app_key()'), 'Handoff exchange must require the app key contract.');
znews_contract_expect(str_contains($handoff, 'fb_get_with_etag($path)'), 'Cross-domain handoff must load a server-side grant.');
znews_contract_expect(str_contains($handoff, 'hash_equals'), 'Handoff verification must be timing-safe.');
znews_contract_expect(str_contains($handoff, "['used'] = true"), 'Handoff must be consumed once.');
znews_contract_expect(str_contains($handoff, "\$profilePhoto === ''"), 'Handoff must support legacy profile photo fallback.');
znews_contract_expect(!str_contains($launcher, 'session_token='), 'Real session tokens must never be placed in the redirect URL.');

znews_contract_expect(str_contains($session, "api_require_method('GET')"), 'Stored creator validation must be GET-only.');
znews_contract_expect(str_contains($session, 'znews_require_creator(true)'), 'Stored creator validation must verify the current server session.');
znews_contract_expect(str_contains($session, "'access_mode' => 'CREATOR'"), 'Stored creator validation must return creator mode.');

znews_contract_expect(str_contains($dashboard, 'href="/user/znews"'), 'User dashboard must expose the Z News launcher.');
znews_contract_expect(str_contains($rewrite, 'RewriteRule ^user/znews/?$ /api/user/znews.php'), 'Clean /user/znews route must map to the launcher.');

znews_contract_expect(str_contains($index, 'brand-premium'), 'Premium Z News branding must be enabled.');
znews_contract_expect(!str_contains($index, '<div class="header-actions">'), 'Visible header actions must be removed.');
znews_contract_expect(str_contains($index, 'id="sessionButton" type="button" hidden'), 'Legacy session hook must remain hidden from users.');
znews_contract_expect(str_contains($index, 'id="refreshButton" type="button" hidden'), 'Manual refresh control must not be visible.');
znews_contract_expect(str_contains($index, 'class="composer-card card" data-auth-only hidden'), 'Composer must be hidden until dashboard creator access is granted.');
znews_contract_expect(str_contains($index, 'id="commentForm" class="comment-composer" data-auth-only hidden'), 'Guest readers must not see the comment composer.');
znews_contract_expect(str_contains($index, 'id="commentGuestCta"'), 'Guest reader comment CTA is missing.');
znews_contract_expect(str_contains($index, 'href="https://zpayswift.com/user" data-guest-only data-zpay-login'), 'Join Z-Pay must link directly to the Z-Pay login page.');
znews_contract_expect(!str_contains($index, 'data-zpay-register'), 'The retired Join Z-Pay registration target must not remain in the page.');
znews_contract_expect(str_contains($index, 'interactive-widget=resizes-content'), 'Android keyboard resize mode is missing.');
znews_contract_expect(str_contains($index, 'znews-bootstrap.js?v=25'), 'Reload-safe handoff bootstrap must be activated.');
znews_contract_expect(str_contains($bootstrap, 'znews-weekly-review.js?v=1'), 'Creator weekly report module must remain available after verified access.');
znews_contract_expect(!str_contains($index, 'znews-quick-login.js'), 'Standalone Z News PIN login must not be loaded.');

znews_contract_expect(str_contains($api, 'exchangeHandoff(code, options = {})'), 'API client must support one-time dashboard handoff exchange.');
znews_contract_expect(str_contains($api, 'validateCreatorSession(options = {})'), 'API client must validate stored creator access.');
znews_contract_expect(str_contains($api, 'znews/auth/session.php'), 'Creator validation endpoint is missing from the API client.');
znews_contract_expect(str_contains($api, 'timeoutMs: 6000'), 'Stored creator validation must be time-bounded.');
znews_contract_expect(!str_contains($api, 'verifyPassword('), 'Z News API client must not expose password login.');
znews_contract_expect(!str_contains($api, 'pinLogin('), 'Z News API client must not expose standalone PIN login.');
znews_contract_expect(!str_contains($api, 'localStorage.setItem'), 'Creator session must not be persisted as a standalone Z News login.');

znews_contract_expect(str_contains($bootstrap, 'const authReady = publicContentReady.then'), 'Handoff/session validation must start independently after public content.');
znews_contract_expect(str_contains($bootstrap, 'window.ZNEWS_AUTH_VERIFIED = verified === true'), 'Creator access must require successful server validation.');
znews_contract_expect(str_contains($bootstrap, "deferred: true"), 'Transient validation failures must not block the guest shell.');
znews_contract_expect(str_contains($bootstrap, 'let pendingHandoffCode = handoffCode().trim()'), 'Handoff code must be captured before app history can remove the URL fragment.');
znews_contract_expect(str_contains($bootstrap, "if (pendingHandoffCode) clearHandoffFragment()"), 'Captured handoff code must be removed from the visible URL immediately.');
znews_contract_expect(str_contains($bootstrap, "pendingHandoffCode = ''"), 'One-time handoff code must be consumed from memory before exchange.');
znews_contract_expect(str_contains($bootstrap, 'znews-reader.js?v=4'), 'Latest post reader module must load after public content.');
znews_contract_expect(str_contains($bootstrap, 'void prepareServiceWorker();'), 'Service-worker refresh must remain non-blocking.');
znews_contract_expect(str_contains($config, 'zpayLoginUrl: `${zpayOrigin}/user`'), 'Canonical Z-Pay login URL is missing from frontend configuration.');
znews_contract_expect(!str_contains($config, 'zpayRegisterUrl'), 'Guest navigation must not retain the registration URL.');
znews_contract_expect(str_contains($access, "['create', 'mine', 'balance']"), 'Guest-only route guard must cover creator sections.');
znews_contract_expect(str_contains($access, 'config.zpayLoginUrl'), 'Guest join action must open the existing Z-Pay login page.');
znews_contract_expect(str_contains($access, '[data-action="like"]'), 'Guest readers must not receive authenticated like controls.');
znews_contract_expect(str_contains($serviceWorker, 'zsky24-embedded-shell-v20'), 'Current embedded PWA cache namespace is missing.');
znews_contract_expect(str_contains($serviceWorker, 'znews-reader.js?v=4'), 'Latest reader module must be cached.');
znews_contract_expect(str_contains($serviceWorker, 'znews-weekly-review.js?v=1'), 'Creator weekly report module must be cached.');
znews_contract_expect(str_contains($serviceWorker, "url.pathname.startsWith('/api/')"), 'Service worker must not cache API responses.');
znews_contract_expect(!str_contains($serviceWorker, 'znews-quick-login.js'), 'Removed login module must not remain in the PWA cache.');

if ($failures) {
    fwrite(STDERR, "Z News dashboard handoff/guest UI contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z News dashboard handoff and guest UI contract passed.\n";
