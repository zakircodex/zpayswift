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
  <title>Z-Pay Swift Admin - bKash / Nagad Requests</title>
  <link rel="stylesheet" href="assets/dashboard.css?v=21">
  <link rel="stylesheet" href="assets/mfs-panel.css?v=3">
</head>
<body class="mfs-body">
  <div class="mfs-app">
    <aside class="mfs-sidebar" id="mfsSidebar">
      <div class="sidebar-brand">
        <div class="logo">Z</div>
        <div>
          <h1>Z-Pay Swift Admin</h1>
          <p>MFS operations panel</p>
        </div>
      </div>

      <nav class="nav" aria-label="Admin navigation">
        <div class="nav-title">Operations</div>
        <a class="nav-btn" href="dashboard.php">Dashboard <span>&rsaquo;</span></a>
        <a class="nav-btn active" href="mfs.php">bKash / Nagad <span>&rsaquo;</span></a>
        <a class="nav-btn" href="dashboard.php">Topup &amp; Bundles <span>&rsaquo;</span></a>
      </nav>

      <div class="sidebar-box">
        <div class="k">Current module</div>
        <div class="v">bKash / Nagad</div>
      </div>

      <div class="sidebar-box">
        <div class="k">Transfer limit</div>
        <div class="v">BDT 500 - 50,000</div>
      </div>

      <div class="sidebar-box mfs-sidebar-note">
        <div class="k">Quick note</div>
        <div class="v">Manage user and subadmin MFS requests from one queue.</div>
      </div>
    </aside>

    <button class="mfs-sidebar-backdrop" id="mfsSidebarBackdrop" type="button" aria-label="Close navigation"></button>

    <main class="mfs-main">
      <div class="mfs-topbar-wrap">
        <header class="mfs-topbar">
          <div class="mfs-heading-wrap">
            <button class="btn ghost mfs-menu-btn" id="mfsSidebarToggle" type="button" aria-controls="mfsSidebar" aria-expanded="false">Menu</button>
            <div>
              <div class="mfs-eyebrow">Z-Pay Swift Admin</div>
              <h1>bKash / Nagad Requests</h1>
              <p>Create and manage MFS requests</p>
            </div>
          </div>
          <div class="mfs-top-actions">
            <button class="btn blue" id="mfsReloadBtn" type="button">Refresh</button>
            <a class="btn ghost" href="dashboard.php">Back Dashboard</a>
          </div>
        </header>

        <div class="mfs-status-strip">
          <div class="status-chip">
            <span class="status-dot"></span>
            <span>Live admin queue</span>
          </div>
          <div class="status-chip">
            <span class="status-dot blue"></span>
            <span>Wallet hold protected</span>
          </div>
          <div class="status-chip">
            <span class="status-dot orange"></span>
            <span>BDT 500 - 50,000 per request</span>
          </div>
        </div>
      </div>

      <section class="mfs-summary-grid" aria-label="MFS request summary">
        <article class="mfs-summary-card pending">
          <div class="mfs-summary-label">Pending</div>
          <div class="mfs-summary-value" id="mfsSummaryPending">0</div>
          <div class="mfs-summary-sub">Waiting for action</div>
        </article>
        <article class="mfs-summary-card processing">
          <div class="mfs-summary-label">Processing</div>
          <div class="mfs-summary-value" id="mfsSummaryProcessing">0</div>
          <div class="mfs-summary-sub">Currently in progress</div>
        </article>
        <article class="mfs-summary-card done">
          <div class="mfs-summary-label">Done</div>
          <div class="mfs-summary-value" id="mfsSummaryDone">0</div>
          <div class="mfs-summary-sub">Completed requests</div>
        </article>
        <article class="mfs-summary-card failed">
          <div class="mfs-summary-label">Failed</div>
          <div class="mfs-summary-value" id="mfsSummaryFailed">0</div>
          <div class="mfs-summary-sub">Refunded requests</div>
        </article>
      </section>

      <section class="mfs-panel-card admin-mfs-create">
        <div class="mfs-panel-head">
          <div>
            <div class="mfs-section-kicker">New Request</div>
            <h2>Create bKash / Nagad Request</h2>
            <p>Create a request for any user or subadmin UID.</p>
          </div>
          <span class="mfs-limit-pill">BDT 500 - 50,000</span>
        </div>

        <form id="mfsCreateForm" class="admin-mfs-create-grid">
          <label class="mfs-field">
            <span>User / Subadmin UID</span>
            <input class="input" id="mfsCreateUid" required placeholder="Enter user or subadmin UID">
          </label>
          <label class="mfs-field">
            <span>Provider</span>
            <select class="input" id="mfsCreateProvider" required>
              <option value="BKASH">bKash</option>
              <option value="NAGAD">Nagad</option>
            </select>
          </label>
          <label class="mfs-field">
            <span>Receiver Number</span>
            <input class="input" id="mfsCreateReceiver" required inputmode="numeric" placeholder="01XXXXXXXXX">
          </label>
          <label class="mfs-field">
            <span>Amount BDT</span>
            <input class="input" id="mfsCreateAmountBdt" required type="number" min="500" max="50000" step="0.01" placeholder="500 - 50000">
          </label>
          <label class="mfs-field">
            <span>Reference <small>Optional</small></span>
            <input class="input" id="mfsCreateReference" placeholder="Reference or memo">
          </label>
          <label class="mfs-field admin-mfs-note">
            <span>Note <small>Optional</small></span>
            <textarea class="input" id="mfsCreateNote" rows="3" placeholder="Additional request note"></textarea>
          </label>
          <div class="admin-mfs-submit">
            <button class="btn brand" type="submit">Create Request</button>
          </div>
        </form>
      </section>

      <section class="mfs-panel-card">
        <div class="mfs-panel-head mfs-queue-head">
          <div>
            <div class="mfs-section-kicker">Request Queue</div>
            <h2>Manage MFS Requests</h2>
            <p>Filter the queue, review details and update request status.</p>
          </div>
          <div class="admin-mfs-tabs">
            <button class="admin-mfs-tab active" data-mfs-tab="pending" type="button">Pending</button>
            <button class="admin-mfs-tab" data-mfs-tab="processing" type="button">Processing</button>
            <button class="admin-mfs-tab" data-mfs-tab="done" type="button">Done</button>
          </div>
        </div>

        <div class="admin-mfs-toolbar">
          <label class="mfs-field">
            <span>Search</span>
            <input class="input" id="mfsSearch" placeholder="Request / UID / number">
          </label>
          <label class="mfs-field">
            <span>UID Filter</span>
            <input class="input" id="mfsUid" placeholder="Filter by UID">
          </label>
          <label class="mfs-field">
            <span>Number Filter</span>
            <input class="input" id="mfsNumber" placeholder="Filter by number">
          </label>
          <label class="mfs-field">
            <span>Service</span>
            <select class="input" id="mfsService">
              <option value="">All Services</option>
              <option value="SEND_MONEY">Send Money</option>
              <option value="CASH_OUT">Cash Out</option>
            </select>
          </label>
          <button class="btn blue admin-mfs-filter-btn" id="mfsApplyFilterBtn" type="button">Apply Filter</button>
        </div>

        <div class="admin-mfs-table-wrap table-wrap">
          <table>
            <thead>
              <tr>
                <th>Request</th>
                <th>User</th>
                <th>Provider</th>
                <th>Receiver</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Time</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="mfsTableBody">
              <tr><td colspan="8" class="empty">Loading...</td></tr>
            </tbody>
          </table>
        </div>

        <div class="admin-mfs-mobile-list" id="mfsMobileList"></div>
        <div id="mfsPageMsg" class="mfs-msg">Ready.</div>
      </section>
    </main>

    <div class="admin-mfs-modal hidden" id="mfsSuccessModal" role="dialog" aria-modal="true" aria-labelledby="mfsSuccessTitle">
      <div class="admin-mfs-modal-card">
        <div class="mfs-section-kicker">Complete Request</div>
        <h2 id="mfsSuccessTitle">Mark MFS Successful</h2>
        <p>Sender details are required. Multiple numbers, amounts, digits and notes are allowed.</p>
        <label for="mfsSuccessSenderDetails">Sender details</label>
        <textarea class="input" id="mfsSuccessSenderDetails" rows="6" placeholder="Example: 017... = BDT 500&#10;018... = BDT 300&#10;123 &amp; abc"></textarea>
        <label for="mfsSuccessTrxid">TRXID <small>Optional</small></label>
        <input class="input" id="mfsSuccessTrxid" placeholder="Optional transaction ID">
        <label for="mfsSuccessMessage">Message <small>Optional</small></label>
        <textarea class="input" id="mfsSuccessMessage" rows="3" placeholder="Leave empty to use the default success message"></textarea>
        <div class="admin-mfs-modal-actions">
          <button class="btn ghost" id="mfsSuccessCancelBtn" type="button">Cancel</button>
          <button class="btn brand" id="mfsSuccessSaveBtn" type="button">Mark Successful</button>
        </div>
      </div>
    </div>
  </div>

  <script src="assets/mfs-panel.js?v=1"></script>
</body>
</html>
