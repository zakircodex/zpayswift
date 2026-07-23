<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$css = (string)file_get_contents($root . '/api/user/assets/user-app.css');
$dashboardJs = (string)file_get_contents($root . '/api/user/assets/dashboard.js');
$appJs = (string)file_get_contents($root . '/api/user/assets/user-app.js');
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

function dashboard_fragment(string $source, string $start, string $end): string
{
    $startAt = strpos($source, $start);
    if ($startAt === false) {
        return '';
    }
    $endAt = strpos($source, $end, $startAt);
    if ($endAt === false) {
        return '';
    }
    return substr($source, $startAt, $endAt - $startAt);
}

$hero = dashboard_fragment($dashboard, '<div class="hero-card"', '<div class="android-tagline"');
$fixedStack = dashboard_fragment($dashboard, '<div class="dashboard-fixed-stack">', '<section id="overviewSection"');
$overview = dashboard_fragment($dashboard, '<section id="overviewSection"', '<section id="topupSection"');
$bottomNav = dashboard_fragment($dashboard, '<div class="bottom-nav">', '<script>');

dashboard_expect($hero !== '', 'Dashboard hero markup is missing');
dashboard_expect(
    str_contains($fixedStack, 'class="hero-card"')
    && str_contains($fixedStack, 'class="android-tagline"')
    && substr_count($fixedStack, 'class="android-tagline-item"') === 2,
    'Hero and marquee tagline are not grouped in the fixed Dashboard stack'
);
dashboard_expect(
    str_contains($hero, 'class="dashboard-hero-topbar"')
    && str_contains($hero, 'id="heroMenuButton"')
    && str_contains($hero, 'id="dashboardHeroTitle"')
    && str_contains($hero, 'id="heroNotificationButton"'),
    'Hero does not contain the Android-style menu, title and notification controls'
);
dashboard_expect(substr_count($dashboard, 'id="heroBalance"') === 1, 'Available balance is rendered more than once');
dashboard_expect(substr_count($dashboard, 'id="heroHold"') === 1, 'Hold balance is rendered more than once');
dashboard_expect(!str_contains($overview, 'Account Summary'), 'Dashboard still renders the account summary card');
dashboard_expect(!str_contains($hero, 'heroStatusText'), 'Dashboard still renders the account status badge');
dashboard_expect(
    str_contains($overview, 'id="zpayQuickActions"')
    && str_contains($overview, '<h2 class="zpay-quick-title">Recommended</h2>'),
    'Dashboard Recommended services block is missing'
);

$servicePositions = [];
foreach (['Add Money', 'Transfer', 'Top-Up', 'bKash', 'Nagad', 'Bundle', 'Shopping', 'Contact Us', 'Info'] as $service) {
    $servicePositions[] = strpos($overview, '<span class="zpay-service-name">' . $service . '</span>');
}
dashboard_expect(
    !in_array(false, $servicePositions, true) && $servicePositions === array_values(array_unique($servicePositions)),
    'A required Dashboard service is missing or duplicated'
);
$sortedServicePositions = $servicePositions;
sort($sortedServicePositions);
dashboard_expect($servicePositions === $sortedServicePositions, 'Recommended services are not in Android order');

$bottomOrder = [
    'data-page-section="overviewSection"',
    'data-page-section="addMoneySection"',
    'data-page-section="transferSection"',
    'data-page-section="historySection"',
    'data-page-section="profileSection"',
];
$bottomPositions = array_map(static fn(string $needle) => strpos($bottomNav, $needle), $bottomOrder);
dashboard_expect(!in_array(false, $bottomPositions, true), 'Android-style bottom navigation destination is missing');
$sortedBottomPositions = $bottomPositions;
sort($sortedBottomPositions);
dashboard_expect($bottomPositions === $sortedBottomPositions, 'Bottom navigation is not in Android order');
dashboard_expect(!str_contains($bottomNav, '>Services<') && !str_contains($bottomNav, '>Support<'), 'Old Services/Support bottom navigation remains');

dashboard_expect(
    str_contains($css, "body.user-authenticated[data-active-section='overviewSection'] .mobile-header")
    && str_contains($css, "body.user-authenticated[data-active-section='overviewSection'] .dashboard-fixed-stack")
    && str_contains($css, "body.user-authenticated[data-active-section='overviewSection'] .hero-card")
    && str_contains($css, 'position: sticky')
    && str_contains($css, 'animation: zpayDashboardTagline 16s linear infinite')
    && str_contains($css, '@keyframes zpayDashboardTagline')
    && str_contains($css, "grid-template-columns: repeat(3, minmax(0, 168px))"),
    'Dashboard-only responsive layout rules are missing'
);
dashboard_expect(
    str_contains($css, "body.user-authenticated[data-active-section='overviewSection'] .bottom-label")
    && str_contains($css, 'white-space: nowrap'),
    'Dashboard navigation labels can wrap'
);
dashboard_expect(
    str_contains($dashboardJs, "el('heroMenuButton')?.addEventListener('click', openSidebar)")
    && str_contains($appJs, "'heroNotificationBadge'")
    && str_contains($appJs, "$('heroNotificationButton')?.addEventListener('click', openNotifications)"),
    'Hero controls are not wired to existing menu/notification behavior'
);
dashboard_expect(
    str_contains($dashboardJs, "pricingCountry === 'MY'")
    && str_contains($dashboardJs, ": 'Not applicable'"),
    'Dashboard rate visibility is not pricing-country aware'
);
dashboard_expect(
    str_contains($appJs, "event.target.closest('[data-dashboard-action]')")
    && str_contains($appJs, 'Shopping is coming soon.')
    && str_contains($appJs, "'Z-Pay Swift'"),
    'Shopping and Info Dashboard actions are not wired to safe local UI behavior'
);

echo "User Dashboard UI tests passed ({$tests} assertions).\n";
