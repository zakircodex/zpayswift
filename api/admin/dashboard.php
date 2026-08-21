<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title>Z-Pay Swift Admin Dashboard</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/api/admin/assets/dashboard.css?v=23">
  <link rel="stylesheet" href="/api/admin/assets/admin-ux.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-ux.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/api/admin/assets/admin-users.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-users.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/api/admin/assets/admin-operations.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-operations.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/api/admin/assets/admin-transactions.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-transactions.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/api/admin/assets/admin-support.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-support.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/api/admin/assets/admin-settings.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-settings.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/api/admin/assets/zsky24-admin.css?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/zsky24-admin.css') ?: 1)) ?>">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
</head>
<body class="admin-premium-body">
  <div id="loginView" class="login-wrap hidden">
    <div class="login-card">
      <div class="brand">
        <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
        <div>
          <h1>Z-Pay Swift Admin</h1>
          <p>Secure operations dashboard login</p>
        </div>
      </div>

      <div id="loginError" class="login-error hidden"></div>

      <div class="field">
        <label>Phone Country</label>
        <select class="input" id="loginPhoneCountry">
          <option value="BD">Bangladesh (+880)</option>
          <option value="MY">Malaysia (+60)</option>
        </select>
      </div>

      <div class="field">
        <label>Phone</label>
        <input class="input" id="loginPhone" placeholder="01XXXXXXXXX">
      </div>

      <div class="field">
        <label>Password</label>
        <input class="input" id="loginPassword" type="password" placeholder="Enter password">
      </div>

      <button class="btn brand" id="loginBtn" style="width:100%;">Login</button>
    </div>
  </div>

  <div id="appView" class="app hidden">
    <aside class="sidebar" id="adminSidebar">
      <div class="sidebar-brand">
        <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
        <div>
          <h1>Z-Pay Swift Admin</h1>
          <p>Session-protected dashboard</p>
        </div>
      </div>

      <div class="nav">
        <div class="nav-title">Main</div>
        <button class="nav-btn" data-section="addMoneySection">Add Money <span>&rsaquo;</span></button>
        <button class="nav-btn" data-section="supportSection">Support <span>&rsaquo;</span></button>
        <button class="nav-btn active" data-section="dashboardSection">Dashboard <span>›</span></button>
        <button class="nav-btn" data-section="topupSection">Topup Requests <span>›</span></button>
        <button class="nav-btn" data-section="bundleSection">Bundles <span>›</span></button>
        <a id="zpayAdminMfsNav" class="nav-btn zpay-admin-mfs-link" href="/admin/mfs.php">bKash / Nagad <span>›</span></a>
        
        <button class="nav-btn" data-section="bundleOffersSection">Bundle Offers <span>›</span></button>
        
        <button class="nav-btn" data-section="usersSection">Users <span>›</span></button>
        <button class="nav-btn" data-section="operatorsSection">Operators <span>›</span></button>
        <button class="nav-btn" data-section="zsky24Section">Z Sky 24 <span>›</span></button>
      </div>

      <div class="sidebar-box">
        <div class="k">Logged in as</div>
        <div class="v" id="adminName">-</div>
      </div>

      <div class="sidebar-box">
        <div class="k">Role</div>
        <div class="v" id="adminRole">-</div>
      </div>

      <div class="sidebar-box">
        <div class="k">Auto refresh</div>
        <div style="margin-top:8px;">
          <select id="autoRefreshSelect">
            <option value="0">Off</option>
            <option value="60">60 sec</option>
            <option value="120">120 sec</option>
          </select>
        </div>
      </div>
    </aside>

    <button class="admin-sidebar-backdrop" id="adminSidebarBackdrop" type="button" aria-label="Close navigation"></button>

    <main class="main">
      <div class="topbar-wrap">
  <div class="topbar">
    <div class="admin-heading-wrap">
      <button class="btn ghost admin-sidebar-toggle" id="adminSidebarToggle" type="button" aria-controls="adminSidebar" aria-expanded="false">Menu</button>
      <div>
        <div class="admin-eyebrow">Z-Pay Swift Admin</div>
        <h2 id="adminPageTitle">Admin Dashboard</h2>
        <p id="adminPageSubtitle">Secure session-based operations panel.</p>
      </div>
    </div>
    <div class="actions">
      <a id="zpayAdminMfsTopLink" class="btn brand zpay-admin-mfs-toplink" href="/admin/mfs.php">bKash / Nagad</a>
      <button class="btn brand" id="directTopupBtn">Direct Topup</button>
      <button class="btn blue" id="openConfigBtn">System Settings</button>
      <button class="btn blue" id="refreshBtn">Refresh All</button>
      <button class="btn ghost" id="logoutBtn">Logout</button>
    </div>
  </div>

  <div class="status-strip">
    <div class="status-chip">
      <span class="status-dot" id="lastRefreshDot"></span>
      <span id="lastRefreshText">Last refresh: never</span>
    </div>

