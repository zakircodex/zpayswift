<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'profile',
    'title' => 'Profile',
    'section_id' => 'profileSection',
    'body_class' => 'user-profile-page',
    'page_css' => 'profile-page.css',
    'page_js' => 'profile-page.js',
    'active_nav' => 'profile',
    'show_header' => false,
    'show_bottom_nav' => false,
]);
user_page_begin($page);
?>
<section id="profileSection" class="page-section profile-page-section active" aria-labelledby="profilePageTitle">
  <div class="profile-page-shell">
    <div class="profile-fixed-hero">
      <span class="profile-hero-orb profile-hero-orb-one" aria-hidden="true"></span>
      <span class="profile-hero-orb profile-hero-orb-two" aria-hidden="true"></span>
      <span class="profile-hero-orb profile-hero-orb-three" aria-hidden="true"></span>
      <div class="profile-toolbar">
        <a class="profile-hero-icon-button" href="/user/dashboard" aria-label="Back to dashboard">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
        </a>
        <h2 id="profilePageTitle">Profile</h2>
        <a id="profileNotificationButton" class="profile-hero-icon-button notification-button" href="/user/notifications" aria-label="Notifications">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
          <span data-notification-badge class="notification-badge hidden">0</span>
        </a>
      </div>
      <div class="profile-hero-panel">
        <div class="profile-photo-wrap">
          <button id="profileAvatarButton" class="profile-avatar-button" type="button" aria-label="Change profile photo">
            <img id="profileAvatarImage" class="hidden" alt="Profile photo">
            <span id="profileAvatarInitials">ZP</span>
          </button>
          <button id="profilePhotoEditButton" class="profile-photo-edit-badge" type="button" aria-label="Edit profile photo">Edit</button>
        </div>
        <div class="profile-identity">
          <h2 id="profileName">Z-Pay User</h2>
          <p id="profilePhone">-</p>
          <p id="profileEmail">-</p>
          <div class="profile-badges"><span id="profileRoleBadge">USER</span><span id="profileStatusBadge">ACTIVE</span></div>
          <div class="profile-identity-country-row">
            <p id="profileCountryCurrency">-</p>
            <button id="profileEditButton" class="profile-edit-button" type="button" aria-label="Edit profile">
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m4 16.9-.7 3.8 3.8-.7L18.5 8.6l-3.1-3.1L4 16.9Zm16.7-10.5a1.5 1.5 0 0 0 0-2.1l-1-1a1.5 1.5 0 0 0-2.1 0l-.9.9 3.1 3.1.9-.9Z"/></svg>
            </button>
          </div>
        </div>
        <input id="profilePhotoInput" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp">
      </div>
    </div>

    <div class="profile-scroll-body">
      <div id="security" class="feature-card profile-section-card profile-security-card">
        <h3>Security</h3>
        <button id="profileChangePasswordBtn" class="profile-action-row" type="button"><span><strong>Change Password</strong><small>Update securely</small></span><b aria-hidden="true">&rsaquo;</b></button>
        <button id="profileChangePinBtn" class="profile-action-row" type="button"><span><strong>Change PIN</strong><small>Update transaction PIN</small></span><b aria-hidden="true">&rsaquo;</b></button>
        <button id="profileBiometricBtn" class="profile-action-row disabled-row" type="button" disabled aria-disabled="true"><span><strong>Fingerprint / Biometric</strong><small>Android app only</small></span><b aria-hidden="true">&rsaquo;</b></button>
      </div>
      <div class="feature-card profile-section-card profile-account-app-card">
        <h3>Account &amp; App</h3>
        <div class="profile-account-list">
          <button id="profileCopyUidBtn" class="profile-copy-row" type="button" aria-label="Copy account ID">
            <span><strong>Account ID / UID</strong><small id="profileUid">-</small></span>
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 8h11v13H8V8Zm2 2v9h7v-9h-7ZM5 3h11v3h-2V5H7v9H5V3Z"/></svg>
          </button>
          <div class="profile-info-row"><i aria-hidden="true"></i><span><small>Registered Date</small><strong id="profileCreatedAt">-</strong></span></div>
          <div class="profile-info-row"><i aria-hidden="true"></i><span><small>Web Version</small><strong id="profileAppVersion">Version 1.0.0</strong></span></div>
          <div class="profile-info-row"><i aria-hidden="true"></i><span><small>Session Status</small><strong id="profileSessionStatus">Active</strong></span></div>
        </div>
      </div>
      <div class="feature-card profile-section-card profile-logout-card">
        <button id="profileLogoutBtn" class="profile-logout-button" type="button">Logout</button>
      </div>
      <div class="profile-bottom-safe" aria-hidden="true"></div>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
