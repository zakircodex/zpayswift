<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = file_get_contents($root . '/znews/assets/znews.js');
$bootstrap = file_get_contents($root . '/znews/assets/znews-bootstrap.js');
$index = file_get_contents($root . '/znews/index.html');
$embeddedWorker = file_get_contents($root . '/znews/sw.js');
$standaloneWorker = file_get_contents($root . '/znews/sw-root.js');

function back_guard_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

back_guard_expect(str_contains($app, 'function initializeAppHistory(route)'), 'The Z Sky 24 history boundary is missing.');
back_guard_expect(str_contains($app, 'znewsBoundary: true'), 'The dashboard exit boundary is not seeded.');
back_guard_expect(str_contains($app, 'history.pushState(appHistoryState(next)'), 'Internal views must create browser history entries.');
back_guard_expect(!str_contains($app, 'history.replaceState({ ...current, znewsView: next }'), 'Internal views must not replace their history entry.');
back_guard_expect(str_contains($app, "toast('Press Back again to return to Z-Pay.')"), 'The guarded exit notice is missing.');
back_guard_expect(str_contains($app, "button.classList.contains('composer-back')"), 'The composer Back button must use browser history.');
back_guard_expect(str_contains($bootstrap, 'znews.js?v=14'), 'The guarded app script is not activated.');
back_guard_expect(str_contains($index, 'znews-bootstrap.js?v=17'), 'The guarded bootstrap is not activated.');
back_guard_expect(str_contains($embeddedWorker, "zsky24-embedded-shell-v10"), 'The embedded cache namespace is stale.');
back_guard_expect(str_contains($standaloneWorker, "zsky24-standalone-shell-v10"), 'The standalone cache namespace is stale.');

echo "Z Sky 24 back navigation guard checks passed.\n";