<div class="status-group" id="configStatusStrip">
  <div class="status-chip">
    <span class="status-dot" id="cfgTopupDot"></span>
    <span id="cfgTopupText">Topup: -</span>
  </div>

  <div class="status-chip">
    <span class="status-dot" id="cfgBundleDot"></span>
    <span id="cfgBundleText">Bundle: -</span>
  </div>

  <div class="status-chip">
    <span class="status-dot" id="cfgMaintenanceDot"></span>
    <span id="cfgMaintenanceText">Maintenance: -</span>
  </div>
</div>

    <div class="status-chip">
      <span class="status-dot blue" id="autoRefreshDot"></span>
      <span id="autoRefreshText">Auto refresh: Off</span>
    </div>

    <div class="status-chip">
      <span class="status-dot orange" id="uiStateDot"></span>
      <span id="uiStateText">UI state: Ready</span>
    </div>
  </div>
</div>

      <div class="cards dashboard-status-cards">
        <div class="card admin-summary-card pending"><div class="card-body"><div class="metric-title">Pending</div><div class="metric-value" id="countPending">0</div><div class="metric-sub">Waiting queue</div></div></div>
        <div class="card admin-summary-card claimed"><div class="card-body"><div class="metric-title">Claimed</div><div class="metric-value" id="countClaimed">0</div><div class="metric-sub">Taken by worker</div></div></div>
        <div class="card admin-summary-card processing"><div class="card-body"><div class="metric-title">Processing</div><div class="metric-value" id="countProcessing">0</div><div class="metric-sub">Dialing / waiting</div></div></div>
        <div class="card admin-summary-card done"><div class="card-body"><div class="metric-title">Done</div><div class="metric-value" id="countDone">0</div><div class="metric-sub">Completed requests</div></div></div>
      </div>

      <section class="section active" id="dashboardSection">
        <div class="split dashboard-primary-grid">
          <div class="card quick-summary-card">
            <div class="panel-head">
              <div>
                <h3>Quick Summary</h3>
                <p>Live data from your current admin API files.</p>
              </div>
            </div>
            <div class="card-body">
              <div class="detail-grid">
                <div class="detail-item"><label>Total Users</label><strong id="dashUsersCount">0</strong></div>
                <div class="detail-item"><label>Pending Bundles</label><strong id="dashBundleCount">0</strong></div>
                <div class="detail-item"><label>Active Operators</label><strong id="dashOperatorsCount">0</strong></div>
                <div class="detail-item"><label>Listed Page Balance</label><strong id="dashBalanceTotal">0.00</strong></div>
              </div>

              <div class="summary-strip">
                <div class="summary-box">
                  <div class="k">Topup Pending</div>
                  <div class="v" id="bottomPending">0</div>
                </div>
                <div class="summary-box">
                  <div class="k">Topup Claimed</div>
                  <div class="v" id="bottomClaimed">0</div>
                </div>
                <div class="summary-box">
                  <div class="k">Topup Processing</div>
                  <div class="v" id="bottomProcessing">0</div>
                </div>
                <div class="summary-box">
                  <div class="k">Topup Done</div>
                  <div class="v" id="bottomDone">0</div>
                </div>
              </div>
            </div>
          </div>



          <div class="card dashboard-log-card">
            <div class="panel-head">
              <div>
                <h3>Dashboard Log</h3>
                <p>Client-side actions and errors</p>
              </div>
            </div>
            <div class="card-body">
              <div class="log-box recent-scroll-box" id="logBox">Dashboard ready.</div>
            </div>
          </div>
        </div>
        
        <div class="card worker-status-card">
  <div class="panel-head">
    <div>
      <h3>Worker Status</h3>
      <p>Live worker heartbeat, device state and SIM overview.</p>
    </div>
    <button class="btn ghost" id="reloadWorkersBtn">Reload Workers</button>
  </div>
  

  <div class="card-body">
    <div class="detail-grid worker-metric-grid">
      <div class="detail-item"><label>Total Workers</label><strong id="workersTotalCount">0</strong></div>
      <div class="detail-item"><label>Online</label><strong id="workersOnlineCount">0</strong></div>
      <div class="detail-item"><label>Busy</label><strong id="workersBusyCount">0</strong></div>
      <div class="detail-item"><label>Idle</label><strong id="workersIdleCount">0</strong></div>
      <div class="detail-item"><label>Offline</label><strong id="workersOfflineCount">0</strong></div>
    </div>
  </div>

  <div class="table-wrap worker-table-wrap">
    <table class="workers-table">
      <thead>
        <tr>
          <th>Device</th>
          <th>Status</th>
          <th>Heartbeat</th>
          <th>SIM / Operators</th>
          <th>App</th>
          <th>Flags</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="workersTableBody">
        <tr><td colspan="7" class="empty">No worker data yet.</td></tr>
      </tbody>
    </table>
  </div>
