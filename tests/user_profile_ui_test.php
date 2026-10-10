<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/profile.php');
$css = (string)file_get_contents($root . '/api/user/assets/pages/profile-page.css');
$js = (string)file_get_contents($root . '/api/user/assets/pages/profile-page.js');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$profileUpdate = (string)file_get_contents($root . '/api/user/profile_update.php');
$profileUpload = (string)file_get_contents($root . '/api/user/profile_photo_upload.php');
$authSession = (string)file_get_contents($root . '/api/auth/session.php');
$dashboardLib = (string)file_get_contents($root . '/api/lib/mobile_dashboard.php');
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

profile_expect(
    str_contains($page, 'id="profileSection"')
    && str_contains($page, 'class="profile-page-shell"')
    && str_contains($page, 'class="profile-fixed-hero"')
    && str_contains($page, 'class="profile-scroll-body"'),
    'Profile fixed-hero/scroll architecture is missing'
);
profile_expect(
    str_contains($page, 'href="/user/dashboard"')
    && str_contains($page, 'id="profilePageTitle"')
    && str_contains($page, 'href="/user/notifications"'),
    'Profile hero toolbar is incomplete'
);
profile_expect(
    str_contains($page, 'id="profileAvatarButton"')
    && str_contains($page, 'id="profileAvatarImage"')
    && str_contains($page, 'src="/assets/brand/zpay-icon.png"')
    && str_contains($page, 'id="profileAvatarInitials"')
    && str_contains($page, 'id="profilePhotoEditButton"')
    && str_contains($page, 'id="profileEditButton"')
    && str_contains($page, 'id="profilePhotoInput"'),
    'Profile photo/edit controls are incomplete'
);
profile_expect(
    str_contains($js, "const PROFILE_AVATAR_FALLBACK = '/assets/brand/zpay-icon.png'")
    && str_contains($js, "avatar.dataset.fallback = image ? 'profile' : 'brand'")
    && str_contains($js, "avatar.src = image || PROFILE_AVATAR_FALLBACK")
    && str_contains($js, "image.dataset.fallback !== 'brand'"),
    'Profile does not share the drawer brand fallback or recover from broken profile photos'
);
profile_expect(
    str_contains($page, '<h3>Security</h3>')
    && str_contains($page, 'id="profileChangePasswordBtn"')
    && str_contains($page, 'id="profileChangePinBtn"')
    && str_contains($page, 'Android app only'),
    'Profile Security section is incomplete'
);
profile_expect(
    str_contains($page, '<h3>Account &amp; App</h3>')
    && str_contains($page, 'id="profileCopyUidBtn"')
    && str_contains($page, 'id="profileCreatedAt"')
    && str_contains($page, 'id="profileAppVersion"')
    && str_contains($page, 'id="profileSessionStatus"')
    && str_contains($page, 'id="profileLogoutBtn"'),
    'Profile Account/App or logout section is incomplete'
);
profile_expect(
    !str_contains($page, 'Account Details')
    && !str_contains($page, 'profilePhoneCountry')
    && !str_contains($page, 'profileLastLogin'),
    'Legacy Profile summary remains'
);
profile_expect(
    str_contains($css, '.profile-fixed-hero')
    && str_contains($css, '.profile-scroll-body')
    && str_contains($css, 'overflow-y: auto')
    && str_contains($css, '@media (max-width: 340px)'),
    'Profile responsive fixed/scroll styling is incomplete'
);
profile_expect(
    str_contains($js, 'function maskEmail(value)')
    && str_contains($js, "maskEmail(profile.email)")
    && !str_contains($js, "$('profileEmail').textContent = profile.email"),
    'Profile email is not masked safely'
);
profile_expect(
    str_contains($js, 'function ensureProfileCropModal()')
    && str_contains($js, 'createImageBitmap')
    && str_contains($js, "data.append('profile_photo', blob, 'profile-cropped.jpg')")
    && str_contains($js, 'URL.revokeObjectURL'),
    'Profile photo crop/upload flow is incomplete'
);
profile_expect(
    str_contains($js, 'zpayProfileModal')
    && str_contains($js, 'closeProfileModal({ fromHistory: true })')
    && str_contains($js, 'trapFocusWithin(event, closeProfileModal)'),
    'Profile modal back/focus behavior is missing'
);
foreach (['profile_get', 'profile_update', 'profile_photo_upload', 'profile_change_password', 'profile_change_pin'] as $action) {
    profile_expect(str_contains($proxy, "case '{$action}':"), "missing profile proxy action {$action}");
}
profile_expect(
    str_contains($profileUpdate, 'FIELD_NOT_ALLOWED')
    && !preg_match("/\$profileBody\s*\[\s*['\"](?:role|pricing_country|wallet_currency|status)['\"]\s*\]/", $proxy),
    'Profile authority fields are not protected'
);
profile_expect(
    !str_contains($page . $js, 'openSection(')
    && !str_contains($page, 'data-page-section'),
    'Profile still depends on SPA routing'
);
profile_expect(
    str_contains($dashboardLib, 'function zpay_public_profile_photo_url')
    && str_contains($dashboardLib, "'PROFILE', 'profile'")
    && str_contains($authSession, "'profile_photo_url' => zpay_public_profile_photo_url(\$user)")
    && str_contains($profileUpdate, "'profile_photo_url' => zpay_public_profile_photo_url(\$user)")
    && str_contains($profileUpload, "'profile_photo_url' => zpay_public_profile_photo_url(\$user)"),
    'Profile APIs do not preserve migrated public photo field compatibility'
);
profile_expect(
    str_contains($js, "url.protocol === 'https:'")
    && str_contains($js, "'zpayswift.com'")
    && str_contains($js, "'stage.zpayswift.com'")
    && str_contains($js, 'allowedHosts.has(url.hostname.toLowerCase())'),
    'Profile photo renderer does not constrain migrated cross-host photos to trusted HTTPS origins'
);

echo "User Profile UI tests passed ({$tests} assertions).\n";
