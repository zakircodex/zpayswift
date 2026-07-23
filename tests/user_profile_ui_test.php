<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$css = (string)file_get_contents($root . '/api/user/assets/user-app.css');
$appJs = (string)file_get_contents($root . '/api/user/assets/user-app.js');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$profileUpdate = (string)file_get_contents($root . '/api/user/profile_update.php');
$tests = 0;

function profile_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function profile_fragment(string $source, string $start, string $end): string
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

$profile = profile_fragment($dashboard, '<section id="profileSection"', '<section id="supportSection"');

profile_expect($profile !== '', 'Profile section is missing');
profile_expect(
    str_contains($dashboard, '/api/user/assets/user-app.css?v=15')
    && str_contains($dashboard, '/api/user/assets/user-app.js?v=7'),
    'Profile CSS/JS cache versions were not bumped'
);
profile_expect(
    str_contains($profile, 'class="page-section profile-page-section"')
    && str_contains($profile, 'class="profile-page-shell"')
    && str_contains($profile, 'class="profile-fixed-hero"')
    && str_contains($profile, 'class="profile-scroll-body"'),
    'Profile page does not use the fixed-hero and scroll-body architecture'
);
profile_expect(
    str_contains($profile, 'id="profileBackButton"')
    && str_contains($profile, 'id="profilePageTitle"')
    && str_contains($profile, 'id="profileNotificationButton"')
    && str_contains($profile, 'id="profileNotificationBadge"'),
    'Profile hero toolbar is incomplete'
);
profile_expect(
    str_contains($profile, 'id="profileAvatarButton"')
    && str_contains($profile, 'id="profileAvatarImage"')
    && str_contains($profile, 'id="profileAvatarInitials"')
    && str_contains($profile, 'id="profileEditButton"')
    && str_contains($profile, 'id="profilePhotoInput"'),
    'Profile identity/photo/edit controls are incomplete'
);
profile_expect(
    str_contains($profile, '<h3>Security</h3>')
    && str_contains($profile, 'id="profileChangePasswordBtn"')
    && str_contains($profile, 'id="profileChangePinBtn"')
    && str_contains($profile, 'id="profileBiometricBtn"')
    && str_contains($profile, 'Android app only'),
    'Profile Security section does not match the required Android-style rows'
);
profile_expect(
    str_contains($profile, '<h3>Account &amp; App</h3>')
    && str_contains($profile, 'id="profileCopyUidBtn"')
    && str_contains($profile, 'id="profileCreatedAt"')
    && str_contains($profile, 'id="profileAppVersion"')
    && str_contains($profile, 'id="profileSessionStatus"'),
    'Profile Account & App section is incomplete'
);
profile_expect(
    str_contains($profile, 'profile-logout-card')
    && str_contains($profile, 'id="profileLogoutBtn"')
    && !str_contains($profile, 'Help &amp; Session'),
    'Profile logout section was not converted to Android style'
);
profile_expect(
    !str_contains($profile, 'Account Details')
    && !str_contains($profile, 'profilePhoneCountry')
    && !str_contains($profile, 'profilePricingCountry')
    && !str_contains($profile, 'profileWalletCurrency')
    && !str_contains($profile, 'profileLastLogin'),
    'Legacy profile account summary fields still render'
);
profile_expect(
    str_contains($css, "body.user-authenticated[data-active-section='profileSection'] .mobile-header")
    && str_contains($css, "body.user-authenticated[data-active-section='profileSection'] .dashboard-fixed-stack")
    && str_contains($css, "body.user-authenticated[data-active-section='profileSection'] .bottom-nav")
    && str_contains($css, '.profile-fixed-hero')
    && str_contains($css, 'flex: 0 0 auto')
    && str_contains($css, '.profile-scroll-body')
    && str_contains($css, 'overflow-y: auto'),
    'Profile fixed hero or independent scroll CSS is missing'
);
profile_expect(
    str_contains($css, '@media (max-width: 420px)')
    && str_contains($css, '@media (max-width: 340px)')
    && str_contains($css, 'overflow-wrap: anywhere'),
    'Profile responsive text-fit hardening is missing'
);
profile_expect(
    str_contains($appJs, 'function maskEmail(value)')
    && str_contains($appJs, 'function profileCountryLabel(value)')
    && str_contains($appJs, "displayCountry + ' | ' + currency")
    && str_contains($appJs, "maskEmail(profile.email)")
    && !str_contains($appJs, "$('profileEmail').textContent = profile.email"),
    'Profile masking/country rendering is not wired safely'
);
profile_expect(
    str_contains($appJs, "'profileNotificationBadge'")
    && str_contains($appJs, "$('profileNotificationButton')?.addEventListener('click', openNotificationsPage)")
    && str_contains($appJs, 'loadUnreadCount();')
    && str_contains($appJs, "document.querySelector('.profile-scroll-body')?.scrollTo"),
    'Profile notification badge/action or scroll reset is missing'
);
profile_expect(
    str_contains($proxy, "case 'profile_get':")
    && str_contains($proxy, "case 'profile_update':")
    && str_contains($proxy, "case 'profile_photo_upload':")
    && str_contains($proxy, "case 'profile_change_password':")
    && str_contains($proxy, "case 'profile_change_pin':"),
    'Existing profile API proxy actions are missing'
);
profile_expect(
    str_contains($profileUpdate, 'FIELD_NOT_ALLOWED')
    && !preg_match("/\$profileBody\s*\[\s*['\"](?:role|pricing_country|wallet_currency|status)['\"]\s*\]/", $proxy),
    'Profile authority fields are not protected by the existing backend contract'
);

echo "User Profile UI tests passed ({$tests} assertions).\n";
