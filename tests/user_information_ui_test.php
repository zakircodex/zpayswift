<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/information.php');
$css = (string)file_get_contents($root . '/api/user/assets/pages/information-page.css');
$js = (string)file_get_contents($root . '/api/user/assets/pages/information-page.js');
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/user/assets/pages/dashboard-page.js');
$drawer = (string)file_get_contents($root . '/api/user/includes/drawer.php');
$bottomNav = (string)file_get_contents($root . '/api/user/includes/bottom-nav.php');
$htaccess = (string)file_get_contents($root . '/.htaccess');
$assertions = 0;

function information_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

information_expect(
    str_contains($page, "'key' => 'information'")
    && str_contains($page, "'show_header' => false")
    && str_contains($page, "'show_drawer' => false")
    && str_contains($page, "'show_bottom_nav' => true")
    && str_contains($page, "'active_nav' => ''"),
    'Information page shell configuration is incorrect'
);

information_expect(
    str_contains($htaccess, 'RewriteRule ^user/information/?$ /api/user/information.php')
    && str_contains($htaccess, 'RewriteRule ^user/info/?$ /api/user/information.php'),
    'Information canonical route or alias is missing'
);

information_expect(
    str_contains($dashboard, 'href="/user/information"')
    && !str_contains($dashboard, 'data-dashboard-action="info"')
    && !str_contains($dashboardJs, 'Z-Pay Swift Web User Panel'),
    'Dashboard Info action does not use the separate Information page'
);

foreach ([
    'Add Money' => '/user/add-money',
    'Z-Pay Transfer' => '/user/transfer',
    'Mobile Top-Up' => '/user/topup',
    'Bundle' => '/user/bundle',
    'bKash' => '/user/bkash',
    'Nagad' => '/user/nagad',
    'Request Tracking' => '/user/history',
    'Support' => '/user/contact-us',
] as $label => $href) {
    information_expect(
        str_contains($page, "'title' => '{$label}'")
        && str_contains($page, "'href' => '{$href}'"),
        "Information service mapping for {$label} is incorrect"
    );
}

information_expect(
    substr_count($page, 'class="information-step-row"') === 1
    && count(array_filter([
        str_contains($page, 'Choose a service'),
        str_contains($page, 'Verify securely with your transaction PIN'),
        str_contains($page, 'Submit and track your request'),
        str_contains($page, 'Receive status updates and notifications'),
    ])) === 4
    && !stripos($page, 'fingerprint'),
    'How It Works content is incomplete or advertises unsupported browser biometric verification'
);

information_expect(
    str_contains($page, 'Important Notice')
    && str_contains($page, 'Security &amp; Privacy')
    && str_contains($page, 'Never share PIN, OTP or password.')
    && str_contains($page, 'will never ask users to send PIN or password through chat.'),
    'Notice or security content is incomplete'
);

information_expect(
    str_contains($page, 'href="/user/contact-us"')
    && str_contains($page, 'href="/user/support"')
    && str_contains($page, 'href="/privacy"')
    && str_contains($page, 'href="/terms"'),
    'Support or policy routes are incorrect'
);

preg_match('/<small>Web ([0-9.]+)<\/small>/', $drawer, $drawerVersion);
information_expect(
    isset($drawerVersion[1])
    && str_contains($page, '<strong>Web ' . $drawerVersion[1] . '</strong>'),
    'Information page does not use the existing canonical Web version'
);

information_expect(
    str_contains($page, '/assets/brand/zpay-icon.png')
    && str_contains($page, 'Fast, secure, easy tracking.')
    && str_contains($page, 'Private wallet, mobile top-up and remittance support service.'),
    'Information intro card content is incomplete'
);

information_expect(
    str_contains($css, 'body.user-info-page')
    && str_contains($css, 'height: 100dvh')
    && str_contains($css, '.user-info-page .information-page-shell')
    && str_contains($css, 'grid-template-rows: auto minmax(0, 1fr)')
    && str_contains($css, '.user-info-page .information-scroll-body')
    && str_contains($css, 'overflow-y: auto')
    && !str_contains($css, '.user-info-page .bottom-nav'),
    'Information fixed header/body-only scroll or shared navigation isolation is incorrect'
);

information_expect(
    !preg_match('/^\s*\.(?:card|button|modal|input|menu)\b/m', $css)
    && !str_contains($page, 'data-open-section')
    && !str_contains($page, 'openSection('),
    'Information page contains unscoped generic CSS or SPA navigation'
);

information_expect(
    str_contains($js, "referrer.origin === window.location.origin")
    && str_contains($js, "referrer.pathname.startsWith('/user/')")
    && str_contains($js, 'window.history.back()')
    && str_contains($page, 'href="/user/dashboard"'),
    'Information Back handling is not same-origin safe'
);

information_expect(
    str_contains($bottomNav, "['dashboard', '/user/dashboard'")
    && str_contains($bottomNav, "['add-money', '/user/add-money'")
    && str_contains($bottomNav, "['transfer', '/user/transfer'")
    && str_contains($bottomNav, "['history', '/user/history'")
    && str_contains($bottomNav, "['profile', '/user/profile'"),
    'Information page cannot reuse the canonical bottom navigation'
);

echo "User Information UI tests passed ({$assertions} assertions).\n";
