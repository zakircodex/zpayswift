<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function architecture_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function architecture_read(string $path): string
{
    $source = file_get_contents($path);
    architecture_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$routes = [
    'dashboard' => 'dashboard.php',
    'add-money' => 'add-money.php',
    'transfer' => 'transfer.php',
    'topup' => 'topup.php',
    'bundle' => 'bundle.php',
    'bkash' => 'bkash.php',
    'nagad' => 'nagad.php',
    'history' => 'history.php',
    'services' => 'services.php',
    'notifications' => 'notifications.php',
    'profile' => 'profile.php',
    'contact-us' => 'contact-us.php',
    'support' => 'support.php',
];

$htaccess = architecture_read($root . '/.htaccess');
$dashboard = architecture_read($root . '/api/user/dashboard.php');
$shell = architecture_read($root . '/api/user/assets/user-shell.js');
$bootstrap = architecture_read($root . '/api/user/includes/page-bootstrap.php');
$commonScripts = architecture_read($root . '/api/user/includes/common-scripts.php');

architecture_expect(
    str_contains($htaccess, 'RewriteRule ^user/?$ /api/user/index.php'),
    '/user entry does not use the isolated login page'
);

foreach ($routes as $route => $file) {
    $path = $root . '/api/user/' . $file;
    architecture_expect(is_file($path), "missing page {$file}");
    architecture_expect(
        preg_match(
            '#RewriteRule \^user/' . preg_quote($route, '#') . '/\?\$ /api/user/' . preg_quote($file, '#') . '#i',
            $htaccess
        ) === 1,
        "route /user/{$route} is not mapped to {$file}"
    );

    $source = architecture_read($path);
    if ($route === 'contact-us') {
        architecture_expect(
            str_contains($source, "header('Cache-Control: no-store')")
            && str_contains($source, "header('Location: /user/support', true, 302)"),
            'contact-us.php is not a safe canonical alias for the Support experience'
        );
        continue;
    }
    architecture_expect(
        str_contains($source, "require_once __DIR__ . '/includes/page-bootstrap.php'"),
        "{$file} does not use the shared authenticated bootstrap"
    );
    architecture_expect(
        preg_match("/'page_css'\s*=>\s*'([^']+)'/", $source, $cssMatch) === 1,
        "{$file} has no page-specific CSS"
    );
    architecture_expect(
        preg_match("/'page_js'\s*=>\s*'([^']+)'/", $source, $jsMatch) === 1,
        "{$file} has no page-specific JavaScript"
    );
    architecture_expect(
        is_file($root . '/api/user/assets/pages/' . $cssMatch[1]),
        "{$file} CSS asset is missing"
    );
    architecture_expect(
        is_file($root . '/api/user/assets/pages/' . $jsMatch[1]),
        "{$file} JavaScript asset is missing"
    );
    architecture_expect(
        !str_contains($source, 'data-open-section')
        && !str_contains($source, 'data-page-section')
        && !str_contains($source, 'openSection('),
        "{$file} still contains SPA cross-page navigation"
    );
}

foreach ([
    'z-pay-transfer' => 'transfer.php',
    'bundles' => 'bundle.php',
    'send-money' => 'bkash.php',
] as $alias => $file) {
    architecture_expect(
        preg_match(
            '#RewriteRule \^user/' . preg_quote($alias, '#') . '/\?\$ /api/user/' . preg_quote($file, '#') . '#i',
            $htaccess
        ) === 1,
        "legacy alias /user/{$alias} is missing"
    );
}

architecture_expect(
    substr_count($dashboard, '<section') === 1
    && str_contains($dashboard, 'id="overviewSection"')
    && !str_contains($dashboard, 'transferSection')
    && !str_contains($dashboard, 'addMoneySection')
    && !str_contains($dashboard, 'profileSection'),
    'dashboard.php still contains inactive feature sections'
);

architecture_expect(
    str_contains($dashboard, '$zpayMobileAppKey')
    && str_contains($dashboard, 'zpay_dash_dashboard_payload($auth)'),
    'Android dashboard API compatibility branch was removed'
);

architecture_expect(
    str_contains($bootstrap, "require_once __DIR__ . '/auth-guard.php'")
    && str_contains($bootstrap, 'user_page_require_auth();'),
    'shared page bootstrap does not enforce the existing session guard'
);

architecture_expect(
    str_contains($commonScripts, 'window.USER_BOOTSTRAP_ACTION')
    && str_contains($shell, "headers['X-CSRF-Token'] = state.csrf")
    && str_contains($shell, "window.location.replace(loginUrl)"),
    'shared shell does not preserve bootstrap, CSRF and session-expiry handling'
);

foreach (glob($root . '/api/user/assets/pages/*-page.js') ?: [] as $jsPath) {
    $source = architecture_read($jsPath);
    architecture_expect(
        !str_contains($source, 'window.openSection')
        && !preg_match('/\bfunction\s+openSection\s*\(/', $source),
        basename($jsPath) . ' still defines SPA cross-page routing'
    );
}

$dashboardJs = architecture_read($root . '/api/user/assets/pages/dashboard-page.js');
$transferJs = architecture_read($root . '/api/user/assets/pages/transfer-page.js');
$addMoneyJs = architecture_read($root . '/api/user/assets/pages/add-money-page.js');

architecture_expect(
    !str_contains($dashboardJs, 'transfer_create')
    && !str_contains($dashboardJs, 'add_money_submit')
    && !str_contains($dashboardJs, 'support_list'),
    'Dashboard page JavaScript contains unrelated feature actions'
);
architecture_expect(
    str_contains($transferJs, "'transfer_recipient'")
    && str_contains($transferJs, "'transfer_preview'")
    && str_contains($transferJs, "'transfer_create'")
    && !str_contains($transferJs, "'add_money_settings'"),
    'Transfer page API isolation is incorrect'
);
architecture_expect(
    str_contains($addMoneyJs, "'add_money_settings'")
    && str_contains($addMoneyJs, "'add_money_submit'")
    && !str_contains($addMoneyJs, "'transfer_create'"),
    'Add Money page API isolation is incorrect'
);

echo "User Panel page architecture tests passed ({$assertions} assertions).\n";
