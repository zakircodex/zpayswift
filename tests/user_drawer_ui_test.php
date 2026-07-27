<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$drawer = (string)file_get_contents($root . '/api/user/includes/drawer.php');
$header = (string)file_get_contents($root . '/api/user/includes/header.php');
$bottomNav = (string)file_get_contents($root . '/api/user/includes/bottom-nav.php');
$css = (string)file_get_contents($root . '/api/user/assets/user-shell.css');
$js = (string)file_get_contents($root . '/api/user/assets/user-shell.js');
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

drawer_expect(
    str_contains($drawer, 'class="sidebar user-drawer"')
    && str_contains($drawer, 'role="dialog"')
    && str_contains($drawer, 'aria-modal="true"')
    && str_contains($drawer, 'aria-hidden="true"')
    && str_contains($drawer, 'inert'),
    'Shared drawer accessibility shell is incomplete'
);
drawer_expect(
    str_contains($drawer, '$needsSharedDrawerTrigger')
    && str_contains($drawer, 'id="openSidebarBtn"')
    && str_contains($drawer, 'class="icon-btn user-drawer-page-trigger hidden"'),
    'Custom-header pages do not receive a shared drawer trigger'
);
drawer_expect(
    str_contains($drawer, 'class="drawer-profile-fixed"')
    && substr_count($drawer, 'class="drawer-profile-orb') === 3
    && str_contains($drawer, 'id="drawerAvatarImage"')
    && str_contains($drawer, 'id="drawerUserName"')
    && str_contains($drawer, 'id="drawerUserPhone"')
    && str_contains($drawer, 'id="drawerRoleChip"')
    && str_contains($drawer, 'id="drawerStatusChip"')
    && str_contains($drawer, 'id="drawerCountryCurrency"'),
    'Fixed drawer profile card is incomplete'
);
drawer_expect(
    strpos($drawer, 'class="drawer-profile-fixed"') < strpos($drawer, 'class="drawer-menu-scroll"')
    && substr_count($drawer, 'class="drawer-menu-scroll"') === 1,
    'Drawer profile/menu scroll architecture is incorrect'
);
foreach (['ACCOUNT', 'SETTINGS', 'SUPPORT', 'ABOUT'] as $section) {
    drawer_expect(str_contains($drawer, 'class="drawer-section-label">' . $section), "missing drawer section {$section}");
}
foreach ([
    'My Profile' => '/user/profile',
    'Security' => '/user/profile#security',
    'Notifications' => '/user/notifications',
    'Contact Us' => '/user/contact-us',
    'Support Requests' => '/user/support',
    'Privacy Policy' => '/privacy',
    'Terms &amp; Conditions' => '/terms',
] as $label => $route) {
    drawer_expect(
        str_contains($drawer, $label) && str_contains($drawer, 'href="' . $route . '"'),
        "missing normal drawer link for {$label}"
    );
}
drawer_expect(
    str_contains($drawer, '<strong>App Version</strong>')
    && !str_contains($drawer, 'About Z-Pay Swift')
    && !str_contains($drawer, '<strong>Web Version</strong>'),
    'Android drawer App Version mapping is incorrect'
);
foreach (['profile', 'security', 'notifications', 'contact-us', 'support'] as $pageKey) {
    drawer_expect(
        str_contains($drawer, 'data-drawer-page="' . $pageKey . '"'),
        "missing drawer active-state key {$pageKey}"
    );
}
drawer_expect(
    str_contains($header, 'id="openSidebarBtn"')
    && str_contains($header, 'aria-controls="sidebar"')
    && str_contains($header, 'aria-expanded="false"'),
    'Shared header drawer trigger is inaccessible'
);
drawer_expect(
    str_contains($css, '.user-drawer')
    && str_contains($css, 'height: 100dvh')
    && str_contains($css, 'overflow-y: auto')
    && str_contains($css, 'scrollbar-width: none')
    && str_contains($css, 'min-height: 0')
    && str_contains($css, '.user-drawer-item.active')
    && str_contains($css, 'body.user-drawer-open')
    && str_contains($css, '.sidebar-overlay.show'),
    'Drawer fixed profile/scroll body CSS is incomplete'
);
drawer_expect(
    str_contains($js, 'function openDrawer(event)')
    && str_contains($js, 'function closeDrawer(options = {})')
    && str_contains($js, 'state.drawerOpener')
    && str_contains($js, 'zpayUserDrawer')
    && str_contains($js, 'event.stopImmediatePropagation()')
    && str_contains($js, "event.key === 'Escape'")
    && str_contains($js, "event.key !== 'Tab'")
    && str_contains($js, 'document.body.classList.toggle(\'user-drawer-open\''),
    'Drawer open/close/focus handling is incomplete'
);
drawer_expect(
    str_contains($js, 'function syncDrawerActiveState()')
    && str_contains($js, 'function installDrawerPageTrigger()')
    && str_contains($js, 'backControl.replaceWith(trigger)')
    && str_contains($js, 'aria-current')
    && str_contains($js, 'function navigateFromDrawer(event)')
    && str_contains($js, 'state.drawerPendingLink = link')
    && str_contains($js, 'pendingLink.click()'),
    'Separate-page drawer navigation/active-state handling is incomplete'
);
drawer_expect(
    str_contains($js, 'displayCountry(pricingCountry, currency)')
    && !str_contains($js, 'user.pricing_country || user.country')
    && !str_contains($js, 'phone_country'),
    'Drawer country/currency rendering is not pricing-country safe'
);
drawer_expect(
    str_contains($js, "$('drawerLogoutBtn')?.addEventListener('click', (event) => {")
    && str_contains($bottomNav, 'id="userLogoutDialog"')
    && str_contains($js, 'closeLogoutDialog')
    && str_contains($js, "post('logout'")
    && !str_contains($drawer . $css . $js, 'APP_KEY')
    && !str_contains($drawer . $css . $js, 'WORKER_KEY'),
    'Secure logout binding is missing or drawer exposes secret-like keys'
);
drawer_expect(
    !str_contains($drawer, 'data-page-section')
    && !str_contains($drawer, 'data-open-section')
    && !str_contains($js, 'openSection('),
    'Drawer still uses SPA section switching'
);

echo "User drawer UI tests passed ({$tests} assertions).\n";
