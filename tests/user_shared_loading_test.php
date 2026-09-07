<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$head = (string)file_get_contents($root . '/api/user/includes/head.php');
$bottom = (string)file_get_contents($root . '/api/user/includes/bottom-nav.php');
$shell = (string)file_get_contents($root . '/api/user/assets/user-shell.js');
$css = (string)file_get_contents($root . '/api/user/assets/user-shell.css');
$tests = 0;

function shared_loading_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

shared_loading_expect(
    str_contains($head, 'id="appView" inert aria-busy="true"')
    && str_contains($bottom, 'class="bottom-nav" aria-label="Primary navigation" inert'),
    'Initial authenticated content is not interaction-locked'
);
shared_loading_expect(
    str_contains($bottom, 'id="loadingWrap"')
    && str_contains($bottom, 'role="dialog"')
    && str_contains($bottom, 'aria-modal="true"')
    && !str_contains($bottom, "if (!empty(\$userPage['show_global_loader']))"),
    'Shared loading modal is not rendered on every authenticated page'
);
shared_loading_expect(
    str_contains($shell, 'busyHolds: new Map()')
    && str_contains($shell, 'function acquireBusy(')
    && str_contains($shell, 'state.busyHolds.delete(id)')
    && str_contains($shell, 'holdPageLoad: acquireBusy'),
    'Loading lifecycle is not reference-counted'
);
shared_loading_expect(
    str_contains($shell, 'app.inert = blocked')
    && str_contains($shell, 'bottomNav.inert = blocked')
    && str_contains($shell, "document.body.classList.toggle('user-page-loading', active)"),
    'Loading modal does not consistently block page actions'
);
shared_loading_expect(
    str_contains($css, '#loadingWrap.user-global-loading')
    && str_contains($css, 'backdrop-filter: blur(10px)')
    && str_contains($css, '#loadingWrap .user-global-loading-card'),
    'Shared loading modal styling is incomplete'
);

$pageScripts = [
    'add-money-page.js' => 'Loading add money...',
    'bundle-page.js' => 'Loading bundle offers...',
    'dashboard-page.js' => 'Loading dashboard...',
    'history-page.js' => 'Loading history...',
    'mfs-page.js' => 'holdPageLoad',
    'notifications-page.js' => 'Loading notifications...',
    'profile-page.js' => 'Loading profile...',
    'support-page.js' => 'Loading support...',
    'topup-page.js' => 'Loading mobile top-up...',
    'transfer-page.js' => 'Loading transfer...',
];
foreach ($pageScripts as $file => $needle) {
    $source = (string)file_get_contents($root . '/api/user/assets/pages/' . $file);
    shared_loading_expect(
        str_contains($source, 'releaseInitialLoad')
        && str_contains($source, 'holdPageLoad')
        && str_contains($source, $needle),
        "Initial loading hold is missing from {$file}"
    );
}

echo "User shared loading tests passed ({$tests} assertions).\n";
