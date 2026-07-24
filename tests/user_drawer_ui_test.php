<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$dashboardCss = (string)file_get_contents($root . '/api/user/assets/dashboard.css');
$appCss = (string)file_get_contents($root . '/api/user/assets/user-app.css');
$dashboardJs = (string)file_get_contents($root . '/api/user/assets/dashboard.js');
$appJs = (string)file_get_contents($root . '/api/user/assets/user-app.js');
$tests = 0;

function drawer_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function drawer_fragment(string $source, string $start, string $end): string
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

$drawer = drawer_fragment($dashboard, '<aside id="sidebar"', '<main class="main-panel">');

drawer_expect($drawer !== '', 'User drawer markup is missing');
drawer_expect(
    str_contains($drawer, 'class="sidebar user-drawer"')
    && str_contains($drawer, 'role="dialog"')
    && str_contains($drawer, 'aria-modal="true"')
    && str_contains($drawer, 'aria-hidden="true"')
    && str_contains($drawer, 'inert'),
    'Drawer accessibility shell is not initialized safely'
);
drawer_expect(
    str_contains($drawer, 'class="drawer-profile-fixed"')
    && str_contains($drawer, 'id="drawerAvatarImage"')
    && str_contains($drawer, 'id="drawerUserName"')
    && str_contains($drawer, 'id="drawerUserPhone"')
    && str_contains($drawer, 'id="drawerRoleChip"')
    && str_contains($drawer, 'id="drawerStatusChip"')
    && str_contains($drawer, 'id="drawerCountryCurrency"'),
    'Fixed drawer profile card is incomplete'
);
drawer_expect(
    strpos($drawer, 'class="drawer-profile-fixed"') < strpos($drawer, 'class="drawer-menu-scroll"'),
    'Profile card is inside or after the scrollable menu body'
);
drawer_expect(
    str_contains($drawer, 'class="drawer-menu-scroll"')
    && substr_count($drawer, 'class="drawer-menu-scroll"') === 1,
    'Drawer must have exactly one scrollable menu body'
);

foreach (['ACCOUNT', 'SETTINGS', 'SUPPORT', 'ABOUT'] as $section) {
    drawer_expect(str_contains($drawer, 'class="drawer-section-label">' . $section), "missing drawer section {$section}");
}

$menuRoutes = [
    'My Profile' => 'data-page-section="profileSection"',
    'Security' => 'data-profile-action="security"',
    'Notifications' => 'data-page-section="notificationsSection"',
    'Contact Us' => 'data-support-tab="new"',
    'Support Requests' => 'data-support-tab="list"',
    'Privacy Policy' => 'href="/privacy"',
    'Terms &amp; Conditions' => 'href="/terms"',
    'About Z-Pay Swift' => 'data-dashboard-action="info"',
    'Logout' => 'id="drawerLogoutBtn"',
];

foreach ($menuRoutes as $label => $needle) {
    drawer_expect(str_contains($drawer, $label) && str_contains($drawer, $needle), "missing or wrong drawer route for {$label}");
}

drawer_expect(
    !str_contains($drawer, 'id="sideMeName"')
    && !str_contains($drawer, 'id="sideMeRole"')
    && !str_contains($drawer, 'id="sideMeStatus"')
    && !str_contains($drawer, 'class="sidebar-brand"')
    && !str_contains($drawer, 'class="sidebar-title"'),
    'Legacy drawer profile/account summary is still rendered'
);

drawer_expect(
    str_contains($dashboard, 'id="openSidebarBtn"')
    && str_contains($dashboard, 'aria-controls="sidebar"')
    && str_contains($dashboard, 'aria-expanded="false"')
    && str_contains($dashboard, 'id="heroMenuButton"'),
    'Drawer open buttons do not expose aria-expanded/controls'
);
drawer_expect(
    str_contains($dashboard, '/api/user/assets/user-app.css?v=16')
    && str_contains($dashboard, '/api/user/assets/dashboard.js?v=28')
    && str_contains($dashboard, '/api/user/assets/user-app.js?v=8'),
    'Drawer asset versions were not bumped after the alignment CSS/JS change'
);