</div>
        
       <div class="analytics-layout">
  <div class="card card-snapshot">
    <div class="panel-head">
      <div>
        <h3>Service Snapshot</h3>
        <p>Quick topup and bundle activity summary.</p>
      </div>
    </div>
    <div class="card-body">
      <div class="detail-grid">
        <div class="detail-item"><label>Topup Success</label><strong id="sumTopupSuccess">0</strong></div>
        <div class="detail-item"><label>Topup Failed</label><strong id="sumTopupFailed">0</strong></div>
        <div class="detail-item"><label>Bundle Pending</label><strong id="sumBundlePending">0</strong></div>
        <div class="detail-item"><label>Bundle Done</label><strong id="sumBundleDone">0</strong></div>
        <div class="detail-item"><label>Topup Success Amount</label><strong id="sumTopupSuccessAmount">0.00</strong></div>
        <div class="detail-item"><label>Topup Failed Amount</label><strong id="sumTopupFailedAmount">0.00</strong></div>
        <div class="detail-item"><label>Bundle Success</label><strong id="sumBundleSuccess">0</strong></div>
        <div class="detail-item"><label>Bundle Failed</label><strong id="sumBundleFailed">0</strong></div>
      </div>
    </div>
  </div>

  <div class="card card-activity">
    <div class="panel-head">
      <div>
        <h3>Recent Activity</h3>
        <p>Latest done topup and bundle actions.</p>
      </div>
    </div>
    <div class="card-body">
      <div class="detail-item" style="margin-bottom:12px;">
        <label>Recent Done Topups</label>
        <div class="log-box recent-scroll-box" id="recentTopupDoneBox">No recent topup activity.</div>
      </div>
      <div class="detail-item">
        <label>Recent Done Bundles</label>
        <div class="log-box recent-scroll-box" id="recentBundleDoneBox">No recent bundle activity.</div>
      </div>
    </div>
  </div>

  <div class="card card-overview">
    <div class="panel-head">
      <div>
        <h3>Visual Overview</h3>
        <p>Compact service graphs for topup and bundle activity.</p>
      </div>
    </div>
    <div class="card-body">
      <div class="chart-grid">
        <div class="chart-card">
          <div class="chart-title">Topup Activity Mix</div>

          <div class="chart-row">
            <div class="chart-label">Success</div>
            <div class="chart-track"><div class="chart-fill success" id="barTopupSuccess"></div></div>
            <div class="chart-value" id="barTopupSuccessText">0%</div>
          </div>

          <div class="chart-row">
            <div class="chart-label">Failed</div>
            <div class="chart-track"><div class="chart-fill danger" id="barTopupFailed"></div></div>
            <div class="chart-value" id="barTopupFailedText">0%</div>
          </div>

          <div class="chart-row">
            <div class="chart-label">Pending</div>
            <div class="chart-track"><div class="chart-fill warning" id="barTopupPending"></div></div>
            <div class="chart-value" id="barTopupPendingText">0%</div>
          </div>
        </div>

        <div class="chart-card">
          <div class="chart-title">Bundle Activity Mix</div>

          <div class="chart-row">
            <div class="chart-label">Success</div>
            <div class="chart-track"><div class="chart-fill success" id="barBundleSuccess"></div></div>
            <div class="chart-value" id="barBundleSuccessText">0%</div>
          </div>

          <div class="chart-row">
            <div class="chart-label">Failed</div>
            <div class="chart-track"><div class="chart-fill danger" id="barBundleFailed"></div></div>
            <div class="chart-value" id="barBundleFailedText">0%</div>
          </div>

          <div class="chart-row">
            <div class="chart-label">Pending</div>
            <div class="chart-track"><div class="chart-fill warning" id="barBundlePending"></div></div>
            <div class="chart-value" id="barBundlePendingText">0%</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

  
        
      </section>

      <section class="section" id="topupSection">
        <div class="card admin-topup-card">
          <div class="panel-head admin-transaction-panel-head">
            <div>
              <h3>Topup Requests</h3>
              <p>Pending, claimed, processing and done.</p>
            </div>
            <div class="tabs topup-status-tabs" aria-label="Top-Up request status">
              <button class="tab-btn active" data-topup-tab="pending" type="button">Pending</button>
              <button class="tab-btn" data-topup-tab="claimed" type="button">Claimed</button>
              <button class="tab-btn" data-topup-tab="processing" type="button">Processing</button>
              <button class="tab-btn" data-topup-tab="done" type="button">Done</button>
            </div>
          </div>

          <div class="toolbar admin-transaction-toolbar">
            <label class="toolbar-left admin-transaction-search" for="topupSearch">
              <span>Search queue</span>
              <input class="input md" id="topupSearch" placeholder="Search request id / uid / number / operator">
            </label>
            <div class="toolbar-right">
              <button class="btn ghost" id="reloadTopupBtn" type="button">Reload Topup</button>
            </div>
          </div>

          <div class="table-wrap admin-transaction-table-wrap topup-table-wrap">
            <table class="admin-transaction-table topup-requests-table">
              <thead>
                <tr>
                  <th>Request</th>
                  <th>User</th>
                  <th>Service</th>
                  <th>Wallet</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Worker</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="topupTableBody">
                <tr><td colspan="8" class="empty admin-transaction-state">No data yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="section" id="bundleSection">

  

  <div class="card admin-bundle-card">
    <div class="panel-head admin-transaction-panel-head">
      <div>
        <h3>Bundle Requests</h3>
        <p>Pending bundle queue with manual success / failed actions.</p>
      </div>

      <button class="btn ghost" id="reloadBundleBtn" type="button">Reload Bundles</button>
    </div>

    <div class="table-wrap admin-transaction-table-wrap bundle-table-wrap">
      <table class="admin-transaction-table bundle-requests-table">
        <thead>
          <tr>
            <th>Request</th>
            <th>User</th>
            <th>Bundle</th>
            <th>Financials</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody id="bundleTableBody">
          <tr><td colspan="7" class="empty admin-transaction-state">No data yet.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</section>
      
      
      
      <section class="section" id="bundleOffersSection">
  <div class="card bundle-offers-card">
    <div class="panel-head bundle-offers-panel-head">
      <div>
        <h3>Bundle Offers</h3>
        <p>Create, edit, expire and delete bundle offers shown to users, retailers and subadmins.</p>
      </div>

      <div class="row-actions">
        <button class="btn brand" id="createBundleOfferBtn" type="button">Add Bundle</button>
        <button class="btn ghost" id="reloadBundleOffersBtn" type="button">Reload Offers</button>
      </div>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <input class="input md" id="bundleOfferSearch" placeholder="Search offer / operator / bundle name">
      </div>

      <div class="toolbar-right">
        <select id="bundleOfferStatusFilter" class="input md">
          <option value="">All Status</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
          <option value="EXPIRED">Expired</option>
        </select>
      </div>
    </div>

    <div class="table-wrap bundle-offers-table-wrap">
      <table class="bundle-offers-table">
        <thead>
          <tr>
            <th>Offer</th>
            <th>Operator</th>
            <th>Amount</th>
            <th>Admin Commission</th>
            <th>Duration / Expiry</th>
            <th>Visibility</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>

        <tbody id="bundleOffersTableBody">
          <tr><td colspan="8" class="empty">No bundle offer loaded yet.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
      
      

      <section class="section" id="addMoneySection">
        <div class="card add-money-admin-card">
          <div class="panel-head add-money-panel-head">
            <div>
              <h3>Add Money Requests</h3>
              <p>Manual bKash, Nagad and bank transfer approvals.</p>
            </div>
            <div class="row-actions add-money-head-actions">
              <button class="btn blue" id="addMoneySettingsBtn" type="button">Payment Settings</button>
              <button class="btn ghost" id="reloadAddMoneyBtn" type="button">Reload</button>
            </div>
          </div>

          <div class="toolbar add-money-filter-row add-money-toolbar" aria-label="Add Money request filters">
            <label class="add-money-filter-field" for="addMoneyStatusFilter">
              <span>Status</span>
              <select id="addMoneyStatusFilter" class="input sm">
                <option value="">All Status</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
              </select>
            </label>
            <label class="add-money-filter-field" for="addMoneyCountryFilter">
              <span>Market</span>
              <select id="addMoneyCountryFilter" class="input sm">
                <option value="">All Countries</option>
                <option value="BD">BD</option>
                <option value="MY">MY</option>
              </select>
            </label>
            <label class="add-money-filter-field" for="addMoneyMethodFilter">
              <span>Method</span>
              <select id="addMoneyMethodFilter" class="input sm">
                <option value="">All Methods</option>
                <option value="BKASH">bKash</option>
                <option value="NAGAD">Nagad</option>
                <option value="BANK">Bank</option>
                <option value="EWALLET">eWallet</option>
              </select>
            </label>
          </div>

          <div class="table-wrap add-money-table-wrap">
            <table class="add-money-requests-table">
              <thead>
                <tr>
                  <th>Request</th>
                  <th>User</th>
                  <th>Market</th>
                  <th>Method</th>
                  <th>Amount</th>
                  <th>Submitted</th>
                  <th>Proof</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="addMoneyTableBody">
                <tr><td colspan="9" class="empty add-money-state-cell">No add money request loaded yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="section" id="supportSection">
        <div class="card support-admin-card">
          <div class="panel-head support-panel-head">
            <div>
              <h3>Support Tickets</h3>
              <p>Review customer support requests, replies and secure screenshots.</p>
            </div>
            <div class="row-actions">
              <button class="btn ghost" id="reloadSupportBtn" type="button">Reload</button>
            </div>
          </div>

          <div class="toolbar support-toolbar">
            <div class="toolbar-left support-filter-grid">
              <label class="support-filter-field" for="supportStatusFilter">
                <span>Status</span>
                <select id="supportStatusFilter" class="input sm">
                  <option value="">All Status</option>
                  <option value="OPEN">Open</option>
                  <option value="PENDING">Pending</option>
                  <option value="REPLIED">Replied</option>
                  <option value="RESOLVED">Resolved</option>
                  <option value="CLOSED">Closed</option>
                </select>
              </label>
              <label class="support-filter-field support-search-field" for="supportSearch">
                <span>Search</span>
                <input class="input md" id="supportSearch" placeholder="Search ticket, user, phone, subject or request ID">
              </label>
            </div>
          </div>

          <div class="table-wrap support-ticket-table-wrap">
            <table class="support-ticket-table">
              <thead>
                <tr>
                  <th>Ticket</th>
                  <th>User</th>
                  <th>Conversation</th>
                  <th>Related Request</th>
                  <th>Status</th>
                  <th>Attachments</th>
                  <th>Last Activity</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="supportTicketsTableBody">
                <tr><td colspan="8" class="empty support-ticket-state">No support ticket loaded yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card support-config-card">
          <div class="panel-head support-panel-head">
            <div>
              <h3>Contact Us Settings</h3>
              <p>Public quick-contact options and support ticket policy.</p>
            </div>
            <button class="btn brand" id="saveSupportConfigBtn" type="button">Save Contact Settings</button>
          </div>

          <div class="card-body support-config-body">
            <div class="form-grid support-config-grid">
              <label>Contact Us Enabled
                <select class="input" id="supportContactEnabled">
                  <option value="1">Enabled</option>
                  <option value="0">Disabled</option>
                </select>
              </label>
              <label>Ticket System Enabled
                <select class="input" id="supportTicketEnabled">
                  <option value="1">Enabled</option>
                  <option value="0">Disabled</option>
                </select>
              </label>
              <label>WhatsApp Enabled
                <select class="input" id="supportWhatsappEnabled">
                  <option value="0">Disabled</option>
                  <option value="1">Enabled</option>
                </select>
              </label>
              <label>WhatsApp Number
                <input class="input" id="supportWhatsappNumber" placeholder="+60...">
              </label>
              <label>Call Enabled
                <select class="input" id="supportCallEnabled">
                  <option value="0">Disabled</option>
                  <option value="1">Enabled</option>
                </select>
              </label>
              <label>Support Phone Number
                <input class="input" id="supportPhone" placeholder="+60...">
              </label>
              <label>Email Enabled
                <select class="input" id="supportEmailEnabled">
                  <option value="0">Disabled</option>
                  <option value="1">Enabled</option>
                </select>
              </label>
              <label>Support Email Address
                <input class="input" id="supportEmail" placeholder="support@example.com">
              </label>
              <label>Support Hours
                <input class="input" id="supportHours" placeholder="Every day, 10:00 AM - 10:00 PM">
              </label>
              <label>Average Response Text
                <input class="input" id="supportAverageResponse" placeholder="Average response time: within 24 hours.">
              </label>
              <label class="support-notice-field">Support Notice
                <textarea class="input" id="supportNotice" rows="3" placeholder="Never share your password, PIN or OTP."></textarea>
              </label>
              <label>Attachments Enabled
                <select class="input" id="supportAttachmentsEnabled">
                  <option value="1">Enabled</option>
                  <option value="0">Disabled</option>
                </select>
              </label>
              <label>Maximum Attachments
                <input class="input" id="supportMaxAttachments" type="number" min="0" max="5" step="1">
              </label>
              <label>Maximum File Size (bytes)
                <input class="input" id="supportMaxFileSize" type="number" min="1024" step="1024">
              </label>
              <label>Ticket Rate Limit (seconds)
                <input class="input" id="supportRateLimit" type="number" min="0" step="1">
              </label>
              <label>Reopen Allowed
                <select class="input" id="supportReopenAllowed">
                  <option value="1">Enabled</option>
                  <option value="0">Disabled</option>
                </select>
              </label>
            </div>
          </div>
        </div>

        <div class="card support-categories-card">
          <div class="panel-head support-panel-head">
            <div>
              <h3>Support Categories</h3>
              <p>Manage active categories shown in the Android Contact Us page.</p>
            </div>
            <button class="btn brand" id="supportCategoryAddBtn" type="button">Add Category</button>
          </div>
          <div class="table-wrap support-category-table-wrap">
            <table class="support-category-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Active</th>
                  <th>Sort</th>
                  <th>Related Request</th>
                  <th>Attachment</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="supportCategoriesTableBody">
                <tr><td colspan="7" class="empty">No category loaded yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="section" id="usersSection">
        <div class="card users-panel-card">
          <div class="panel-head users-panel-head">
            <div>
              <h3>Users</h3>
              <p>User details, wallet actions and ledger view.</p>
            </div>
          </div>

          <div class="toolbar users-toolbar">
            <div class="toolbar-left users-search-wrap">
              <label class="users-search-label" for="usersSearch">Search Users</label>
              <input class="input md" id="usersSearch" placeholder="Search UID / phone / email / recent name" autocomplete="off">
            </div>
            <div class="row-actions user-head-actions users-toolbar-actions">
              <button class="btn brand" id="createUserBtn" type="button">Create User</button>
              <button class="btn blue" id="walletHistoryBtn" type="button">Balance History</button>
              <button class="btn ghost" id="reloadUsersBtn" type="button">Reload Users</button>
            </div>
          </div>

          <div class="table-wrap users-table-wrap">
            <table class="users-table">
              <thead>
  <tr>
    <th>User</th>
    <th>Phone</th>
    <th>Status</th>
    <th>Role</th>
    <th>Country / Risk</th>
    <th>Available</th>
    <th>Created</th>
    <th>Last Login</th>
    <th>Action</th>
  </tr>
