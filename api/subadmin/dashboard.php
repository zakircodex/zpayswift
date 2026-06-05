<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_name('zawtopup_subadmin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['subadmin_session_token']) || empty($_SESSION['subadmin_user'])) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Z-Pay Swift Subadmin Panel</title>
  <link rel="stylesheet" href="assets/subadmin.css?v=17">
</head>
<body>

<div id="loginView" class="hidden" style="display:none;"></div>

<div id="appView">
  <div class="wrap">
    <div class="app-shell">

      <aside class="sidebar">
        <div class="sidebar-brand">
          <div class="logo">S</div>
          <div>
            <h3>Z-Pay Swift<br>Subadmin</h3>
            <p>API keys, users, wallet tools and request monitoring</p>
          </div>
        </div>

        <div class="sidebar-title">Main</div>

        <div class="side-nav">
          <button class="side-btn active" data-page-section="overviewSection">
            <span>Dashboard</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="bundleOffersSection">
            <span>Bundle Offers</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="panelTopupSection">
            <span>Panel Topup</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="mfsCreateSection">
            <span>bKash / Nagad Create</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="mfsRequestsSection">
            <span>My MFS Requests</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="apiKeysSection">
            <span>API Keys</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="requestLogsSection">
            <span>Request Logs</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="usersSection">
            <span>Users</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="createUserSection">
            <span>Create User</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="integrationGuideSection">
            <span>Integration Guide</span>
            <span>›</span>
          </button>

          <button class="side-btn" data-page-section="apiTestSection">
            <span>API Test</span>
            <span>›</span>
          </button>
        </div>

        <div class="side-card">
          <label>Logged in as</label>
          <strong id="sideMeName">-</strong>
        </div>

        <div class="side-card">
          <label>Role</label>
          <strong id="sideMeRole">-</strong>
        </div>

        <div class="side-card">
          <label>Status</label>
          <strong id="sideMeStatus">-</strong>
        </div>
      </aside>

      <main class="main-panel">

        <div class="topbar">
          <div>
            <h2>Subadmin Dashboard</h2>
            <p>Own balance, API keys, wallet tools, users and request history</p>
          </div>
          <div class="actions">
            <button class="btn blue" id="refreshBtn">Refresh</button>
            <button class="btn ghost" id="logoutBtn">Logout</button>
          </div>
        </div>

        <div id="overviewSection" class="page-section active">
          <div class="grid">
            <div class="card">
              <div class="metric-title">Available Balance</div>
              <div class="metric-value" id="availableBalance">0.00</div>
              <div class="metric-sub">Spendable wallet balance</div>
            </div>

            <div class="card">
              <div class="metric-title">Hold Balance</div>
              <div class="metric-value" id="holdBalance">0.00</div>
              <div class="metric-sub">Reserved for pending requests</div>
            </div>

            <div class="card">
              <div class="metric-title">API Keys</div>
              <div class="metric-value" id="apiKeyCount">0</div>
              <div class="metric-sub">Active / disabled / revoked</div>
            </div>

            <div class="card">
              <div class="metric-title">Request Logs</div>
              <div class="metric-value" id="requestLogCount">0</div>
              <div class="metric-sub">Recent request activity</div>
            </div>
          </div>

          <div class="dashboard-bottom-grid">
            <div class="card compact-summary-card">
              <h3>Account Summary</h3>
              <p>Profile, permissions and wallet information</p>

              <div class="info-grid compact-info-grid">
                <div class="box"><label>Name</label><strong id="meName">-</strong></div>
                <div class="box"><label>Phone</label><strong id="mePhone">-</strong></div>
                <div class="box"><label>Email</label><strong id="meEmail">-</strong></div>
                <div class="box"><label>Role</label><strong id="meRole">-</strong></div>
                <div class="box"><label>Status</label><strong id="meStatus">-</strong></div>
                <div class="box"><label>Last Login</label><strong id="meLastLogin">-</strong></div>
                <div class="box"><label>Commission / 1000</label><strong id="meCommission">0.00</strong></div>
                <div class="box"><label>API Enabled</label><strong id="meApiEnabled">No</strong></div>
                <div class="box"><label>Topup Enabled</label><strong id="meTopupEnabled">No</strong></div>
                <div class="box"><label>Bundle Enabled</label><strong id="meBundleEnabled">No</strong></div>
                <div class="box"><label>Amount Limits</label><strong id="meAmountLimits">0.00 - 0.00</strong></div>
                <div class="box"><label>Wallet Updated</label><strong id="meWalletUpdated">-</strong></div>
              </div>
            </div>

            <div class="card request-chart-card">
              <div class="chart-head">
                <div>
                  <h3>Request Performance</h3>
                  <p>Success, failed and pending request summary</p>
                </div>
              </div>

              <div class="chart-metrics">
                <div class="chart-stat success">
                  <label>Success</label>
                  <strong id="chartSuccessCount">0</strong>
                </div>
                <div class="chart-stat danger">
                  <label>Failed</label>
                  <strong id="chartFailedCount">0</strong>
                </div>
                <div class="chart-stat warning">
                  <label>Pending</label>
                  <strong id="chartPendingCount">0</strong>
                </div>
              </div>

              <div class="mini-bar-chart">
                <div class="bar-row">
                  <div class="bar-label">Success</div>
                  <div class="bar-track">
                    <div id="chartSuccessBar" class="bar-fill success" style="width:0%"></div>
                  </div>
                  <div id="chartSuccessPercent" class="bar-percent">0%</div>
                </div>

                <div class="bar-row">
                  <div class="bar-label">Failed</div>
                  <div class="bar-track">
                    <div id="chartFailedBar" class="bar-fill danger" style="width:0%"></div>
                  </div>
                  <div id="chartFailedPercent" class="bar-percent">0%</div>
                </div>

                <div class="bar-row">
                  <div class="bar-label">Pending</div>
                  <div class="bar-track">
                    <div id="chartPendingBar" class="bar-fill warning" style="width:0%"></div>
                  </div>
                  <div id="chartPendingPercent" class="bar-percent">0%</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="bundleOffersSection" class="page-section">
          <div class="card">
            <div class="topbar mb-14">
              <div>
                <h3>Bundle Offers</h3>
                <p>Choose a bundle offer and create a bundle request directly from your panel</p>
              </div>
              <div class="actions">
                <button class="btn blue" id="loadBundleOffersBtn">Refresh Offers</button>
              </div>
            </div>

            <div id="bundleOffersGrid" class="bundle-offer-grid">
              <div class="bundle-empty-card">
                <strong>No bundle offers loaded yet</strong>
                <span>Click Refresh Offers to load active bundle offers.</span>
              </div>
            </div>
          </div>
        </div>

        <div id="panelTopupSection" class="page-section">
          <div class="panel">
            <div class="card">
              <h3>Create Topup</h3>
              <p>Create a topup request directly from the subadmin panel</p>

              <div class="test-grid">
                <div class="field">
                  <label>Topup Number</label>
                  <input id="panelTopupNumber" class="input" placeholder="01712345678">
                </div>

                <div class="field">
                  <label>Operator</label>
                  <select id="panelTopupOperator" class="input">
                    <option value="GP">GP</option>
                    <option value="ROBI">ROBI</option>
                    <option value="BL">BL</option>
                    <option value="AIRTEL">AIRTEL</option>
                    <option value="TT">TT</option>
                  </select>
                </div>

                <div class="field">
                  <label>Amount</label>
                  <input id="panelTopupAmount" class="input" type="number" step="0.01" min="1" value="20">
                </div>

                <div class="field">
                  <label>Note</label>
                  <input id="panelTopupNote" class="input" value="Panel topup request">
                </div>
              </div>

              <div class="actions mt-10">
                <button class="btn green" id="sendPanelTopupBtn">Create Topup</button>
                <button class="btn ghost" id="clearPanelTopupBtn">Clear</button>
              </div>

              <div class="box mt-14">
                <label>Result</label>
                <div id="panelTopupOutput" class="status-box-clean">No panel topup created yet.</div>
              </div>
            </div>

            <div class="card">
              <h3>Recent Panel Topups</h3>
              <p>Latest requests created directly from this panel</p>

              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Request ID</th>
                      <th>Status</th>
                      <th>Operator</th>
                      <th>Number</th>
                      <th>Amount</th>
                      <th>Created</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody id="panelTopupTableBody">
                    <tr><td colspan="7" class="muted">No panel topup yet.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div id="mfsCreateSection" class="page-section">
          <div class="mfs-panel-layout mfs-create-only">
            <div class="card mfs-create-card">
              <div class="topbar mb-14">
                <div>
                  <h3>bKash / Nagad Create</h3>
                  <p>Create SEND MONEY request from your own subadmin wallet</p>
                </div>
                <div class="actions">
                  <button class="btn blue" id="subMfsRefreshBtn" type="button">Refresh Summary</button>
                </div>
              </div>

              <div class="mfs-summary-grid">
                <div class="mfs-summary-card pending">
                  <label>Pending</label>
                  <strong id="subMfsSummaryPending">0</strong>
                  <span>Waiting admin</span>
                </div>
                <div class="mfs-summary-card processing">
                  <label>Processing</label>
                  <strong id="subMfsSummaryProcessing">0</strong>
                  <span>In progress</span>
                </div>
                <div class="mfs-summary-card done">
                  <label>Done</label>
                  <strong id="subMfsSummaryDone">0</strong>
                  <span>Successful</span>
                </div>
                <div class="mfs-summary-card failed">
                  <label>Failed</label>
                  <strong id="subMfsSummaryFailed">0</strong>
                  <span>Refunded</span>
                </div>
              </div>

              <div class="sub-mfs-create-grid mt-14">
                <div class="field">
                  <label>Provider</label>
                  <select id="subMfsProvider" class="input">
                    <option value="BKASH">bKash</option>
                    <option value="NAGAD">Nagad</option>
                  </select>
                </div>

                <div class="field">
                  <label>Receiver Number</label>
                  <input id="subMfsReceiver" class="input" inputmode="numeric" placeholder="01XXXXXXXXX">
                </div>

                <div class="field">
                  <label>Amount BDT</label>
                  <input id="subMfsAmountBdt" class="input" type="number" step="0.01" min="500" max="50000" placeholder="500 - 50000">
                </div>

                <div class="field" id="subMfsAmountRmField">
                  <label>Amount RM <span class="muted">MY optional</span></label>
                  <input id="subMfsAmountRm" class="input" type="number" step="0.01" min="0" placeholder="Optional for Malaysia wallet">
                </div>

                <div class="field">
                  <label>Transaction PIN</label>
                  <input id="subMfsPin" class="input" type="password" placeholder="Your account PIN">
                </div>

                <div class="field">
                  <label>Reference <span class="muted">Optional</span></label>
                  <input id="subMfsReference" class="input" placeholder="Reference or memo">
                </div>

                <div class="field">
                  <label>Note <span class="muted">Optional</span></label>
                  <input id="subMfsNote" class="input" placeholder="Request note">
                </div>
              </div>

              <p class="muted tiny-note mt-10">Minimum BDT 500 and maximum BDT 50,000 per request. Failed requests refund the same subadmin wallet.</p>

              <div class="actions mt-10">
                <button class="btn blue" id="subMfsPreviewBtn" type="button">Preview Fee</button>
                <button class="btn green" id="subMfsCreateBtn" type="button">Create MFS Request</button>
                <button class="btn ghost" id="subMfsClearBtn" type="button">Clear</button>
              </div>

              <div class="box mt-14 hidden" aria-hidden="true">
                <label>Status</label>
                <div id="subMfsOutput" class="status-box-clean">No MFS request created yet.</div>
              </div>
            </div>
          </div>
        </div>

        <div id="mfsRequestsSection" class="page-section">
          <div class="mfs-panel-layout mfs-list-only">
            <div class="card">
              <div class="topbar mb-14">
                <div>
                  <h3>My MFS Requests</h3>
                  <p>View your own bKash/Nagad requests. Status changes are handled from the Admin panel.</p>
                </div>
                <div class="actions sub-mfs-toolbar">
                  <button class="btn blue" id="subMfsListRefreshBtn" type="button">Refresh</button>
                  <div class="actions sub-mfs-tabs">
                    <button class="btn green sub-mfs-tab active" type="button" data-mfs-tab="pending">Pending</button>
                    <button class="btn ghost sub-mfs-tab" type="button" data-mfs-tab="processing">Processing</button>
                    <button class="btn ghost sub-mfs-tab" type="button" data-mfs-tab="done">Done / Success</button>
                    <button class="btn ghost sub-mfs-tab" type="button" data-mfs-tab="failed">Failed</button>
                  </div>
                </div>
              </div>

              <div class="sub-mfs-filter-row mb-16">
                <input id="subMfsSearch" class="input" placeholder="Search request, number, provider">
                <input id="subMfsNumberFilter" class="input" placeholder="Receiver number filter">
                <select id="subMfsProviderFilter" class="input">
                  <option value="">All Providers</option>
                  <option value="BKASH">bKash</option>
                  <option value="NAGAD">Nagad</option>
                </select>
                <button class="btn blue" id="subMfsApplyFilterBtn" type="button">Apply Filter</button>
              </div>

              <div class="table-wrap sub-mfs-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Request ID</th>
                      <th>Provider</th>
                      <th>Receiver</th>
                      <th>Amount</th>
                      <th>Fee / Pay</th>
                      <th>Status</th>
                      <th>Created</th>
                      <th>Reference</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody id="subMfsTableBody">
                    <tr><td colspan="9" class="muted">No MFS request loaded yet.</td></tr>
                  </tbody>
                </table>
              </div>

              <div id="subMfsMobileList" class="sub-mfs-mobile-list"></div>

              <div class="box mt-14">
                <label>Details</label>
                <div id="subMfsDetailsOutput" class="status-box-clean">Select a request to view details.</div>
              </div>
            </div>
          </div>
        </div>

        <div id="apiKeysSection" class="page-section">
          <div class="card">
            <h3>API Keys</h3>
            <p>Create and manage your API keys</p>

            <div class="actions mb-16">
              <button class="btn green" id="createKeyBtn">Generate API Key</button>
              <button class="btn blue" id="reloadKeysBtn">Reload Keys</button>
              <button class="btn blue" id="reloadLogsBtn">Reload Logs</button>
            </div>

            <div class="box mb-16">
              <label>Last Created Full Key</label>
              <strong id="lastPlainKey" class="break-14">-</strong>

              <div class="actions mt-12">
                <button class="btn blue mini-main-btn" id="copyPlainKeyBtn">Copy Plain Key</button>
                <button class="btn ghost mini-main-btn" id="usePlainKeyBtn">Use In Live Test</button>
              </div>

              <div class="muted tiny-note mt-10">
                Full plain key is shown only when newly created. Old masked keys cannot be used as real API keys.
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Key ID</th>
                    <th>Masked Key</th>
                    <th>Status</th>
                    <th>Last Used</th>
                    <th>Created</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="keysTableBody">
                  <tr><td colspan="6" class="muted">No API keys yet.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div id="requestLogsSection" class="page-section">
          <div class="card">
            <div class="topbar mb-14">
              <div>
                <h3>Request Logs</h3>
                <p>Recent API request history</p>
              </div>

              <div class="actions">
                <button class="btn ghost log-filter-btn active" data-log-filter="ALL">All</button>
                <button class="btn ghost log-filter-btn" data-log-filter="PENDING">Pending</button>
                <button class="btn ghost log-filter-btn" data-log-filter="SUCCESS">Success</button>
                <button class="btn ghost log-filter-btn" data-log-filter="FAILED">Failed</button>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Request ID</th>
                    <th>Key ID</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Service</th>
                    <th>Number</th>
                    <th>Amount</th>
                    <th>Created</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="logsTableBody">
                  <tr><td colspan="9" class="muted">No request logs yet.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div id="usersSection" class="page-section">
          <div class="card">
            <h3>Users</h3>
            <p>View your own users and convert user to retailer</p>

            <div class="actions mb-16">
              <select id="usersRoleFilter" class="input filter-input">
                <option value="">All Roles</option>
                <option value="USER">USER</option>
                <option value="RETAILER">RETAILER</option>
              </select>

              <select id="usersStatusFilter" class="input filter-input">
                <option value="">All Status</option>
                <option value="ACTIVE">ACTIVE</option>
                <option value="INACTIVE">INACTIVE</option>
                <option value="DISABLED">DISABLED</option>
              </select>

              <button class="btn blue" id="reloadUsersBtn">Reload Users</button>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Country</th>
                    <th>Balance</th>
                    <th>Limits</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="usersTableBody">
                  <tr><td colspan="9" class="muted">No users loaded yet.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div id="createUserSection" class="page-section">
          <div class="card">
            <h3>Create User</h3>
            <p>Create a new user under this subadmin</p>

            <div class="test-grid">
              <div class="field">
                <label>Name</label>
                <input id="newUserName" class="input" placeholder="Enter full name">
              </div>

              <div class="field">
                <label>Phone</label>
                <input id="newUserPhone" class="input" placeholder="Enter phone number">
              </div>

              <div class="field">
                <label>Email</label>
                <input id="newUserEmail" class="input" placeholder="Enter email">
              </div>

              <div class="field">
                <label>Password</label>
                <input id="newUserPassword" class="input" type="password" placeholder="Enter password">
              </div>

              <div class="field">
                <label>Confirm Password</label>
                <input id="newUserConfirmPassword" class="input" type="password" placeholder="Confirm password">
              </div>

              <div class="field">
                <label>PIN</label>
                <input id="newUserPin" class="input" type="password" placeholder="Enter PIN">
              </div>

              <div class="field">
                <label>Confirm PIN</label>
                <input id="newUserConfirmPin" class="input" type="password" placeholder="Confirm PIN">
              </div>
            </div>

            <div class="actions mt-8">
              <button class="btn green" id="createUserBtn">Create User</button>
              <button class="btn ghost" id="clearCreateUserBtn">Clear</button>
            </div>

            <div class="box mt-14">
              <label>Result</label>
              <div id="createUserOutput" class="status-box-clean">No user created yet.</div>
            </div>
          </div>
        </div>

        <div id="integrationGuideSection" class="page-section">
          <div class="card">
            <h3>Integration Guide</h3>
            <p>Topup API and Bundle API integration flow</p>

            <div class="card mt-14">
              <h3>Topup API Flow</h3>
              <p>Use this API when you want to create a mobile topup request.</p>

              <div class="info-grid">
                <div class="box">
                  <label>Topup API Endpoint</label>
                  <strong id="guideTopupEndpoint" class="break-14">-</strong>
                  <div class="mt-12">
                    <button class="btn blue mini-main-btn" onclick="copyById('guideTopupEndpoint','Topup endpoint copied')">Copy Endpoint</button>
                  </div>
                </div>

                <div class="box">
                  <label>Authorization Header</label>
                  <strong id="guideTopupAuth" class="break-14">-</strong>
                  <div class="mt-12">
                    <button class="btn blue mini-main-btn" onclick="copyById('guideTopupAuth','Topup auth header copied')">Copy Header</button>
                  </div>
                </div>
              </div>

              <div class="box mt-14">
                <label>Topup JSON Body</label>
                <pre id="guideTopupBody">-</pre>
                <div class="mt-12">
                  <button class="btn blue mini-main-btn" onclick="copyById('guideTopupBody','Topup JSON copied')">Copy JSON</button>
                </div>
              </div>

              <div class="box mt-14">
                <label>Topup cURL Example</label>
                <pre id="guideTopupCurl">-</pre>
                <div class="mt-12">
                  <button class="btn blue mini-main-btn" onclick="copyById('guideTopupCurl','Topup cURL copied')">Copy cURL</button>
                </div>
              </div>
            </div>

            <div class="card mt-14">
              <h3>Bundle API Flow</h3>
              <p>Use this API when you want to create a bundle request using offer_id.</p>

              <div class="info-grid">
                <div class="box">
                  <label>Bundle API Endpoint</label>
                  <strong id="guideBundleEndpoint" class="break-14">-</strong>
                  <div class="mt-12">
                    <button class="btn blue mini-main-btn" onclick="copyById('guideBundleEndpoint','Bundle endpoint copied')">Copy Endpoint</button>
                  </div>
                </div>

                <div class="box">
                  <label>Authorization Header</label>
                  <strong id="guideBundleAuth" class="break-14">-</strong>
                  <div class="mt-12">
                    <button class="btn blue mini-main-btn" onclick="copyById('guideBundleAuth','Bundle auth header copied')">Copy Header</button>
                  </div>
                </div>
              </div>

              <div class="box mt-14">
                <label>Bundle JSON Body</label>
                <pre id="guideBundleBody">-</pre>
                <div class="mt-12">
                  <button class="btn blue mini-main-btn" onclick="copyById('guideBundleBody','Bundle JSON copied')">Copy JSON</button>
                </div>
              </div>

              <div class="box mt-14">
                <label>Bundle cURL Example</label>
                <pre id="guideBundleCurl">-</pre>
                <div class="mt-12">
                  <button class="btn blue mini-main-btn" onclick="copyById('guideBundleCurl','Bundle cURL copied')">Copy cURL</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="apiTestSection" class="page-section">
          <div class="card">
            <div class="topbar mb-14">
              <div>
                <h3>API Test</h3>
                <p>Test Topup API and Bundle API from one place</p>
              </div>
            </div>

            <div class="actions mb-16 api-test-tabs">
              <button class="btn green api-test-tab active" type="button" data-api-test-target="liveApiTestPanel">
                Topup API Test
              </button>
              <button class="btn ghost api-test-tab" type="button" data-api-test-target="bundleApiTestPanel">
                Bundle API Test
              </button>
            </div>

            <div id="liveApiTestPanel" class="api-test-panel active">
              <h3>Topup API Test</h3>
              <p>Test your real topup API request from this panel</p>

              <div class="test-grid">
                <div class="field">
                  <label>Topup Create API Endpoint</label>
                  <input id="liveApiEndpoint" class="input" value="">
                </div>

                <div class="field">
                  <label>Plain API Key</label>
                  <input id="liveApiKey" class="input" placeholder="Paste full plain API key">
                </div>

                <div class="field">
                  <label>Topup Number</label>
                  <input id="liveTopupNumber" class="input" placeholder="01712345678">
                </div>

                <div class="field">
                  <label>Operator</label>
                  <select id="liveOperator" class="input">
                    <option value="GP">GP</option>
                    <option value="ROBI">ROBI</option>
                    <option value="BL">BL</option>
                    <option value="AIRTEL">AIRTEL</option>
                    <option value="TT">TT</option>
                  </select>
                </div>

                <div class="field">
                  <label>Amount</label>
                  <input id="liveAmount" class="input" type="number" step="0.01" min="1" value="20">
                </div>

                <div class="field">
                  <label>Note</label>
                  <input id="liveNote" class="input" value="Live API test from subadmin panel">
                </div>
              </div>

              <div class="actions mt-8">
                <button class="btn green" id="runLiveApiTestBtn">Run Topup API Test</button>
                <button class="btn ghost" id="fillLastPlainKeyBtn">Use Last Created Key</button>
                <button class="btn ghost" id="clearLiveApiOutputBtn">Clear Output</button>
              </div>

              <div class="box mt-14">
                <label>Result</label>
                <div id="liveApiOutput" class="status-box-clean">No test run yet.</div>
              </div>
            </div>

            <div id="bundleApiTestPanel" class="api-test-panel">
              <h3>Bundle API Test</h3>
              <p>Create a real bundle request using public bundle API</p>

              <div class="test-grid">
                <div class="field">
                  <label>Bundle Create API Endpoint</label>
                  <input id="bundleCreateEndpoint" class="input" value="">
                </div>

                <div class="field">
                  <label>Plain API Key</label>
                  <input id="bundleCreateApiKey" class="input" placeholder="Paste full plain API key">
                </div>

                <div class="field">
                  <label>Offer ID</label>
                  <input id="bundleTestOfferId" class="input" placeholder="Select from Bundle Offers or paste offer_id">
                </div>

                <div class="field">
                  <label>Bundle Number</label>
                  <input id="bundleTestNumber" class="input" placeholder="01712345678">
                </div>

                <div class="field">
                  <label>Note</label>
                  <input id="bundleTestNote" class="input" value="Bundle API test from subadmin panel">
                </div>
              </div>

              <div class="actions mt-8">
                <button class="btn green" id="runBundleApiTestBtn">Run Bundle API Test</button>
                <button class="btn ghost" id="clearBundleApiOutputBtn">Clear Output</button>
              </div>

              <div class="box mt-14">
                <label>Result</label>
                <div id="bundleApiOutput" class="status-box-clean">No bundle API test run yet.</div>
              </div>
            </div>
          </div>
        </div>

      </main>
    </div>
  </div>
</div>

<div id="loadingWrap" class="loading">
  <div class="loading-box">
    <div class="spinner"></div>
    <div id="loadingText">Loading...</div>
  </div>
</div>

<div id="panelBundleBuyModalWrap" class="modal-wrap">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3>Buy Bundle</h3>
        <p>Confirm bundle number and create request</p>
      </div>
      <button id="closePanelBundleBuyModalBtn" class="modal-close" type="button">Close</button>
    </div>

    <div class="info-grid mb-14">
      <div class="box">
        <label>Bundle Name</label>
        <strong id="panelBundleOfferName">-</strong>
      </div>

      <div class="box">
        <label>Offer ID</label>
        <strong id="panelBundleOfferId">-</strong>
      </div>

      <div class="box">
        <label>Operator</label>
        <strong id="panelBundleOperator">-</strong>
      </div>

      <div class="box">
        <label>Amount</label>
        <strong id="panelBundleAmount">BDT 0.00</strong>
      </div>

      <div class="box">
        <label>User Commission</label>
        <strong id="panelBundleCommission">BDT 0.00</strong>
      </div>

      <div class="box">
        <label>Net Cost</label>
        <strong id="panelBundleNetCost">BDT 0.00</strong>
      </div>

      <div class="box">
        <label>Expiry</label>
        <strong id="panelBundleExpires">-</strong>
      </div>

      <div class="box">
        <label>Status</label>
        <strong id="panelBundleStatus">-</strong>
      </div>
    </div>

    <div class="field">
      <label>Bundle Number</label>
      <input id="panelBundleNumberInput" class="input" placeholder="01712345678">
    </div>

    <div class="field">
      <label>Note</label>
      <input id="panelBundleNoteInput" class="input" value="Panel bundle request">
    </div>

    <div class="box mt-14">
      <label>Result</label>
      <div id="panelBundleBuyOutput" class="status-box-clean">No bundle request created yet.</div>
    </div>

    <div class="actions mt-14">
      <button id="panelBundleSubmitBtn" class="btn green" type="button">Create Bundle Request</button>
      <button id="panelBundleCancelBtn" class="btn ghost" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="logModalWrap" class="modal-wrap">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3 id="logModalTitle">Request Details</h3>
        <p id="logModalSub">Request summary and details</p>
      </div>
      <button id="closeLogModalBtn" class="modal-close">Close</button>
    </div>

    <div class="info-grid">
      <div class="box"><label>Request ID</label><strong id="logRequestId">-</strong></div>
      <div class="box"><label>Key ID</label><strong id="logKeyId">-</strong></div>
      <div class="box"><label>Type</label><strong id="logType">-</strong></div>
      <div class="box"><label>Status</label><strong id="logStatusText">-</strong></div>
      <div class="box"><label>Operator</label><strong id="logOperator">-</strong></div>
      <div class="box"><label>Number</label><strong id="logNumber">-</strong></div>
      <div class="box"><label>Amount</label><strong id="logAmount">0.00</strong></div>
      <div class="box"><label>Created</label><strong id="logCreated">-</strong></div>
      <div class="box"><label>Updated</label><strong id="logUpdated">-</strong></div>
      <div class="box"><label>Message</label><strong id="logMessage">-</strong></div>
    </div>

    <div class="hidden">
      <pre id="logRawJson">-</pre>
    </div>

    <div class="actions mt-14">
      <button class="btn blue" id="copyLogRequestBtn">Copy Request ID</button>
      <button class="btn ghost" id="closeLogModalBtn2">Close</button>
    </div>
  </div>
</div>

<div id="deductOtpModalWrap" class="modal-wrap">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3>Deduct Balance</h3>
        <p>Send OTP before balance deduction</p>
      </div>
      <button id="closeDeductOtpModalBtn" class="modal-close">Close</button>
    </div>

    <div class="info-grid mb-14">
      <div class="box"><label>Name</label><strong id="deductTargetName">-</strong></div>
      <div class="box"><label>Phone</label><strong id="deductTargetPhone">-</strong></div>
      <div class="box"><label>Available Balance</label><strong id="deductTargetBalance">0.00</strong></div>
      <div class="box"><label>Role</label><strong id="deductTargetRole">-</strong></div>
    </div>

    <div class="field">
      <label>Deduct Amount (BDT)</label>
      <input id="deductAmountInput" class="input" type="number" step="0.01" min="0.01" placeholder="Enter amount">
    </div>

    <div class="field">
      <label>Note</label>
      <input id="deductNoteInput" class="input" placeholder="Reason for deduction">
    </div>

    <div class="actions">
      <button id="sendDeductOtpBtn" class="btn orange">Send OTP</button>
      <button id="resetDeductOtpBtn" class="btn ghost">Reset</button>
    </div>
  </div>
</div>

<div id="deductConfirmModalWrap" class="modal-wrap">
  <div class="modal-card modal-card-sm">
    <div class="modal-head">
      <div>
        <h3>Confirm OTP</h3>
        <p>Enter the OTP sent to the account holder</p>
      </div>
      <button id="closeDeductConfirmModalBtn" class="modal-close">Close</button>
    </div>

    <div class="info-grid mb-14">
      <div class="box"><label>Name</label><strong id="deductConfirmName">-</strong></div>
      <div class="box"><label>Phone</label><strong id="deductConfirmPhone">-</strong></div>
      <div class="box"><label>Amount</label><strong id="deductConfirmAmount">BDT 0.00</strong></div>
      <div class="box"><label>Role</label><strong id="deductConfirmRole">-</strong></div>
    </div>

    <div class="field">
      <label>OTP Code</label>
      <input id="deductOtpCodeInput" class="input" maxlength="6" placeholder="Enter 6 digit OTP">
    </div>

    <div id="deductOtpConfirmStatus" class="status-note info mt-14">
      OTP পাঠানোর পরে এখানে confirmation status দেখাবে।
    </div>

    <div class="actions mt-14">
      <button id="resendDeductOtpBtn" class="btn orange">Resend OTP</button>
      <button id="confirmDeductOtpBtn" class="btn red">Confirm Deduction</button>
      <button id="cancelDeductOtpBtn" class="btn ghost">Cancel</button>
    </div>
  </div>
</div>

<div id="addBalanceModalWrap" class="modal-wrap">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3>Add Balance</h3>
        <p>Add wallet balance to user or retailer</p>
      </div>
      <button id="closeAddBalanceModalBtn" class="modal-close">Close</button>
    </div>

    <div class="info-grid mb-14">
      <div class="box"><label>Name</label><strong id="addBalanceTargetName">-</strong></div>
      <div class="box"><label>Phone</label><strong id="addBalanceTargetPhone">-</strong></div>
      <div class="box"><label>Available Balance</label><strong id="addBalanceTargetBalance">0.00</strong></div>
      <div class="box"><label>Role</label><strong id="addBalanceTargetRole">-</strong></div>
    </div>

    <div class="field">
      <label>Add Amount</label>
      <input id="addBalanceAmountInput" class="input" type="number" step="0.01" min="0.01" placeholder="Enter amount">
    </div>

    <div class="field">
      <label>Note</label>
      <input id="addBalanceNoteInput" class="input" placeholder="Reason for balance add">
    </div>

    <div class="actions">
      <button id="submitAddBalanceBtn" class="btn green">Add Balance</button>
      <button id="cancelAddBalanceBtn" class="btn ghost">Cancel</button>
    </div>

    <div class="box mt-14">
      <label>Status</label>
      <div id="addBalanceStatusBox" class="status-box-clean">No balance add request yet.</div>
    </div>
  </div>
</div>

<div id="walletLedgerModalWrap" class="modal-wrap">
  <div class="modal-card modal-card-wide">
    <div class="modal-head">
      <div>
        <h3>Wallet Ledger</h3>
        <p>Recent wallet credit and debit history</p>
      </div>
      <button id="closeWalletLedgerModalBtn" class="modal-close">Close</button>
    </div>

    <div class="info-grid mb-14">
      <div class="box"><label>Name</label><strong id="ledgerTargetName">-</strong></div>
      <div class="box"><label>Phone</label><strong id="ledgerTargetPhone">-</strong></div>
      <div class="box"><label>Available Balance</label><strong id="ledgerAvailableBalance">0.00</strong></div>
      <div class="box"><label>Hold Balance</label><strong id="ledgerHoldBalance">0.00</strong></div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Direction</th>
            <th>Amount</th>
            <th>Before</th>
            <th>After</th>
            <th>Note</th>
            <th>By</th>
          </tr>
        </thead>
        <tbody id="walletLedgerTableBody">
          <tr><td colspan="8" class="muted">No wallet ledger loaded yet.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="actions mt-14">
      <button id="reloadWalletLedgerBtn" class="btn blue">Reload Ledger</button>
      <button id="closeWalletLedgerModalBtn2" class="btn ghost">Close</button>
    </div>
  </div>
</div>

<div id="actionConfirmModalWrap" class="modal-wrap">
  <div class="confirm-modal-card">
    <h3 id="actionConfirmTitle" class="confirm-modal-title">Confirm Action</h3>
    <div id="actionConfirmText" class="confirm-modal-text">Are you sure?</div>

    <div class="confirm-modal-actions">
      <button id="actionConfirmCancelBtn" class="btn ghost" type="button">Cancel</button>
      <button id="actionConfirmOkBtn" class="btn green" type="button">Confirm</button>
    </div>
  </div>
</div>

<div id="createUserOtpModalWrap" class="modal-wrap">
  <div class="modal-card modal-card-sm">
    <div class="modal-head">
      <div>
        <h3>Create User OTP Verification</h3>
        <p>OTP verify করার পরে নতুন user create হবে</p>
      </div>
      <button id="closeCreateUserOtpModalBtn" class="modal-close" type="button">Close</button>
    </div>

    <div class="info-grid mb-14">
      <div class="box">
        <label>Phone</label>
        <strong id="createUserOtpMaskedPhone">-</strong>
      </div>
      <div class="box">
        <label>Expires In</label>
        <strong id="createUserOtpExpiresText">300 seconds</strong>
      </div>
    </div>

    <div class="field">
      <label>OTP Code</label>
      <input id="createUserOtpCode" class="input" maxlength="6" placeholder="Enter 6 digit OTP">
    </div>

    <div id="createUserOtpStatus" class="status-note info mt-14">
      OTP পাঠানোর পরে এখানে status দেখাবে।
    </div>

    <div class="actions mt-14 auth-actions-stack">
      <button id="verifyCreateUserOtpBtn" class="btn green auth-main-btn" type="button">Verify & Create User</button>
      <button id="resendCreateUserOtpBtn" class="btn orange auth-main-btn" type="button">Resend OTP</button>
      <button id="cancelCreateUserOtpBtn" class="btn ghost auth-main-btn" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="toastWrap" class="toast-wrap"></div>

<script>
window.SUBADMIN_PROXY_URL = 'proxy.php';
</script>
<script src="assets/subadmin.js?v=15"></script>
<script src="assets/subadmin-otp.js?v=3"></script>
</body>
</html>
