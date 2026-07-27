<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$pageCss = (string)file_get_contents($root . '/api/user/assets/pages/dashboard-page.css');
$pageJs = (string)file_get_contents($root . '/api/user/assets/pages/dashboard-page.js');
$shellJs = (string)file_get_contents($root . '/api/user/assets/user-shell.js');
$bottomNav = (string)file_get_contents($root . '/api/user/includes/bottom-nav.php');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$tests = 0;

function dashboard_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

dashboard_expect(
    str_contains($dashboard, '$zpayMobileAppKey')
    && str_contains($dashboard, 'zpay_dash_dashboard_payload($auth)'),
    'Android dashboard API compatibility branch is missing'
);
dashboard_expect(
    substr_count($dashboard, '<section') === 1
    && str_contains($dashboard, 'id="overviewSection"')
    && !str_contains($dashboard, 'transferSection')
    && !str_contains($dashboard, 'addMoneySection'),
    'Dashboard browser page is not isolated'
);
dashboard_expect(
    str_contains($dashboard, 'class="dashboard-hero-topbar"')
    && str_contains($dashboard, 'id="openSidebarBtn"')
    && str_contains($dashboard, 'id="dashboardHeroTitle"')
    && str_contains($dashboard, 'href="/user/notifications"'),
    'Android-style dashboard hero controls are incomplete'
);
dashboard_expect(
    substr_count($dashboard, 'id="heroBalance"') === 1
    && substr_count($dashboard, 'id="heroHold"') === 1
    && !str_contains($dashboard, 'Account Summary'),
    'Dashboard balance is duplicated or legacy summary remains'
);
dashboard_expect(
    str_contains($dashboard, 'id="zpayQuickActions"')
    && str_contains($dashboard, '<h2 class="zpay-quick-title">Recommended</h2>'),
    'Dashboard Recommended block is missing'
);

$services = [
    'Add Money' => '/user/add-money',
    'Transfer' => '/user/transfer',
    'Top-Up' => '/user/topup',
    'bKash' => '/user/bkash',
    'Nagad' => '/user/nagad',
    'Bundle' => '/user/bundle',
    'Contact Us' => '/user/contact-us',
];
foreach ($services as $label => $href) {
    dashboard_expect(
        str_contains($dashboard, 'href="' . $href . '"')
        && str_contains($dashboard, '<span class="zpay-service-name">' . $label . '</span>'),
        "Dashboard service {$label} is missing or not a normal link"
    );
}
dashboard_expect(
    !str_contains($dashboard, 'data-open-section')
    && !str_contains($dashboard, 'data-page-section')
    && !str_contains($dashboard, 'openSection('),
    'Dashboard still uses SPA section switching'
);
dashboard_expect(
    str_contains($dashboard, "'bootstrap_action' => 'dashboard_bootstrap'")
    && str_contains($dashboard, "'summary_only' => '1'")
    && str_contains($proxy, "'history_complete' => !\$summaryOnly"),
    'Dashboard does not reuse the lightweight bootstrap contract'
);
dashboard_expect(
    str_contains($pageJs, 'shell.state.bootstrapData')
    && !str_contains($pageJs, "shell.get('dashboard_bootstrap'")
    && !str_contains($pageJs, 'transfer_create')
    && !str_contains($pageJs, 'add_money_submit'),
    'Dashboard page makes a duplicate bootstrap or loads unrelated features'
);
dashboard_expect(
    str_contains($pageJs, "pricingCountry === 'MY' || currency === 'MYR'")
    && str_contains($pageJs, ": 'Not applicable'"),
    'Dashboard rate visibility is not pricing-country/wallet aware'
);
dashboard_expect(
    str_contains($pageCss, '.user-dashboard-page .hero-card')
    && str_contains($pageCss, '.user-dashboard-page .dashboard-orb')
    && str_contains($pageCss, 'grid-template-columns: repeat(3, minmax(0, 1fr))')
    && str_contains($pageCss, '@media (max-width: 359px)'),
    'Dashboard responsive hero/service layout is incomplete'
);
dashboard_expect(
    str_contains($dashboard, 'class="page-section dashboard-scroll-body active"')
    && str_contains($pageCss, 'body.user-dashboard-page')
    && str_contains($pageCss, 'height: 100dvh')
    && str_contains($pageCss, '.user-dashboard-page #overviewSection')
    && str_contains($pageCss, 'overflow-y: auto'),
    'Dashboard fixed hero and body-only scroll architecture is incomplete'
);
dashboard_expect(
    str_contains($pageCss, 'white-space: nowrap')
    && str_contains($pageCss, '.user-dashboard-page .hero-balance.is-long')
    && str_contains($pageJs, "balanceLine?.classList.toggle('is-long'")
    && str_contains($pageCss, 'height: 104px'),
    'Dashboard balance fitting or compact service-card treatment is incomplete'
);
dashboard_expect(
    str_contains($bottomNav, "['dashboard', '/user/dashboard'")
    && str_contains($bottomNav, "['add-money', '/user/add-money'")
    && str_contains($bottomNav, "['transfer', '/user/transfer'")
    && str_contains($bottomNav, "['history', '/user/history'")
    && str_contains($bottomNav, "['profile', '/user/profile'"),
    'Shared bottom navigation order is incomplete'
);
dashboard_expect(
    str_contains($shellJs, "window.USER_BOOTSTRAP_ACTION || 'me'")
    && str_contains($shellJs, 'state.bootstrapData = data')
    && str_contains($shellJs, 'loadUnread();'),
    'Shared dashboard bootstrap or notification badge wiring is missing'
);

echo "User Dashboard UI tests passed ({$tests} assertions).\n";