</thead>
<tbody id="usersTableBody">
  <tr><td colspan="9" class="empty users-state-cell">No data yet.</td></tr>
</tbody>
            </table>
          </div>
          <div class="users-pagination users-pagination-bar" id="usersPagination">
            <span id="usersPaginationText">0 users</span>
            <div class="row-actions">
              <button class="btn ghost" id="usersPrevBtn" type="button">Previous</button>
              <button class="btn ghost" id="usersNextBtn" type="button">Next</button>
            </div>
          </div>
        </div>
      </section>

      <section class="section operators-admin-section" id="operatorsSection">
        <div class="card operator-management-card">
          <div class="panel-head operator-panel-head">
            <div>
              <h3>Operators</h3>
              <p>Manage country availability, operator limits, prefixes and runtime configuration.</p>
            </div>
            <button class="btn ghost operator-reload-btn" id="reloadOperatorsBtn" type="button">Reload Operators</button>
          </div>

          <div class="operator-content">
            <div class="operator-subsection-head">
              <div>
                <span class="operator-kicker">Market availability</span>
                <h4>Top-Up Countries</h4>
              </div>
            </div>
            <div class="operator-country-grid" id="topupCountriesTableBody" aria-live="polite">
              <div class="operator-empty-state">No country data yet.</div>
            </div>

            <div class="operator-subsection-head operator-list-heading">
              <div>
                <span class="operator-kicker">Runtime catalog</span>
                <h4>Operator Configuration</h4>
              </div>
            </div>
            <div class="operators-grid" id="operatorsTableBody" aria-live="polite">
              <div class="operator-empty-state">No data yet.</div>
            </div>
          </div>
        </div>
      </section>

      <section class="section" id="zsky24Section" aria-labelledby="zsky24AdminTitle">
        <div class="zsky-admin-shell">
          <div class="zsky-admin-hero">
            <div>
              <span class="zsky-admin-kicker">Z SKY 24 OPERATIONS</span>
              <h3 id="zsky24AdminTitle">Creator credit control</h3>
              <p>Review verified ad revenue and creator transfer requests without changing settlement values in the browser.</p>
            </div>
            <button class="btn blue" id="zsky24RefreshBtn" type="button">Refresh</button>
          </div>

          <div class="zsky-admin-metrics" aria-label="Z Sky 24 queue summary">
            <div class="zsky-admin-metric"><span>Ready to settle</span><strong id="zskySettlementCount">0</strong></div>
            <div class="zsky-admin-metric"><span>Transfer review</span><strong id="zskyTransferCount">0</strong></div>
            <div class="zsky-admin-metric warning"><span>Ad review</span><strong id="zskyImpressionCount">0</strong></div>
            <div class="zsky-admin-metric danger"><span>Reconciliation flags</span><strong id="zskyReconciliationCount">0</strong></div>
          </div>

          <div class="zsky-admin-tabs" role="tablist" aria-label="Creator credit queues">
            <button class="zsky-admin-tab active" type="button" data-zsky-tab="settlements" role="tab" aria-selected="true">Creator credits</button>
            <button class="zsky-admin-tab" type="button" data-zsky-tab="transfers" role="tab" aria-selected="false">Transfer requests</button>
            <button class="zsky-admin-tab" type="button" data-zsky-tab="impressions" role="tab" aria-selected="false">Ad verification</button>
          </div>

          <div class="card zsky-admin-panel">
            <div class="panel-head">
              <div><h3 id="zskyQueueTitle">Verified revenue</h3><p id="zskyQueueSubtitle">Only provider-verified impressions can be settled.</p></div>
            </div>
            <div id="zskyQueueBody" class="zsky-admin-list" aria-live="polite">
              <div class="empty">Open Z Sky 24 to load creator credit data.</div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <div class="drawer" id="drawer" aria-hidden="true" inert>
    <div class="drawer-head">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <div>
          <h3 id="drawerTitle" style="margin:0;">Details</h3>
          <p id="drawerSub" style="margin:4px 0 0;color:var(--muted);">Details</p>
        </div>
        <button class="btn ghost" id="closeDrawerBtn">Close</button>
      </div>
    </div>
    <div class="drawer-body" id="drawerBody"></div>
    <div class="drawer-foot" id="drawerFoot">
      <button class="btn ghost" id="drawerFootClose">Close</button>
    </div>
  </div>

  <div class="modal-wrap" id="modalWrap">
    <div class="modal">
      <div class="modal-head">
        <h3 id="modalTitle" style="margin:0;">Action</h3>
      </div>
      <div class="modal-body" id="modalBody"></div>
      <div class="modal-foot" id="modalFoot"></div>
    </div>
  </div>

  <div class="loading-wrap" id="loadingWrap">
    <div class="loading-box">
      <div class="spinner"></div>
      <div id="loadingText">Loading...</div>
    </div>
  </div>

  <div class="toast-wrap" id="toastWrap"></div>

<script>
window.ADMIN_PROXY_URL = '/api/admin/proxy.php';
</script>
<script src="/api/admin/assets/dashboard.js?v=42"></script>
<script src="/api/admin/assets/zsky24-admin.js?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/zsky24-admin.js') ?: 1)) ?>"></script>
<script src="/api/admin/assets/admin-dashboard-ux.js?v=<?= rawurlencode((string)(@filemtime(__DIR__ . '/assets/admin-dashboard-ux.js') ?: 1)) ?>"></script>

</body>
</html>
