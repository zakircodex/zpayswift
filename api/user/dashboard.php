<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title>Z-Pay Swift User Dashboard</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/api/user/assets/dashboard.css?v=12">
  <link rel="stylesheet" href="/api/user/assets/dashboard-ux.css?v=10">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
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
    <aside id="sidebar" class="sidebar">
      <div class="sidebar-brand">
        <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
        <div>
          <h3>Z-Pay Swift<br>User</h3>
          <p>Wallet, topup, bundles, bKash/Nagad and request history</p>
        </div>
      </div>

      <div class="sidebar-title">Menu</div>

      <div class="side-nav">
        <button class="side-btn active" data-page-section="overviewSection" type="button">
          <span>Dashboard</span>
          <span>›</span>
        </button>

        <button class="side-btn" data-page-section="topupSection" type="button">
          <span>Topup</span>
          <span>›</span>
        </button>

        <button class="side-btn" data-page-section="bundleSection" type="button">
          <span>Bundle Offers</span>
          <span>›</span>
        </button>
        
        <button class="side-btn" data-page-section="mfsSection" type="button">
              <span>bKash / Nagad</span>
              <span>›</span>
              </button>

        <button class="side-btn" data-page-section="historySection" type="button">
          <span>My History</span>
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

      <div class="sidebar-footer">
        <button id="sidebarRefreshBtn" class="btn blue full-btn" type="button">Refresh</button>
        <button id="sidebarLogoutBtn" class="btn ghost full-btn" type="button">Logout</button>
      </div>
    </aside>

    <main class="main-panel">
      <div class="mobile-header">
        <div class="mobile-top-card">
          <div class="mobile-top-row">
            <button id="openSidebarBtn" class="icon-btn" type="button">☰</button>
            <div class="mobile-title">Z-Pay Swift</div>
            <button id="quickRefreshBtn" class="icon-btn" type="button">↻</button>
          </div>
        </div>
      </div>

      <div class="hero-card">
        <div class="hero-top desktop-only">
          <div class="status-chip">
            <span class="status-dot"></span>
            <span id="heroStatusText">ACTIVE</span>
          </div>

          <button id="desktopRefreshBtn" class="icon-btn icon-btn-sm" type="button">↻</button>
        </div>

        <div class="hero-balance-label">Available Balance</div>
        <div class="hero-balance"><span id="heroBalancePrefix">BDT</span> <span id="heroBalance">0.00</span></div>

        <div class="hero-grid">
          <div class="hero-mini">
            <div class="hero-mini-label">Hold Balance</div>
            <div class="hero-mini-value"><span id="heroHoldPrefix">BDT</span> <span id="heroHold">0.00</span></div>
          </div>

          <div class="hero-mini">
            <div class="hero-mini-label">This Month Requests</div>
            <div class="hero-mini-value" id="heroRequests">0</div>
          </div>

          <div class="hero-mini">
            <div class="hero-mini-label">Account Type</div>
            <div class="hero-mini-value" id="heroRole">USER</div>
          </div>

          <div class="hero-mini">
            <div class="hero-mini-label">Services</div>
            <div class="hero-mini-value">
              <span id="heroTopupAccess">Topup: No</span><br>
              <span id="heroBundleAccess">Bundle: No</span>
            </div>
          </div>
        </div>
      </div>

      <section id="overviewSection" class="page-section active">
        <div class="summary-card">
          <div class="section-head">
            <div>
              <h3 class="section-title">Account Summary</h3>
              <p class="section-sub">Your profile, wallet and access information</p>
            </div>
          </div>

          <div class="summary-grid">
            <div class="summary-box"><label>Name</label><strong id="meName">-</strong></div>
            <div class="summary-box"><label>Phone</label><strong id="mePhone">-</strong></div>
            <div class="summary-box"><label>Email</label><strong id="meEmail">-</strong></div>
            <div class="summary-box"><label>Role</label><strong id="meRole">-</strong></div>
            <div class="summary-box"><label>Status</label><strong id="meStatus">-</strong></div>
            <div class="summary-box"><label>Last Login</label><strong id="meLastLogin">-</strong></div>
            <div class="summary-box"><label>Commission / 1000</label><strong id="meCommission">0.00</strong></div>
            <div class="summary-box"><label>API Enabled</label><strong id="meApiEnabled">No</strong></div>
            <div class="summary-box"><label>Topup Enabled</label><strong id="meTopupEnabled">No</strong></div>
            <div class="summary-box"><label>Bundle Enabled</label><strong id="meBundleEnabled">No</strong></div>
            <div class="summary-box"><label>Amount Limits</label><strong id="meAmountLimits">0.00 - 0.00</strong></div>
            <div class="summary-box"><label>Wallet Updated</label><strong id="meWalletUpdated">-</strong></div>
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
    <button class="bottom-btn active" data-page-section="overviewSection" type="button">Dashboard</button>
    <button class="bottom-btn" data-page-section="topupSection" type="button">Topup</button>
    <button class="bottom-btn" data-page-section="bundleSection" type="button">Bundle</button>
    
    <button class="bottom-btn" data-page-section="mfsSection" type="button">Money</button>
    
    <button class="bottom-btn" data-page-section="historySection" type="button">History</button>
  </div>
</div>

<script>
window.USER_PROXY_URL = '/api/user/proxy.php';
window.USER_LOGIN_URL = '/user/';
</script>
<script src="/api/user/assets/dashboard.js?v=19"></script>
<script src="/api/user/assets/dashboard-ux.js?v=12"></script>
</body>
</html>