drawer_expect(
    str_contains($appCss, '.user-drawer.sidebar')
    && str_contains($appCss, 'height: 100dvh')
    && str_contains($appCss, 'display: flex')
    && str_contains($appCss, '.drawer-profile-fixed')
    && str_contains($appCss, 'flex: 0 0 auto')
    && str_contains($appCss, '.drawer-menu-scroll')
    && str_contains($appCss, 'overflow-y: auto')
    && str_contains($appCss, 'min-height: 0')
    && str_contains($appCss, 'body.user-drawer-open'),
    'Drawer fixed profile and scroll body CSS is incomplete'
);
drawer_expect(
    str_contains($appCss, 'width: min(86vw, 336px)')
    && str_contains($appCss, 'border-radius: 0 30px 30px 0')
    && str_contains($appCss, 'z-index: 170')
    && str_contains($appCss, 'z-index: 160')
    && str_contains($appCss, 'backdrop-filter: none')
    && str_contains($appCss, 'rgba(50, 230, 134')
    && str_contains($appCss, '.sidebar-overlay.show')
    && str_contains($appCss, 'transform: translateX(0)'),
    'Android-like drawer width, overlay, accent or animation CSS is missing'
);
drawer_expect(
    str_contains($appCss, '.user-drawer .drawer-avatar > img#drawerAvatarImage')
    && str_contains($appCss, 'width: 100% !important')
    && str_contains($appCss, 'max-width: 100% !important')
    && str_contains($appCss, 'object-fit: cover')
    && str_contains($appCss, '.user-drawer .drawer-menu-copy')
    && str_contains($appCss, 'flex-direction: column')
    && str_contains($appCss, 'white-space: normal'),
    'Drawer avatar and menu text alignment hardening is missing'
);
drawer_expect(
    str_contains($appCss, '.drawer-version .drawer-menu-copy')
    && str_contains($appCss, 'text-align: left')
    && str_contains($appCss, '.logout-confirm-card')
    && str_contains($appCss, 'border-radius: 30px')
    && str_contains($appCss, '.logout-confirm-icon')
    && str_contains($appCss, '.logout-confirm-actions')
    && str_contains($appCss, 'flex-direction: row')
    && str_contains($appCss, 'border-radius: 18px')
    && str_contains($appCss, '.drawer-logout .drawer-menu-copy')
    && str_contains($appCss, 'text-align: left'),
    'Web version alignment or logout confirmation styling is missing'
);

drawer_expect(
    str_contains($dashboardJs, 'function renderDrawerProfile()')
    && str_contains($dashboardJs, 'function maskDrawerPhone(')
    && str_contains($dashboardJs, 'drawerSafeImage(')
    && str_contains($dashboardJs, 'window.renderUserDrawerProfile = renderDrawerProfile')
    && str_contains($appJs, 'window.renderUserDrawerProfile()'),
    'Drawer profile data binding is not wired to authenticated profile state'
);
drawer_expect(
    str_contains($dashboardJs, 'document.body.classList.add(\'user-drawer-open\')')
    && str_contains($dashboardJs, 'document.body.classList.remove(\'user-drawer-open\')')
    && str_contains($dashboardJs, 'setDrawerOpenerExpanded(true)')
    && str_contains($dashboardJs, 'setDrawerOpenerExpanded(false)')
    && str_contains($dashboardJs, 'sidebar.toggleAttribute(\'inert\''),
    'Drawer open/close accessibility state is incomplete'
);
drawer_expect(
    str_contains($dashboardJs, 'function handleSidebarKeydown(')
    && str_contains($dashboardJs, "e.key === 'Escape'")
    && str_contains($dashboardJs, "e.key !== 'Tab'")
    && str_contains($dashboardJs, 'drawerFocusableControls()')
    && str_contains($dashboardJs, 'lastSidebarOpener.focus'),
    'Drawer keyboard close/focus trap behavior is missing'
);
drawer_expect(
    str_contains($dashboardJs, "'.side-btn[data-page-section], .drawer-profile-fixed[data-page-section]'")
    && str_contains($dashboardJs, 'focusProfileSecurityAction()')
    && str_contains($dashboardJs, 'setSupportDrawerTab(btn.dataset.supportTab')
    && str_contains($dashboardJs, 'function ensureLogoutConfirmModal()')
    && str_contains($dashboardJs, 'function requestLogoutConfirmation()')
    && !str_contains($dashboardJs, 'closeLogoutConfirmModalBtn')
    && str_contains($dashboardJs, "el('drawerLogoutBtn')?.addEventListener('click', requestLogoutConfirmation)")
    && str_contains($dashboardJs, "el('confirmLogoutBtn')?.addEventListener('click'")
    && str_contains($dashboardJs, 'doLogout();')
    && str_contains($dashboardJs, "proxyPost('logout'"),
    'Drawer route binding or confirm-before-secure-logout flow is not preserved'
);
drawer_expect(
    str_contains($dashboardJs, "const displayCountry = drawerCountryLabel(pricingCountry || (currency === 'MYR' ? 'MY' : 'BD'))")
    && str_contains($dashboardJs, "displayCountry + ' | ' + currency"),
    'Drawer country fallback is not derived safely from wallet currency'
);
drawer_expect(
    !str_contains($dashboardJs, 'console.log(')
    && !str_contains($dashboardCss . $appCss . $dashboardJs . $appJs, 'APP_KEY')
    && !str_contains($dashboardCss . $appCss . $dashboardJs . $appJs, 'WORKER_KEY'),
    'Drawer assets expose debug logs or secret-like keys'
);

echo "User drawer UI tests passed ({$tests} assertions).\n";
