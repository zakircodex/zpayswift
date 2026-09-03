<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function new_tab_balance_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dashboard = file_get_contents($root . '/api/user/dashboard.php');
$app = file_get_contents($root . '/znews/assets/znews.js');
$index = file_get_contents($root . '/znews/index.html');
$bootstrap = file_get_contents($root . '/znews/assets/znews-bootstrap.js');
$embeddedWorker = file_get_contents($root . '/znews/sw.js');
$standaloneWorker = file_get_contents($root . '/znews/sw-root.js');
new_tab_balance_expect(
    is_string($dashboard)
    && is_string($app)
    && is_string($index)
    && is_string($bootstrap)
    && is_string($embeddedWorker)
    && is_string($standaloneWorker),
    'Required source files could not be read.'
);

new_tab_balance_expect(
    preg_match('/zpay-service-btn-znews[^>]+href="\/user\/znews"[^>]+target="_blank"[^>]+rel="noopener"/', $dashboard) === 1,
    'Dashboard Z Sky 24 launcher must open safely in a new tab.'
);
new_tab_balance_expect(str_contains($app, 'async function loadMiniBalance()'), 'Feed mini-balance loader is missing.');
new_tab_balance_expect(str_contains($app, 'const summary = await api.balanceSummary()'), 'Feed mini-balance does not use the authenticated balance summary.');
new_tab_balance_expect(str_contains($app, 'els.miniBalance.textContent = formatBdtMicros(state.balanceMicros)'), 'Feed mini-balance is not rendered from the server balance.');
new_tab_balance_expect(str_contains($app, "window.addEventListener('znews:auth-ready'"), 'Authenticated feed boot does not observe verified session readiness.');
new_tab_balance_expect(str_contains($app, "if (hasVerifiedSession() && state.route !== 'balance') void loadMiniBalance();"), 'Verified session does not refresh mini-balance after first paint.');
new_tab_balance_expect(str_contains($index, 'znews-bootstrap.js?v=23'), 'Feed document does not bypass the stale bootstrap cache.');
new_tab_balance_expect(str_contains($index, 'znews.js?v=21'), 'Document does not bypass the stale feed app cache.');
foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    new_tab_balance_expect(str_contains($worker, 'znews-bootstrap.js?v=23'), 'A PWA shell is missing the refreshed bootstrap URL.');
    new_tab_balance_expect(str_contains($worker, 'znews.js?v=21'), 'A PWA shell is missing the refreshed feed app URL.');
}

echo "Z Sky 24 new-tab and balance sync tests passed ({$assertions} assertions).\n";
