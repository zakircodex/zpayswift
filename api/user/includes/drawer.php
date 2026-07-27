<?php
declare(strict_types=1);

if (empty($userPage['show_drawer'])) {
    echo '<main class="main-panel user-page-panel">';
    return;
}
?>
<aside id="sidebar" class="sidebar user-drawer" role="dialog" aria-modal="true" aria-label="User menu" aria-hidden="true" inert tabindex="-1">
  <a class="drawer-profile-fixed" href="/user/profile" aria-label="Open profile">
    <span class="drawer-profile-orb drawer-orb-one" aria-hidden="true"></span>
    <span class="drawer-profile-orb drawer-orb-two" aria-hidden="true"></span>
    <span class="drawer-avatar">
      <img id="drawerAvatarImage" src="/assets/brand/zpay-icon.png" alt="">
      <span id="drawerAvatarInitials">ZP</span>
    </span>
    <span class="drawer-profile-copy">
      <strong id="drawerUserName">Z-PAY USER</strong>
      <small id="drawerUserPhone">-</small>
      <span class="drawer-chip-row">
        <span id="drawerRoleChip" class="drawer-chip">User</span>
        <span id="drawerStatusChip" class="drawer-chip status-chip">Active</span>
      </span>
      <small id="drawerCountryCurrency">- | -</small>
    </span>
  </a>

  <div class="drawer-menu-scroll" aria-label="Menu options">
    <div class="drawer-menu-section">
      <div class="drawer-section-label">ACCOUNT</div>
      <div class="drawer-section-card">
        <a class="side-btn" href="/user/profile">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-5 0-8 2.6-8 6v1h16v-1c0-3.4-3-6-8-6Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>My Profile</strong><small>Account details and photo</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
        <a class="side-btn" href="/user/profile#security">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 5 5v6c0 4.4 2.8 8.4 7 10 4.2-1.6 7-5.6 7-10V5l-7-3Zm0 2.2 5 2.1V11c0 3.3-1.9 6.4-5 7.8A8.5 8.5 0 0 1 7 11V6.3l5-2.1Zm-1 4.8h2v4h-2V9Zm0 6h2v2h-2v-2Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Security</strong><small>Password, PIN and biometric</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
      </div>
    </div>

    <div class="drawer-menu-section">
      <div class="drawer-section-label">SETTINGS</div>
      <div class="drawer-section-card">
        <a class="side-btn" href="/user/notifications">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Notifications</strong><small>Account alerts and updates</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
      </div>
    </div>

    <div class="drawer-menu-section">
      <div class="drawer-section-label">SUPPORT</div>
      <div class="drawer-section-card">
        <a class="side-btn" href="/user/contact-us">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-9 9v4a3 3 0 0 0 3 3h2v-8H5.1a7 7 0 0 1 13.8 0H16v8h2.1A3.1 3.1 0 0 1 15 21h-3v-2h3a1 1 0 0 0 1-1v-7h3v5h1v-4a9 9 0 0 0-9-9Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Contact Us</strong><small>Message Z-Pay Swift support</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
        <a class="side-btn" href="/user/support">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H7.7L4 19.7V4Zm2 2v8.9l.9-.9H18V6H6Zm3 3h6v2H9V9Zm0 3h4v2H9v-2Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Support Requests</strong><small>View and continue tickets</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
        <a class="side-btn drawer-link" href="/privacy" target="_blank" rel="noopener">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 5 5v6c0 4.4 2.8 8.4 7 10 4.2-1.6 7-5.6 7-10V5l-7-3Zm0 2.2 5 2.1V11c0 3.3-1.9 6.4-5 7.8A8.5 8.5 0 0 1 7 11V6.3l5-2.1Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Privacy Policy</strong><small>Safe public link</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
        <a class="side-btn drawer-link" href="/terms" target="_blank" rel="noopener">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6V2Zm8 2H8v16h10V8h-4V4Zm-4 7h6v2h-6v-2Zm0 4h6v2h-6v-2Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Terms &amp; Conditions</strong><small>Safe public link</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </a>
      </div>
    </div>

    <div class="drawer-menu-section">
      <div class="drawer-section-label">ABOUT</div>
      <div class="drawer-section-card">
        <button class="side-btn" type="button" data-shell-action="info">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M11 10h2v8h-2v-8Zm0-4h2v2h-2V6Zm1-4a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>About Z-Pay Swift</strong><small>App and service information</small></span>
          <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
        </button>
        <div class="side-btn drawer-version" aria-label="Web version">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4V5Zm2 2v10h12V7H6Zm2 2h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Web Version</strong><small>1.0.0</small></span>
          <span class="drawer-chevron" aria-hidden="true"></span>
        </div>
      </div>
    </div>

    <button id="drawerLogoutBtn" class="side-btn drawer-logout" type="button">
      <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 4h9v16h-9v-2h7V6h-7V4Zm-1 4 1.4 1.4L8.8 11H15v2H8.8l1.6 1.6L9 16l-4-4 4-4Z"/></svg></span>
      <span class="drawer-menu-copy"><strong>Logout</strong><small>Sign out from this browser</small></span>
    </button>
  </div>
</aside>
<main class="main-panel user-page-panel">
