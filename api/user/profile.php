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
    'show_bottom_nav' => true,
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
        <a id="profileBackButton" class="profile-hero-icon-button" href="/user/dashboard" aria-label="Return to dashboard">
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
          </div>
        </div>
        <button id="profileEditButton" class="profile-edit-button" type="button" aria-label="Edit profile">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m4 16.9-.7 3.8 3.8-.7L18.5 8.6l-3.1-3.1L4 16.9Zm16.7-10.5a1.5 1.5 0 0 0 0-2.1l-1-1a1.5 1.5 0 0 0-2.1 0l-.9.9 3.1 3.1.9-.9Z"/></svg>
        </button>
        <input id="profilePhotoInput" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp">
      </div>
    </div>

    <div class="profile-scroll-body">
      <div id="security" class="feature-card profile-section-card profile-security-card">
        <h3>Security</h3>
        <button id="profileChangePasswordBtn" class="profile-action-row" type="button">
          <span class="profile-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 10V8a5 5 0 0 1 10 0v2h1.5A1.5 1.5 0 0 1 20 11.5v8a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19.5v-8A1.5 1.5 0 0 1 5.5 10H7Zm2 0h6V8a3 3 0 0 0-6 0v2Zm3 3a2 2 0 0 0-1 3.73V18h2v-1.27A2 2 0 0 0 12 13Z"/></svg></span>
          <span class="profile-action-copy"><strong>Change Password</strong><small>Update securely</small></span>
          <b aria-hidden="true">&rsaquo;</b>
        </button>
        <button id="profileChangePinBtn" class="profile-action-row" type="button">
          <span class="profile-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 4.5 5v5.5c0 5 3.2 9.5 7.5 11.5 4.3-2 7.5-6.5 7.5-11.5V5L12 2Zm0 2.2 5.5 2.2v4.1c0 3.9-2.3 7.5-5.5 9.2-3.2-1.7-5.5-5.3-5.5-9.2V6.4L12 4.2ZM9 9h2V7h2v2h2v2h-2v2h-2v-2H9V9Zm0 6h6v2H9v-2Z"/></svg></span>
          <span class="profile-action-copy"><strong>Change PIN</strong><small>Update transaction PIN</small></span>
          <b aria-hidden="true">&rsaquo;</b>
        </button>
        <button id="profileBiometricBtn" class="profile-action-row disabled-row" type="button" disabled aria-disabled="true">
          <span class="profile-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2a8 8 0 0 0-8 8v2h2v-2a6 6 0 0 1 12 0v2h2v-2a8 8 0 0 0-8-8Zm-4 8a4 4 0 0 1 8 0v3a7 7 0 0 1-2.1 5l1.4 1.4A9 9 0 0 0 18 13v-3a6 6 0 0 0-12 0v3c0 2.5-1 4.8-2.7 6.5l1.4 1.4A11 11 0 0 0 8 13v-3Zm4-2a2 2 0 0 0-2 2v3c0 3.2-1.2 6.2-3.4 8.4L8 22.8A13.8 13.8 0 0 0 12 13v-3h2v3c0 3.6-1.3 7-3.7 9.6l1.5 1.4A16 16 0 0 0 16 13v-3a4 4 0 0 0-4-4Z"/></svg></span>
          <span class="profile-action-copy"><strong>Fingerprint / Biometric</strong><small>Android app only</small></span>
          <b aria-hidden="true">&rsaquo;</b>
        </button>
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
