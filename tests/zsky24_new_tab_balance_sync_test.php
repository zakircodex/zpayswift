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
$api = file_get_contents($root . '/znews/assets/znews-api.js');
$weekly = file_get_contents($root . '/znews/assets/znews-weekly-review.js');
$index = file_get_contents($root . '/znews/index.html');
$bootstrap = file_get_contents($root . '/znews/assets/znews-bootstrap.js');
$embeddedWorker = file_get_contents($root . '/znews/sw.js');
$standaloneWorker = file_get_contents($root . '/znews/sw-root.js');
new_tab_balance_expect(
    is_string($dashboard)
    && is_string($app)
    && is_string($api)
    && is_string($weekly)
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
new_tab_balance_expect(str_contains($api, 'weeklyReviews(cursor'), 'Authenticated weekly report client is missing.');
new_tab_balance_expect(str_contains($weekly, 'znews:weekly-performance-open'), 'Weekly report does not open after verified navigation.');
new_tab_balance_expect(str_contains($index, 'data-route="performance"'), 'Weekly performance navigation is missing.');
new_tab_balance_expect(str_contains($app, "window.addEventListener('znews:auth-ready'"), 'Authenticated feed boot does not observe verified session readiness.');
new_tab_balance_expect(!str_contains($app, "void loadMiniBalance();"), 'Verified session still triggers the retired mini-balance request.');
new_tab_balance_expect(str_contains($index, 'znews-bootstrap.js?v=36'), 'Feed document does not bypass the stale bootstrap cache.');
new_tab_balance_expect(str_contains($index, 'znews.js?v=29'), 'Document does not bypass the stale feed app cache.');
foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    new_tab_balance_expect(str_contains($worker, 'znews-bootstrap.js?v=36'), 'A PWA shell is missing the refreshed bootstrap URL.');
    new_tab_balance_expect(str_contains($worker, 'znews.js?v=29'), 'A PWA shell is missing the refreshed feed app URL.');
}

echo "Z Sky 24 new-tab and weekly performance tests passed ({$assertions} assertions).\n";
