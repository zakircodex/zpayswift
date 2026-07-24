<?php
declare(strict_types=1);

$zpayMobileAppKey = trim((string)($_SERVER['HTTP_X_APP_KEY'] ?? ''));
if ($zpayMobileAppKey !== '') {
    require_once dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/lib/wallet.php';
    require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';

    api_require_method('GET');
    api_require_app_key();

    $auth = zpay_dash_require_mobile_user(true);
    api_response(true, 'DASHBOARD_OK', 'Dashboard loaded', zpay_dash_dashboard_payload($auth));
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07172f">
  <title>Z-Pay Swift User Dashboard</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/api/user/assets/dashboard.css?v=14">
  <link rel="stylesheet" href="/api/user/assets/dashboard-ux.css?v=10">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/user-app.css?v=17">
</head>
<body>

<div id="loginView" class="login-wrap">
  <div class="login-card">
    <div class="brand">
      <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
      <div>
        <h1>Z-Pay Swift<br>User</h1>
        <p>Secure wallet, topup, bundle and bKash/Nagad requests</p>
      </div>
    </div>

    <div id="loginError" class="login-error"></div>

    <div class="field">
      <label>Phone Country</label>
      <select id="loginPhoneCountry" class="input">
        <option value="BD">Bangladesh (+880)</option>
        <option value="MY">Malaysia (+60)</option>
      </select>
    </div>

    <div class="field">
      <label>Phone Number</label>
      <input id="loginPhone" class="input" type="tel" inputmode="tel" placeholder="01XXXXXXXXX">
    </div>

    <div class="field">
      <label>Password</label>
      <input id="loginPassword" class="input" type="password" placeholder="Enter password">
    </div>

    <div class="login-check-row">
      <label class="login-check">
        <input type="checkbox" id="rememberTrustedDevice" checked>
        <span>Trust this device after OTP verification</span>
      </label>
    </div>

    <button id="loginBtn" class="btn green full-btn" type="button">Login</button>

    <div class="login-links">
      <a href="/user/register.php" class="link-btn">Create Account</a>
      <a href="/user/forgot.php" class="link-btn">Forgot Password / PIN</a>
    </div>
  </div>
</div>

<div id="appView" class="hidden">
  <div id="sidebarOverlay" class="sidebar-overlay"></div>

  <div class="app-shell">
    <aside id="sidebar" class="sidebar user-drawer" role="dialog" aria-modal="true" aria-label="User menu" aria-hidden="true" inert tabindex="-1">
      <button class="drawer-profile-fixed" data-page-section="profileSection" type="button" aria-label="Open profile">
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
          <small id="drawerCountryCurrency">- | BDT</small>
        </span>
      </button>

      <div class="drawer-menu-scroll" aria-label="Menu options">
        <div class="drawer-menu-section">
          <div class="drawer-section-label">ACCOUNT</div>
          <div class="drawer-section-card">
            <button class="side-btn" data-page-section="profileSection" type="button">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-5 0-8 2.6-8 6v1h16v-1c0-3.4-3-6-8-6Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>My Profile</strong><small>Account details and photo</small></span>
              <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
            </button>
            <button class="side-btn" data-page-section="profileSection" data-profile-action="security" type="button">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 5 5v6c0 4.4 2.8 8.4 7 10 4.2-1.6 7-5.6 7-10V5l-7-3Zm0 2.2 5 2.1V11c0 3.3-1.9 6.4-5 7.8A8.5 8.5 0 0 1 7 11V6.3l5-2.1Zm-1 4.8h2v4h-2V9Zm0 6h2v2h-2v-2Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>Security</strong><small>Password, PIN and biometric</small></span>
              <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
            </button>
          </div>
        </div>

        <div class="drawer-menu-section">
          <div class="drawer-section-label">SETTINGS</div>
          <div class="drawer-section-card">
            <button class="side-btn" data-page-section="notificationsSection" type="button">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>Notifications</strong><small>Account alerts and updates</small></span>
              <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
            </button>
          </div>
        </div>

        <div class="drawer-menu-section">
          <div class="drawer-section-label">SUPPORT</div>
          <div class="drawer-section-card">
            <button class="side-btn" data-page-section="supportSection" data-support-tab="new" type="button">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-9 9v4a3 3 0 0 0 3 3h2v-8H5.1a7 7 0 0 1 13.8 0H16v8h2.1A3.1 3.1 0 0 1 15 21h-3v-2h3a1 1 0 0 0 1-1v-7h3v5h1v-4a9 9 0 0 0-9-9Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>Contact Us</strong><small>Message Z-Pay Swift support</small></span>
              <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
            </button>
            <button class="side-btn" data-page-section="supportSection" data-support-tab="list" type="button">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H7.7L4 19.7V4Zm2 2v8.9l.9-.9H18V6H6Zm3 3h6v2H9V9Zm0 3h4v2H9v-2Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>Support Requests</strong><small>View and continue tickets</small></span>
              <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
            </button>
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
            <button class="side-btn" data-dashboard-action="info" type="button">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M11 10h2v8h-2v-8Zm0-4h2v2h-2V6Zm1-4a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>About Z-Pay Swift</strong><small>App and service information</small></span>
              <span class="drawer-chevron" aria-hidden="true">&rsaquo;</span>
            </button>
            <div class="side-btn drawer-version" aria-label="Web version">
              <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4V5Zm2 2v10h12V7H6Zm2 2h8v2H8V9Zm0 4h5v2H8v-2Z"/></svg></span>
              <span class="drawer-menu-copy"><strong>Web Version</strong><small>1.0.0</small></span>
            </div>
          </div>
        </div>

        <button id="drawerLogoutBtn" class="side-btn drawer-logout" type="button">
          <span class="drawer-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 4h9v16h-9v-2h7V6h-7V4Zm-1 4 1.4 1.4L8.8 11H15v2H8.8l1.6 1.6L9 16l-4-4 4-4Z"/></svg></span>
          <span class="drawer-menu-copy"><strong>Logout</strong><small>Sign out from this browser</small></span>
        </button>

      </div>
    </aside>

    <main class="main-panel">
      <div class="mobile-header">
        <div class="mobile-top-card">
          <div class="mobile-top-row">
            <button id="openSidebarBtn" class="icon-btn" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
              <span id="appNavIcon" aria-hidden="true">&#9776;</span>
            </button>
            <div id="appHeaderTitle" class="mobile-title">Z-Pay Swift</div>
            <button id="notificationButton" class="icon-btn notification-button" type="button" aria-label="Notifications">
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
              <span id="notificationBadge" class="notification-badge hidden">0</span>
            </button>
          </div>
        </div>
      </div>

      <div class="dashboard-fixed-stack">
      <div class="hero-card" aria-labelledby="dashboardHeroTitle">
        <div class="dashboard-hero-topbar">
          <button id="heroMenuButton" class="icon-btn hero-menu-button" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 6.5h16v2H4v-2Zm0 4.5h16v2H4v-2Zm0 4.5h16v2H4v-2Z"/></svg>
          </button>
          <h1 id="dashboardHeroTitle" class="dashboard-hero-title">Z-Pay Swift</h1>
          <button id="heroNotificationButton" class="icon-btn notification-button" type="button" aria-label="Notifications">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
            <span id="heroNotificationBadge" class="notification-badge hidden">0</span>
          </button>
        </div>

        <div class="hero-balance-label">Available Balance</div>
        <div class="hero-balance-row">
          <div class="hero-balance"><span id="heroBalancePrefix">BDT</span> <span id="heroBalance">0.00</span></div>
          <button class="hero-add-money" type="button" data-open-section="addMoneySection">Add Money <span aria-hidden="true">&rsaquo;</span></button>
        </div>

        <div class="hero-hold-line">Hold Balance: <span id="heroHoldPrefix">BDT</span> <span id="heroHold">0.00</span></div>

        <div class="hero-grid">
          <div class="hero-mini">
            <div class="hero-mini-label hero-rate-label">Today Rate</div>
            <div class="hero-mini-value hero-rate-value" id="heroRate">Rate unavailable</div>
          </div>

          <div class="hero-mini">
            <div class="hero-mini-label">This Month</div>
            <div class="hero-mini-value">Requests: <span id="heroRequests">0</span></div>
          </div>

          <div class="hero-mini">
            <div class="hero-mini-label">Hello</div>
            <div class="hero-mini-value" id="heroName">Z-Pay User</div>
          </div>
        </div>
      </div>

      <div class="android-tagline" aria-label="টাকা পাঠানোর সব থেকে সহজ উপায় Z-Pay Swift">
        <div class="android-tagline-track" aria-hidden="true">
          <span class="android-tagline-item">টাকা পাঠানোর সব থেকে সহজ উপায় &quot;Z-Pay Swift&quot;</span>
          <span class="android-tagline-item">টাকা পাঠানোর সব থেকে সহজ উপায় &quot;Z-Pay Swift&quot;</span>
        </div>
      </div>
      </div>

      <section id="overviewSection" class="page-section active">
        <div id="zpayQuickActions" class="zpay-quick-card dashboard-recommended">
          <div class="zpay-quick-head">
            <h2 class="zpay-quick-title">Recommended</h2>
          </div>
          <div class="zpay-service-grid">
            <button class="zpay-service-btn" type="button" data-open-section="addMoneySection" aria-label="Add Money">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V8h-5a3 3 0 0 0 0 6h5v3.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm11 3.5a1 1 0 1 0 0 2h5v-2h-5Z"/></svg></span>
              <span class="zpay-service-name">Add Money</span>
            </button>
            <button class="zpay-service-btn" type="button" data-open-section="transferSection" aria-label="Transfer">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m15.5 4 4 4-4 4V9H5V7h10.5V4ZM8.5 12v3H19v2H8.5v3l-4-4 4-4Z"/></svg></span>
              <span class="zpay-service-name">Transfer</span>
            </button>
            <button class="zpay-service-btn" type="button" data-open-section="topupSection" aria-label="Top-Up">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v12h8V5H8Zm3 14v1h2v-1h-2Z"/></svg></span>
              <span class="zpay-service-name">Top-Up</span>
            </button>
            <button class="zpay-service-btn" type="button" data-open-section="mfsSection" data-mfs-provider="BKASH" aria-label="bKash">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 12 15-8-4.5 16-3.2-6.1L4 12Zm7.8-.2 1.7 3.2 1.9-6.7-6.3 3.4 2.7.1Z"/></svg></span>
              <span class="zpay-service-name">bKash</span>
            </button>
            <button class="zpay-service-btn" type="button" data-open-section="mfsSection" data-mfs-provider="NAGAD" aria-label="Nagad">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 12 15-8-4.5 16-3.2-6.1L4 12Zm7.8-.2 1.7 3.2 1.9-6.7-6.3 3.4 2.7.1Z"/></svg></span>
              <span class="zpay-service-name">Nagad</span>
            </button>
            <button class="zpay-service-btn" type="button" data-open-section="bundleSection" aria-label="Bundle">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z"/></svg></span>
              <span class="zpay-service-name">Bundle</span>
            </button>
            <button class="zpay-service-btn" type="button" data-dashboard-action="shopping" aria-label="Shopping">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 7V6a5 5 0 0 1 10 0v1h3l-1 14H5L4 7h3Zm2 0h6V6a3 3 0 0 0-6 0v1Zm0 3v2h2v-2H9Zm4 0v2h2v-2h-2Z"/></svg></span>
              <span class="zpay-service-name">Shopping</span>
            </button>
            <button class="zpay-service-btn" type="button" data-open-section="supportSection" aria-label="Contact Us">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-9 9v4a3 3 0 0 0 3 3h2v-8H5.1a7 7 0 0 1 13.8 0H16v8h2.1A3.1 3.1 0 0 1 15 21h-3v-2h3a1 1 0 0 0 1-1v-7h3v5h1v-4a7 7 0 0 0-14 0v5h1v-6h3v8H6a3 3 0 0 1-3-3v-4a9 9 0 0 1 9-9Z"/></svg></span>
              <span class="zpay-service-name">Contact Us</span>
            </button>
            <button class="zpay-service-btn" type="button" data-dashboard-action="info" aria-label="Info">
              <span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M11 10h2v8h-2v-8Zm0-4h2v2h-2V6Zm1-4a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z"/></svg></span>
              <span class="zpay-service-name">Info</span>
            </button>
          </div>
        </div>
      </section>

      <section id="notificationsSection" class="page-section notification-page-section" aria-labelledby="notificationsPageTitle">
        <div class="notification-page-shell">
          <div class="notification-page-fixed-area">
            <header class="notification-page-header">
              <button id="notificationsBackButton" class="notification-page-icon-button" type="button" aria-label="Back to dashboard">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
              </button>
              <h2 id="notificationsPageTitle">Notifications</h2>
              <button id="notificationsMarkAllButton" class="notification-page-icon-button notification-mark-all-button" type="button" aria-label="Mark all notifications as read" title="Mark all as read">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m1.7 12.3 4 4 1.4-1.4-4-4-1.4 1.4Zm15.2-4.2-7.8 7.8-3-3-1.4 1.4 4.4 4.4 9.2-9.2-1.4-1.4Zm-4.2 0-1.4-1.4-4.2 4.2 1.4 1.4 4.2-4.2Zm7.6 0-9.2 9.2 1.4 1.4 9.2-9.2-1.4-1.4Z"/></svg>
              </button>
            </header>

            <div class="notification-page-tabs" role="tablist" aria-label="Notification filters">
              <button id="notificationAllTab" class="notification-page-tab active" type="button" role="tab" aria-selected="true" data-notification-filter="ALL">
                All Notifications
              </button>
              <button id="notificationUnreadTab" class="notification-page-tab" type="button" role="tab" aria-selected="false" data-notification-filter="UNREAD">
                Unread <span id="notificationUnreadCount">0</span>
              </button>
            </div>
          </div>

          <div class="notification-page-scroll-body">
            <div id="notificationPageLive" class="notification-page-live" aria-live="polite"></div>
            <div id="notificationList" class="notification-page-list" aria-busy="true">
              <div class="notification-page-skeleton" aria-hidden="true"></div>
              <div class="notification-page-skeleton" aria-hidden="true"></div>
              <div class="notification-page-skeleton" aria-hidden="true"></div>
              <div class="notification-page-skeleton" aria-hidden="true"></div>
            </div>
          </div>
        </div>
      </section>

      <section id="topupSection" class="page-section">
        <div class="wizard-card">
          <div class="section-head">
            <div>
              <h3 class="section-title">Create Topup</h3>
              <p class="section-sub">Simple step-by-step mobile topup request</p>
            </div>
          </div>

          <div class="wizard-progress">
            <div id="wizardPill1" class="wizard-pill active">Number</div>
            <div id="wizardPill2" class="wizard-pill">Operator</div>
            <div id="wizardPill3" class="wizard-pill">Amount</div>
            <div id="wizardPill4" class="wizard-pill">PIN</div>
            <div id="wizardPill5" class="wizard-pill">Confirm</div>
          </div>

          <div id="wizardStep1" class="wizard-step active">
            <div class="wizard-step-title">Enter Topup Number</div>
            <div class="wizard-step-sub">Write the customer mobile number</div>

            <input id="wizardTopupNumber" class="wizard-big-input" type="tel" inputmode="numeric" placeholder="01712345678">

            <div class="wizard-actions">
              <button id="wizardNext1" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep2" class="wizard-step">
            <div class="wizard-step-title">Select Operator</div>
            <div class="wizard-step-sub">Choose the correct mobile operator</div>

            <div class="choice-grid">
              <button type="button" class="choice-btn operator-choice" data-operator="GP">
                Grameenphone
                <small>GP</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="ROBI">
                Robi
                <small>ROBI</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="AIRTEL">
                Airtel
                <small>AIRTEL</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="BL">
                Banglalink
                <small>BL</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="TT">
                Teletalk
                <small>TT</small>
              </button>
            </div>

            <div class="wizard-actions">
              <button id="wizardBack2" class="btn ghost" type="button">Back</button>
              <button id="wizardNext2" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep3" class="wizard-step">
            <div class="wizard-step-title">Enter Amount</div>
            <div class="wizard-step-sub">Select quick amount or enter manually</div>

            <div class="choice-grid choice-grid-small-gap">
              <button type="button" class="choice-btn amount-choice" data-amount="20">BDT 20</button>
              <button type="button" class="choice-btn amount-choice" data-amount="30">BDT 30</button>
              <button type="button" class="choice-btn amount-choice" data-amount="50">BDT 50</button>
              <button type="button" class="choice-btn amount-choice" data-amount="100">BDT 100</button>
            </div>

            <input id="wizardAmount" class="wizard-big-input wizard-big-input-top" type="number" inputmode="decimal" step="0.01" min="1" placeholder="Enter amount">

            <div class="wizard-actions">
              <button id="wizardBack3" class="btn ghost" type="button">Back</button>
              <button id="wizardNext3" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep4" class="wizard-step">
            <div class="wizard-step-title">Enter Transaction PIN</div>
            <div class="wizard-step-sub">Your PIN is required to confirm this request</div>

            <input id="wizardPin" class="wizard-big-input" type="password" inputmode="numeric" placeholder="Enter PIN">

            <div class="wizard-actions">
              <button id="wizardBack4" class="btn ghost" type="button">Back</button>
              <button id="wizardNext4" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep5" class="wizard-step">
            <div class="wizard-step-title">Confirm Topup</div>
            <div class="wizard-step-sub">Check all information before submit</div>

            <div class="review-grid">
              <div class="review-box"><label>Number</label><strong id="reviewNumber">-</strong></div>
              <div class="review-box"><label>Operator</label><strong id="reviewOperator">-</strong></div>
              <div class="review-box"><label>Amount</label><strong id="reviewAmount">-</strong></div>
              <div class="review-box"><label>PIN</label><strong id="reviewPin">••••</strong></div>
            </div>

            <div class="wizard-actions">
              <button id="wizardBack5" class="btn ghost" type="button">Back</button>
              <button id="wizardConfirmBtn" class="btn green" type="button">Confirm Topup</button>
            </div>
          </div>

          <div class="result-box">
            <div id="topupResult" class="result-empty">No topup created yet.</div>
          </div>
        </div>
      </section>

      <section id="bundleSection" class="page-section">
  <div class="history-card bundle-page-card">
    <div class="bundle-section-head">
      <div>
        <h3 class="section-title">Bundle Offers</h3>
        <p class="section-sub">Choose an active bundle and create request</p>
      </div>

      <button id="refreshBundleOffersBtn" class="btn blue" type="button">Refresh Offers</button>
    </div>

    <div id="bundleOfferStatus" class="bundle-result-box compact-status-box">
      Click Refresh Offers to load active bundle offers.
    </div>

    <div id="bundleOffersGrid" class="bundle-grid">
      <div class="bundle-empty">
        <strong>No bundle offers loaded</strong>
        <span>Click Refresh Offers to load active offers.</span>
      </div>
    </div>
  </div>
</section>



<section id="mfsSection" class="page-section">
  <div class="wizard-card mfs-card">
    <div class="section-head">
      <div>
        <h3 class="section-title">bKash / Nagad Send Money</h3>
        <p class="section-sub">Personal bKash/Nagad request with review, PIN confirm and secure tracking.</p>
      </div>
    </div>

    <div id="mfsStepForm" class="mfs-step active">
      <div class="choice-grid">
        <button type="button" class="choice-btn mfs-provider-choice active" data-provider="BKASH">
          bKash
          <small>Personal</small>
        </button>
        <button type="button" class="choice-btn mfs-provider-choice" data-provider="NAGAD">
          Nagad
          <small>Personal</small>
        </button>
      </div>

      <div class="field field-top-gap">
        <label>Receiver Number</label>
        <input id="mfsReceiverNumber" class="input" type="tel" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX">
      </div>

      <div class="wizard-actions">
        <button id="mfsPreviewBtn" class="btn blue" type="button">Next</button>
      </div>
    </div>

    <div id="mfsStepAmount" class="mfs-step">
      <div class="wizard-step-title">Enter Amount</div>
      <div class="wizard-step-sub">Write amount before PIN confirmation</div>

      <div class="field field-top-gap">
        <label>Amount BDT</label>
        <input id="mfsAmountBdt" class="input" type="number" inputmode="decimal" step="0.01" min="500" max="50000" placeholder="BDT 500 - 50000">
      </div>

      <div class="field field-top-gap" id="mfsAmountRmField">
        <label>Amount RM <span class="muted">Malaysia account</span></label>
        <input id="mfsAmountRm" class="input" type="number" inputmode="decimal" step="0.01" min="0.01" placeholder="RM amount">
      </div>

      <div id="mfsRateHint" class="mfs-rate-hint"></div>

      <div id="mfsAmountNotice" class="mfs-step-notice"></div>

      <div class="wizard-actions">
        <button id="mfsAmountBackBtn" class="btn ghost" type="button">Back</button>
        <button id="mfsAmountNextBtn" class="btn green" type="button">Next</button>
      </div>
    </div>

    <div id="mfsStepPreview" class="mfs-step">
      <div class="result-card good">
        <div class="result-title">Review Send Money</div>
        <div id="mfsPreviewDetails" class="result-text">-</div>
      </div>

      <div class="field field-top-gap">
        <label>Reference <span class="muted">Optional</span></label>
        <input id="mfsReference" class="input" placeholder="Reference / note">
      </div>

      <div class="wizard-actions">
        <button id="mfsBackBtn" class="btn ghost" type="button">Back / Edit</button>
        <button id="mfsSendBtn" class="btn green" type="button">Confirm &amp; Send Money</button>
      </div>
    </div>

    <div id="mfsStepPin" class="mfs-step">
      <div class="wizard-step-title">Enter PIN</div>
      <div class="wizard-step-sub">PIN is required before final review</div>

      <input id="mfsPin" class="wizard-big-input" type="password" inputmode="numeric" placeholder="Enter PIN">

      <div class="wizard-actions">
        <button id="mfsPinBackBtn" class="btn ghost" type="button">Back</button>
        <button id="mfsConfirmBtn" class="btn green" type="button">Next</button>
      </div>
    </div>

    <div class="result-box hidden" aria-hidden="true">
      <div id="mfsResult" class="result-empty">No send money request created yet.</div>
    </div>
  </div>
</section>


      <section id="addMoneySection" class="page-section">
        <div class="history-card">
          <div class="section-head">
            <div>
              <h3 class="section-title">Add Money</h3>
              <p class="section-sub">Submit payment proof and wait for admin approval.</p>
            </div>
            <div class="add-money-actions">
              <button id="addMoneyOpenBtn" class="btn green" type="button">Add Money</button>
              <button id="addMoneyReloadBtn" class="btn ghost" type="button">Reload</button>
            </div>
          </div>

          <div id="addMoneyContent" class="add-money-content">
            <div class="detail-box">
              <label>Status</label>
              <strong>Loading add money settings...</strong>
            </div>
          </div>
        </div>
      </section>

      <section id="historySection" class="page-section">
        <div class="history-card">
          <div class="section-head">
            <div>
              <h3 class="section-title">My History</h3>
              <p class="section-sub">This month topup, bundle, bKash/Nagad and wallet history</p>
            </div>
          </div>

          <div class="history-toolbar">
            <div class="history-month-card">
              <label for="historyMonthInput">History Month</label>
              <input id="historyMonthInput" class="input" type="month">
              <span id="historyMonthLabel" class="history-month-label">This month</span>
            </div>
            <button id="historyRefreshBtn" class="btn blue" type="button">Refresh History</button>
          </div>

          <div class="filter-row">
            <button class="filter-btn active" data-filter="ALL" type="button">All</button>
            <button class="filter-btn" data-filter="PENDING" type="button">Pending</button>
            <button class="filter-btn" data-filter="PROCESSING" type="button">Processing</button>
            <button class="filter-btn" data-filter="SUCCESS" type="button">Successful</button>
            <button class="filter-btn" data-filter="FAILED" type="button">Failed</button>
          </div>

          <div id="historyList" class="history-list">
            <div class="history-item">
              <div class="history-id">No history found for this month.</div>
            </div>
          </div>
        </div>
      </section>

      <section id="servicesSection" class="page-section">
        <div class="feature-card services-hub">
          <div class="feature-heading">
            <div>
              <span class="feature-eyebrow">Z-Pay Swift</span>
              <h2>Services</h2>
              <p>Choose a service to continue.</p>
            </div>
          </div>
          <div class="android-service-grid">
            <button class="android-service-card" type="button" data-open-section="addMoneySection"><span class="service-glyph">+</span><strong>Add Money</strong></button>
            <button class="android-service-card" type="button" data-open-section="transferSection"><span class="service-glyph">⇄</span><strong>Transfer</strong></button>
            <button class="android-service-card" type="button" data-open-section="topupSection"><span class="service-glyph">▯</span><strong>Top-Up</strong></button>
            <button class="android-service-card" type="button" data-open-section="mfsSection" data-mfs-provider="BKASH"><span class="service-glyph service-glyph-send">➤</span><strong>bKash</strong></button>
            <button class="android-service-card" type="button" data-open-section="mfsSection" data-mfs-provider="NAGAD"><span class="service-glyph service-glyph-send">➤</span><strong>Nagad</strong></button>
            <button class="android-service-card" type="button" data-open-section="bundleSection"><span class="service-glyph">▣</span><strong>Bundle</strong></button>
            <button class="android-service-card" type="button" data-open-section="historySection"><span class="service-glyph">↻</span><strong>History</strong></button>
            <button class="android-service-card" type="button" data-open-section="supportSection"><span class="service-glyph">?</span><strong>Support</strong></button>
            <button class="android-service-card" type="button" data-open-section="profileSection"><span class="service-glyph">○</span><strong>Profile</strong></button>
          </div>
        </div>
      </section>

      <section id="transferSection" class="page-section">
        <div class="feature-card transfer-card">
          <div class="feature-heading">
            <div>
              <span class="feature-eyebrow">Secure wallet transfer</span>
              <h2>Z-Pay Transfer</h2>
              <p>Send money to another Z-Pay account.</p>
            </div>
          </div>

          <div class="android-stepper" aria-label="Transfer progress">
            <span id="transferPill1" class="active">Receiver</span>
            <span id="transferPill2">Amount</span>
            <span id="transferPill3">PIN</span>
            <span id="transferPill4">Review</span>
          </div>

          <div id="transferStepReceiver" class="transfer-step active">
            <div class="step-copy"><h3>Receiver account</h3><p>Enter the receiver's Z-Pay phone number.</p></div>
            <label class="feature-field" for="transferReceiverInput"><span>Phone or account</span><input id="transferReceiverInput" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX"></label>
            <div id="transferReceiverResult" class="inline-state hidden" role="status"></div>
            <button id="transferResolveBtn" class="android-primary-button" type="button">Check Receiver</button>
          </div>

          <div id="transferStepAmount" class="transfer-step">
            <div id="transferReceiverCard" class="recipient-card"></div>
            <label class="feature-field" for="transferAmountInput"><span>Amount</span><div class="money-input-wrap"><b id="transferCurrencyPrefix">BDT</b><input id="transferAmountInput" type="number" inputmode="decimal" min="1" step="0.01" placeholder="0.00"></div></label>
            <label class="feature-field" for="transferReferenceInput"><span>Reference <small>Optional</small></span><input id="transferReferenceInput" maxlength="80" placeholder="What is this transfer for?"></label>
            <div class="feature-actions"><button class="android-secondary-button" type="button" data-transfer-back="1">Back</button><button id="transferAmountNextBtn" class="android-primary-button" type="button">Continue</button></div>
          </div>

          <div id="transferStepPin" class="transfer-step">
            <div class="step-copy"><h3>Enter transaction PIN</h3><p>Your PIN verifies this transfer. It is never displayed or stored in the browser.</p></div>
            <label class="feature-field" for="transferPinInput"><span>4-digit PIN</span><input id="transferPinInput" type="password" inputmode="numeric" autocomplete="off" maxlength="4" placeholder="••••"></label>
            <div class="feature-actions"><button class="android-secondary-button" type="button" data-transfer-back="2">Back</button><button id="transferPreviewBtn" class="android-primary-button" type="button">Review Transfer</button></div>
          </div>

          <div id="transferStepReview" class="transfer-step">
            <div class="review-panel">
              <h3>Z-Pay Transfer Preview</h3>
              <div id="transferReviewRows" class="review-rows"></div>
            </div>
            <p class="hold-hint">Press and hold until confirmation completes.</p>
            <button id="transferHoldConfirmBtn" class="hold-confirm-button" type="button" aria-label="Press and hold to confirm transfer">
              <span class="hold-confirm-progress" aria-hidden="true"></span>
              <span class="hold-confirm-label">Press &amp; Hold to Transfer</span>
            </button>
            <button class="android-secondary-button full-width" type="button" data-transfer-back="3">Edit Transfer</button>
          </div>
        </div>
      </section>

      <section id="profileSection" class="page-section profile-page-section" aria-labelledby="profilePageTitle">
        <div class="profile-page-shell">
          <div class="profile-fixed-hero">
            <span class="profile-hero-orb profile-hero-orb-one" aria-hidden="true"></span>
            <span class="profile-hero-orb profile-hero-orb-two" aria-hidden="true"></span>
            <span class="profile-hero-orb profile-hero-orb-three" aria-hidden="true"></span>

            <div class="profile-toolbar">
              <button id="profileBackButton" class="profile-hero-icon-button" data-open-section="overviewSection" type="button" aria-label="Back to dashboard">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
              </button>
              <h2 id="profilePageTitle">Profile</h2>
              <button id="profileNotificationButton" class="profile-hero-icon-button notification-button" type="button" aria-label="Notifications">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
                <span id="profileNotificationBadge" class="notification-badge hidden">0</span>
              </button>
            </div>

            <div class="profile-hero-panel">
              <button id="profileAvatarButton" class="profile-avatar-button" type="button" aria-label="Change profile photo">
                <img id="profileAvatarImage" class="hidden" alt="Profile photo">
                <span id="profileAvatarInitials">ZP</span>
                <small>Edit</small>
              </button>
              <div class="profile-identity">
                <h2 id="profileName">Z-Pay User</h2>
                <p id="profilePhone">-</p>
                <p id="profileEmail">-</p>
                <div class="profile-badges"><span id="profileRoleBadge">USER</span><span id="profileStatusBadge">ACTIVE</span></div>
                <p id="profileCountryCurrency">-</p>
              </div>
              <button id="profileEditButton" class="profile-edit-button" type="button" aria-label="Edit profile">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m4 16.9-.7 3.8 3.8-.7L18.5 8.6l-3.1-3.1L4 16.9Zm16.7-10.5a1.5 1.5 0 0 0 0-2.1l-1-1a1.5 1.5 0 0 0-2.1 0l-.9.9 3.1 3.1.9-.9Z"/></svg>
              </button>
              <input id="profilePhotoInput" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp">
            </div>
          </div>

          <div class="profile-scroll-body">
            <div class="feature-card profile-section-card profile-security-card">
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

      <section id="supportSection" class="page-section">
        <div id="supportHomeView">
          <div class="support-hero-panel">
            <span class="support-hero-icon" aria-hidden="true">?</span>
            <div><span class="feature-eyebrow">Z-Pay Swift Help</span><h2>How can we help?</h2><p id="supportNotice">Create a request or continue an existing conversation.</p></div>
          </div>

          <div id="supportContactActions" class="support-contact-actions"></div>

          <div class="segmented-tabs" role="tablist" aria-label="Support views">
            <button id="supportNewTab" class="active" type="button" role="tab" aria-selected="true">Contact Us</button>
            <button id="supportListTab" type="button" role="tab" aria-selected="false">My Requests <span id="supportUnreadBadge" class="tab-badge hidden">0</span></button>
          </div>

          <div id="supportCreatePanel" class="support-tab-panel active">
            <form id="supportCreateForm" class="feature-card support-form" novalidate>
              <label class="feature-field" for="supportCategory"><span>Category</span><select id="supportCategory" name="category_code"><option value="">Select a category</option></select></label>
              <label class="feature-field" for="supportSubject"><span>Subject</span><input id="supportSubject" name="subject" maxlength="120" placeholder="Short issue title"></label>
              <label id="supportRelatedWrap" class="feature-field hidden" for="supportRelatedRequest"><span>Related request <small>Optional</small></span><select id="supportRelatedRequest" name="related_request_id"><option value="">No related request</option></select></label>
              <label class="feature-field" for="supportMessage"><span>Message</span><textarea id="supportMessage" name="message" maxlength="2500" rows="5" placeholder="Describe your issue"></textarea></label>
              <label class="attachment-picker" for="supportAttachments"><span>Add screenshots</span><small>JPG, PNG or WebP. Up to 3 files.</small><input id="supportAttachments" type="file" accept="image/jpeg,image/png,image/webp" multiple></label>
              <div id="supportAttachmentSummary" class="attachment-summary"></div>
              <button id="supportCreateButton" class="android-primary-button" type="submit">Submit Request</button>
            </form>
          </div>

          <div id="supportListPanel" class="support-tab-panel">
            <div class="support-list-head"><div><h3>My Support Requests</h3><p>Open a request to continue the conversation.</p></div><button id="supportRefreshButton" class="icon-command" type="button" aria-label="Refresh support requests">↻</button></div>
            <div id="supportTicketList" class="support-ticket-list"><div class="feature-empty-state">No support requests loaded.</div></div>
          </div>
        </div>

        <div id="supportConversationView" class="support-conversation hidden">
          <div class="conversation-header">
            <button id="supportConversationBack" class="icon-command" type="button" aria-label="Back to support requests">‹</button>
            <div><h2 id="supportConversationTitle">Support Request</h2><p id="supportConversationMeta">-</p></div>
            <span id="supportConversationStatus" class="status-pill pending">Open</span>
          </div>
          <div id="supportMessages" class="support-messages" aria-live="polite"></div>
          <form id="supportReplyForm" class="support-composer" novalidate>
            <label class="composer-attachment" for="supportReplyAttachment" aria-label="Attach screenshot">+</label>
            <input id="supportReplyAttachment" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp" multiple>
            <textarea id="supportReplyMessage" rows="1" maxlength="2500" placeholder="Write a reply..."></textarea>
            <button id="supportReplyButton" type="submit" aria-label="Send reply">Send</button>
            <div id="supportReplyAttachmentSummary" class="attachment-summary composer-summary"></div>
          </form>
          <div id="supportClosedNotice" class="closed-notice hidden"></div>
        </div>
      </section>
    </main>
  </div>
</div>

<div id="loginOtpModal" class="modal">
  <div class="modal-card modal-card-sm">
    <button id="closeLoginOtpModalBtn" class="modal-close" type="button">×</button>

    <h3 class="modal-title">Verify Login OTP</h3>
    <p class="modal-sub">Enter the OTP sent to your registered phone number</p>

    <div class="detail-grid">
      <div class="detail-box">
        <label>Phone</label>
        <strong id="loginOtpMaskedPhone">-</strong>
      </div>

      <div class="detail-box">
        <label>Expires In</label>
        <strong id="loginOtpExpiresText">5 minutes</strong>
      </div>
    </div>

    <div class="field field-top-gap">
      <label>OTP Code</label>
      <input id="loginOtpCode" class="input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6 digit OTP">
    </div>

    <div id="loginOtpStatus" class="otp-status">
      OTP পাঠানোর পরে এখানে status দেখাবে।
    </div>

    <div class="otp-actions">
      <button id="verifyLoginOtpBtn" class="btn green" type="button">Verify OTP</button>
      <button id="resendLoginOtpBtn" class="btn blue" type="button">Resend OTP</button>
      <button id="cancelLoginOtpBtn" class="btn ghost" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="bundleBuyModal" class="modal">
  <div class="modal-card">
    <button id="closeBundleBuyModalBtn" class="modal-close" type="button">×</button>

    <h3 class="modal-title">Buy Bundle</h3>
    <p class="modal-sub">Confirm bundle information and submit your request</p>

    <div class="detail-grid">
      <div class="detail-box"><label>Bundle Name</label><strong id="bundleBuyName">-</strong></div>
      <div class="detail-box"><label>Offer ID</label><strong id="bundleBuyOfferId">-</strong></div>
      <div class="detail-box"><label>Operator</label><strong id="bundleBuyOperator">-</strong></div>
      <div class="detail-box"><label>Price</label><strong id="bundleBuyAmount">BDT 0.00</strong></div>
      <div class="detail-box"><label>User Commission</label><strong id="bundleBuyUserCommission">BDT 0.00</strong></div>
      <div class="detail-box"><label>You Pay</label><strong id="bundleBuyNetCost">BDT 0.00</strong></div>
      <div class="detail-box"><label>Validity</label><strong id="bundleBuyValidity">-</strong></div>
      <div class="detail-box"><label>Expires</label><strong id="bundleBuyExpires">-</strong></div>
    </div>

    <div class="field field-top-gap">
      <label>Bundle Number</label>
      <input id="bundleBuyNumber" class="input" type="tel" inputmode="numeric" placeholder="01712345678">
    </div>

    <div class="field field-top-gap">
      <label>Transaction PIN</label>
      <input id="bundleBuyPin" class="input" type="password" inputmode="numeric" placeholder="Enter PIN">
    </div>

    <div class="field field-top-gap">
      <label>Note</label>
      <input id="bundleBuyNote" class="input" value="Bundle request from user panel">
    </div>

    <div id="bundleBuyResult" class="bundle-result-box">
      Enter bundle number and PIN, then confirm.
    </div>

    <div class="otp-actions">
      <button id="confirmBundleBuyBtn" class="btn green" type="button">Confirm Bundle</button>
      <button id="cancelBundleBuyBtn" class="btn ghost" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="detailModal" class="modal">
  <div class="modal-card">
    <button id="closeDetailModalBtn" class="modal-close" type="button">×</button>

    <h3 class="modal-title">Request Details</h3>
    <p class="modal-sub">Detailed view of your request information</p>

    <div class="detail-grid">
      <div class="detail-box"><label>Request ID</label><strong id="detailRequestId">-</strong></div>
      <div class="detail-box"><label>Status</label><strong id="detailStatus">-</strong></div>
      <div class="detail-box"><label>Type</label><strong id="detailType">-</strong></div>
      <div class="detail-box"><label>Source</label><strong id="detailSource">-</strong></div>
      <div class="detail-box"><label>Operator</label><strong id="detailOperator">-</strong></div>
      <div class="detail-box"><label>Number</label><strong id="detailNumber">-</strong></div>
      <div class="detail-box"><label>Amount</label><strong id="detailAmount">-</strong></div>
      <div class="detail-box"><label>Created</label><strong id="detailCreated">-</strong></div>
      <div class="detail-box"><label>Updated</label><strong id="detailUpdated">-</strong></div>
      <div class="detail-box"><label>Completed</label><strong id="detailCompleted">-</strong></div>
      <div class="detail-box full"><label>Message</label><strong id="detailMessage">-</strong></div>
    </div>
  </div>
</div>

<div id="loadingWrap" class="loading">
  <div class="loading-box">
    <div class="spinner"></div>
    <div id="loadingText">Loading...</div>
  </div>
</div>

<div id="toastWrap" class="toast-wrap"></div>

<div class="bottom-nav">
  <div class="bottom-nav-inner">
    <button class="bottom-btn active" data-page-section="overviewSection" type="button" aria-label="Dashboard">
      <span class="bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5v9A1.5 1.5 0 0 1 19.5 21h-4.25v-6h-6.5v6H4.5A1.5 1.5 0 0 1 3 19.5v-9Z"/></svg></span>
      <span class="bottom-label">Home</span>
    </button>
    <button class="bottom-btn" data-page-section="addMoneySection" type="button" aria-label="Add Money">
      <span class="bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V8h-5a3 3 0 0 0 0 6h5v3.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm11 3.5a1 1 0 1 0 0 2h5v-2h-5Z"/></svg></span>
      <span class="bottom-label">Add Money</span>
    </button>
    <button class="bottom-btn" data-page-section="transferSection" type="button" aria-label="Transfer">
      <span class="bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m15.5 4 4 4-4 4V9H5V7h10.5V4ZM8.5 12v3H19v2H8.5v3l-4-4 4-4Z"/></svg></span>
      <span class="bottom-label">Transfer</span>
    </button>
    <button class="bottom-btn" data-page-section="historySection" type="button" aria-label="History">
      <span class="bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 1-8.5 12h2.2A7 7 0 1 0 5 12H2l4-4 4 4H7a5 5 0 1 1 1.5 3.6l1.4-1.4A3 3 0 1 0 9 12h3V7h2v7H9V9.4l-1.8 1.8A7 7 0 0 0 12 19a7 7 0 0 0 0-14Z"/></svg></span>
      <span class="bottom-label">History</span>
    </button>
    <button class="bottom-btn" data-page-section="profileSection" type="button" aria-label="Profile">
      <span class="bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-5 0-8 2.6-8 6v1h16v-1c0-3.4-3-6-8-6Z"/></svg></span>
      <span class="bottom-label">Profile</span>
    </button>
  </div>
</div>

<script>
window.USER_PROXY_URL = '/api/user/proxy.php';
window.USER_LOGIN_URL = '/user/';
</script>
<script src="/api/user/assets/dashboard.js?v=28"></script>
<script src="/api/user/assets/dashboard-ux.js?v=12"></script>
<script src="/api/user/assets/user-app.js?v=9"></script>
</body>
</html>
