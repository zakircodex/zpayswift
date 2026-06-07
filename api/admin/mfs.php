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
  <link rel="stylesheet" href="/api/admin/assets/dashboard.css?v=21">
  <link rel="stylesheet" href="/api/admin/assets/mfs-panel.css?v=5">
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
        <a class="nav-btn" href="/admin/">Dashboard <span>&rsaquo;</span></a>
        <button class="nav-btn" data-mfs-view-target="create" type="button">Create bKash / Nagad <span>&rsaquo;</span></button>
        <button class="nav-btn active" data-mfs-view-target="manage" type="button">Manage MFS Requests <span>&rsaquo;</span></button>
        <button class="nav-btn" data-mfs-view-target="settings" type="button">Fee &amp; Rate Settings <span>&rsaquo;</span></button>
        <a class="nav-btn" href="/admin/">Topup &amp; Bundles <span>&rsaquo;</span></a>
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
            <a class="btn ghost" href="/admin/">Back Dashboard</a>
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

      <div class="mfs-loader hidden" id="mfsPageLoader" role="status" aria-live="polite">
        <span class="mfs-loader-spinner" aria-hidden="true"></span>
        <span id="mfsPageLoaderText">Loading MFS requests...</span>
      </div>

      <section class="mfs-panel-card admin-mfs-create hidden" id="mfsCreateSection" data-mfs-view="create">
        <div class="mfs-panel-head">
          <div>
            <div class="mfs-section-kicker">New Request</div>
            <h2>Create bKash / Nagad Request</h2>
            <p>Create a request for any user or subadmin UID or registered phone number.</p>
          </div>
          <span class="mfs-limit-pill">BDT 500 - 50,000</span>
        </div>

        <form id="mfsCreateForm" class="admin-mfs-create-grid" novalidate>
          <label class="mfs-field">
            <span>User / Subadmin UID or Phone</span>
            <input class="input" id="mfsCreateUid" required placeholder="Enter UID or registered phone">
            <small class="mfs-field-help">Balance will be held from this target account.</small>
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
            <span>Amount RM <small>MY target optional</small></span>
            <input class="input" id="mfsCreateAmountRm" type="number" min="0" step="0.01" placeholder="Auto / optional">
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
            <button class="btn brand" id="mfsCreateSubmitBtn" type="submit">Create Request</button>
          </div>
          <div class="admin-mfs-preview" id="mfsCreatePreview">
            Enter target, provider and amount. You will review the fee before confirming.
          </div>
        </form>
      </section>

      <section class="mfs-panel-card admin-mfs-settings hidden" id="mfsSettingsSection" data-mfs-view="settings">
        <div class="mfs-panel-head">
          <div>
            <div class="mfs-section-kicker">Admin Only</div>
            <h2>MFS Fee &amp; Rate Settings</h2>
            <p>Configure MYR/BDT conversion and bKash/Nagad MFS fees.</p>
          </div>
          <span class="mfs-limit-pill">Saved in MFS_SETTINGS</span>
        </div>

        <form id="mfsSettingsForm" class="admin-mfs-settings-grid" novalidate>
          <label class="mfs-field">
            <span>MYR to BDT Rate</span>
            <input class="input" id="mfsRateMyrBdt" type="number" min="0.01" step="0.01" placeholder="31.00">
          </label>

          <div class="admin-mfs-fee-card admin-mfs-my-fee-card">
            <h3>Malaysia bKash Role Fee</h3>
            <div class="admin-mfs-fee-grid admin-mfs-role-fees">
              <label class="mfs-field"><span>USER Fee RM</span><input class="input" id="mfsMyBkashUserFee" type="number" min="0" step="0.01" placeholder="5.00"></label>
              <label class="mfs-field"><span>RETAILER Fee RM</span><input class="input" id="mfsMyBkashRetailerFee" type="number" min="0" step="0.01" placeholder="2.00"></label>
              <label class="mfs-field"><span>SUBADMIN Fee RM</span><input class="input" id="mfsMyBkashSubadminFee" type="number" min="0" step="0.01" placeholder="2.00"></label>
            </div>
          </div>

          <div class="admin-mfs-fee-card admin-mfs-my-fee-card">
            <h3>Malaysia Nagad Role Fee</h3>
            <div class="admin-mfs-fee-grid admin-mfs-role-fees">
              <label class="mfs-field"><span>USER Fee RM</span><input class="input" id="mfsMyNagadUserFee" type="number" min="0" step="0.01" placeholder="5.00"></label>
              <label class="mfs-field"><span>RETAILER Fee RM</span><input class="input" id="mfsMyNagadRetailerFee" type="number" min="0" step="0.01" placeholder="2.00"></label>
              <label class="mfs-field"><span>SUBADMIN Fee RM</span><input class="input" id="mfsMyNagadSubadminFee" type="number" min="0" step="0.01" placeholder="2.00"></label>
            </div>
          </div>

          <div class="admin-mfs-fee-card">
            <h3>Bangladesh bKash Fee</h3>
            <div class="admin-mfs-fee-grid">
              <label class="mfs-field"><span>Type</span><select class="input" id="mfsBdBkashType"><option value="fixed">Fixed</option><option value="percent">Percent</option></select></label>
              <label class="mfs-field"><span>Fixed BDT</span><input class="input" id="mfsBdBkashFixed" type="number" min="0" step="0.01"></label>
              <label class="mfs-field"><span>Percent</span><input class="input" id="mfsBdBkashPercent" type="number" min="0" step="0.01"></label>
              <label class="mfs-field"><span>Min Fee</span><input class="input" id="mfsBdBkashMin" type="number" min="0" step="0.01"></label>
              <label class="mfs-field"><span>Max Fee</span><input class="input" id="mfsBdBkashMax" type="number" min="0" step="0.01"></label>
            </div>
          </div>

          <div class="admin-mfs-fee-card">
            <h3>Bangladesh Nagad Fee</h3>
            <div class="admin-mfs-fee-grid">
              <label class="mfs-field"><span>Type</span><select class="input" id="mfsBdNagadType"><option value="fixed">Fixed</option><option value="percent">Percent</option></select></label>
              <label class="mfs-field"><span>Fixed BDT</span><input class="input" id="mfsBdNagadFixed" type="number" min="0" step="0.01"></label>
              <label class="mfs-field"><span>Percent</span><input class="input" id="mfsBdNagadPercent" type="number" min="0" step="0.01"></label>
              <label class="mfs-field"><span>Min Fee</span><input class="input" id="mfsBdNagadMin" type="number" min="0" step="0.01"></label>
              <label class="mfs-field"><span>Max Fee</span><input class="input" id="mfsBdNagadMax" type="number" min="0" step="0.01"></label>
            </div>
          </div>

          <div class="admin-mfs-submit admin-mfs-settings-actions">
            <button class="btn brand" id="mfsSettingsSaveBtn" type="submit">Save Settings</button>
            <button class="btn ghost" id="mfsSettingsReloadBtn" type="button">Reload</button>
          </div>
        </form>
      </section>

      <section class="mfs-panel-card" id="mfsManageSection" data-mfs-view="manage">
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
            <button class="admin-mfs-tab" data-mfs-tab="failed" type="button">Failed</button>
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

    <div class="admin-mfs-modal hidden" id="mfsConfirmModal" role="dialog" aria-modal="true" aria-labelledby="mfsConfirmTitle">
      <div class="admin-mfs-modal-card admin-mfs-compact-modal">
        <div class="mfs-section-kicker" id="mfsConfirmKicker">Confirm Action</div>
        <h2 id="mfsConfirmTitle">Confirm MFS Action</h2>
        <p id="mfsConfirmMessage"></p>
        <label class="hidden" id="mfsConfirmInputWrap" for="mfsConfirmInput">
          Failure message
          <textarea class="input" id="mfsConfirmInput" rows="4" placeholder="MFS request failed"></textarea>
        </label>
        <div class="admin-mfs-modal-actions">
          <button class="btn ghost" id="mfsConfirmCancelBtn" type="button">Cancel</button>
          <button class="btn brand" id="mfsConfirmSaveBtn" type="button">Confirm</button>
        </div>
      </div>
    </div>

    <div class="admin-mfs-modal hidden" id="mfsCreateReviewModal" role="dialog" aria-modal="true" aria-labelledby="mfsCreateReviewTitle">
      <div class="admin-mfs-modal-card">
        <div class="mfs-section-kicker">Review Request</div>
        <h2 id="mfsCreateReviewTitle">Confirm Send Money</h2>
        <p>Please review the details before confirming.</p>
        <div class="admin-mfs-review-grid" id="mfsCreateReviewDetails"></div>
        <div class="admin-mfs-modal-actions">
          <button class="btn ghost" id="mfsCreateReviewCancelBtn" type="button">Back / Edit</button>
          <button class="btn brand" id="mfsCreateReviewConfirmBtn" type="button">Confirm Send Money</button>
        </div>
      </div>
    </div>

    <div class="admin-mfs-modal hidden" id="mfsViewModal" role="dialog" aria-modal="true" aria-labelledby="mfsViewTitle">
      <div class="admin-mfs-modal-card">
        <div class="mfs-section-kicker">Request Details</div>
        <h2 id="mfsViewTitle">MFS Request</h2>
        <pre class="admin-mfs-details" id="mfsViewDetails"></pre>
        <div class="admin-mfs-receipt-actions hidden" id="mfsViewReceiptActions">
          <a class="btn green" id="mfsViewReceiptOpen" href="#" target="_blank" rel="noopener">View Receipt</a>
          <button class="btn ghost" id="mfsViewReceiptCopy" type="button">Copy Receipt Link</button>
        </div>
        <div class="admin-mfs-modal-actions">
          <button class="btn blue" id="mfsViewCloseBtn" type="button">Close</button>
        </div>
      </div>
    </div>

    <div class="admin-mfs-modal hidden" id="mfsFeedbackModal" role="dialog" aria-modal="true" aria-labelledby="mfsFeedbackTitle">
      <div class="admin-mfs-modal-card admin-mfs-compact-modal" id="mfsFeedbackCard">
        <span class="mfs-modal-indicator" id="mfsFeedbackIndicator" aria-hidden="true"></span>
        <div class="mfs-section-kicker" id="mfsFeedbackKicker">Success</div>
        <h2 id="mfsFeedbackTitle">Action completed</h2>
        <p id="mfsFeedbackMessage"></p>
        <pre class="admin-mfs-details hidden" id="mfsFeedbackDetails"></pre>
        <div class="admin-mfs-receipt-actions hidden" id="mfsFeedbackReceiptActions">
          <a class="btn green" id="mfsFeedbackReceiptOpen" href="#" target="_blank" rel="noopener">Open Receipt</a>
          <button class="btn ghost" id="mfsFeedbackReceiptCopy" type="button">Copy Link</button>
        </div>
        <div class="admin-mfs-modal-actions">
          <button class="btn blue" id="mfsFeedbackOkBtn" type="button">OK</button>
        </div>
      </div>
    </div>
  </div>

  <script>
  window.ADMIN_MFS_PROXY_URL = '/api/admin/proxy.php';
  window.ADMIN_DASHBOARD_URL = '/admin/';
  </script>
  <script src="/api/admin/assets/mfs-panel.js?v=7"></script>
</body>
</html>
