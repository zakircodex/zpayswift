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

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'dashboard',
    'title' => 'Z-Pay Swift',
    'section_id' => 'overviewSection',
    'body_class' => 'user-dashboard-page',
    'page_css' => 'dashboard-page.css',
    'page_js' => 'dashboard-page.js',
    'active_nav' => 'dashboard',
    'show_header' => false,
    'bootstrap_action' => 'dashboard_bootstrap',
    'bootstrap_params' => [
        'limit' => 50,
        'summary_only' => '1',
    ],
]);
user_page_begin($page);
?>
<div class="dashboard-fixed-stack">
  <div class="hero-card" aria-labelledby="dashboardHeroTitle">
    <span class="dashboard-orb dashboard-orb-one" aria-hidden="true"></span>
    <span class="dashboard-orb dashboard-orb-two" aria-hidden="true"></span>
    <span class="dashboard-orb dashboard-orb-three" aria-hidden="true"></span>
    <div class="dashboard-hero-topbar">
      <button id="openSidebarBtn" class="icon-btn hero-menu-button" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 6.5h16v2H4v-2Zm0 4.5h16v2H4v-2Zm0 4.5h16v2H4v-2Z"/></svg>
      </button>
      <h1 id="dashboardHeroTitle" class="dashboard-hero-title">Z-Pay Swift</h1>
      <a class="icon-btn notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </div>

    <div class="hero-balance-label">Available Balance</div>
    <div class="hero-balance-row">
      <div class="hero-balance"><span id="heroBalancePrefix">BDT</span> <span id="heroBalance">0.00</span></div>
      <a class="hero-add-money" href="/user/add-money">Add Money <span aria-hidden="true">&rsaquo;</span></a>
    </div>
    <div class="hero-hold-line">Hold Balance: <span id="heroHoldPrefix">BDT</span> <span id="heroHold">0.00</span></div>

    <div class="hero-grid">
      <div class="hero-mini">
        <div class="hero-mini-label hero-rate-label">Today Rate</div>
        <div class="hero-mini-value" id="heroRate">Rate unavailable</div>
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

  <div class="android-tagline" aria-label="টাকা পাঠানোর সব থেকে সহজ উপায় Z-Pay Swift">
    <div class="android-tagline-track" aria-hidden="true">
      <span class="android-tagline-item">টাকা পাঠানোর সব থেকে সহজ উপায় &quot;Z-Pay Swift&quot;</span>
      <span class="android-tagline-item">টাকা পাঠানোর সব থেকে সহজ উপায় &quot;Z-Pay Swift&quot;</span>
    </div>
  </div>
</div>

<section id="overviewSection" class="page-section dashboard-scroll-body active">
  <div id="zpayQuickActions" class="zpay-quick-card dashboard-recommended">
    <div class="zpay-quick-head"><h2 class="zpay-quick-title">Recommended</h2></div>
    <div class="zpay-service-grid">
      <a class="zpay-service-btn" href="/user/add-money" aria-label="Add Money"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V8h-5a3 3 0 0 0 0 6h5v3.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm11 3.5a1 1 0 1 0 0 2h5v-2h-5Z"/></svg></span><span class="zpay-service-name">Add Money</span></a>
      <a class="zpay-service-btn" href="/user/transfer" aria-label="Transfer"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m15.5 4 4 4-4 4V9H5V7h10.5V4ZM8.5 12v3H19v2H8.5v3l-4-4 4-4Z"/></svg></span><span class="zpay-service-name">Transfer</span></a>
      <a class="zpay-service-btn" href="/user/topup" aria-label="Top-Up"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v12h8V5H8Zm3 14v1h2v-1h-2Z"/></svg></span><span class="zpay-service-name">Top-Up</span></a>
      <a class="zpay-service-btn" href="/user/bkash" aria-label="bKash"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 12 15-8-4.5 16-3.2-6.1L4 12Zm7.8-.2 1.7 3.2 1.9-6.7-6.3 3.4 2.7.1Z"/></svg></span><span class="zpay-service-name">bKash</span></a>
      <a class="zpay-service-btn" href="/user/nagad" aria-label="Nagad"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 12 15-8-4.5 16-3.2-6.1L4 12Zm7.8-.2 1.7 3.2 1.9-6.7-6.3 3.4 2.7.1Z"/></svg></span><span class="zpay-service-name">Nagad</span></a>
      <a class="zpay-service-btn" href="/user/bundle" aria-label="Bundle"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z"/></svg></span><span class="zpay-service-name">Bundle</span></a>
      <button class="zpay-service-btn" type="button" data-dashboard-action="shopping" aria-label="Shopping"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 7V6a5 5 0 0 1 10 0v1h3l-1 14H5L4 7h3Zm2 0h6V6a3 3 0 0 0-6 0v1Zm0 3v2h2v-2H9Zm4 0v2h2v-2h-2Z"/></svg></span><span class="zpay-service-name">Shopping</span></button>
      <a class="zpay-service-btn" href="/user/contact-us" aria-label="Contact Us"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-9 9v4a3 3 0 0 0 3 3h2v-8H5.1a7 7 0 0 1 13.8 0H16v8h2.1A3.1 3.1 0 0 1 15 21h-3v-2h3a1 1 0 0 0 1-1v-7h3v5h1v-4a7 7 0 0 0-14 0v5h1v-6h3v8H6a3 3 0 0 1-3-3v-4a9 9 0 0 1 9-9Z"/></svg></span><span class="zpay-service-name">Contact Us</span></a>
      <button class="zpay-service-btn" type="button" data-dashboard-action="info" aria-label="Info"><span class="zpay-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M11 10h2v8h-2v-8Zm0-4h2v2h-2V6Zm1-4a10 10 0 1 1 0 20 10 10 0 0 1 0-20Zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z"/></svg></span><span class="zpay-service-name">Info</span></button>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
