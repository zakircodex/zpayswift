<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';

$page = user_page_config([
    'key' => 'transfer',
    'title' => 'Z-Pay Transfer',
    'section_id' => 'transferSection',
    'body_class' => 'user-transfer-page',
    'page_css' => 'transfer-page.css',
    'page_js' => 'transfer-page.js',
    'active_nav' => 'transfer',
    'show_header' => false,
    'show_global_loader' => false,
]);
$transferTrackingBase = function_exists('app_api_url')
    ? app_api_url('transfer/receipt.php')
    : 'https://zpayswift.com/api/transfer/receipt.php';

user_page_begin($page);
?>
<section id="transferSection" class="page-section transfer-page-section active" aria-labelledby="transferPageTitle" data-tracking-base="<?= htmlspecialchars($transferTrackingBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <div class="transfer-page-shell">
    <header class="transfer-page-header">
      <a id="transferBackButton" class="transfer-header-button" href="/user/dashboard" aria-label="Go back">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h2 id="transferPageTitle">Z-Pay Transfer</h2>
      <a class="transfer-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <div class="transfer-scroll-body" aria-live="polite">
      <div id="transferStepReceiver" class="transfer-step active">
        <div class="transfer-step-card transfer-receiver-card">
          <div class="step-copy transfer-step-title"><h3>Receiver Account</h3></div>
          <label class="feature-field transfer-field" for="transferReceiverInput">
            <span>Z-Pay Phone Number</span>
            <div class="transfer-input-shell">
              <span class="transfer-input-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h10V5H7Zm3 12h4v1h-4v-1Z"/></svg></span>
              <input id="transferReceiverInput" type="tel" inputmode="tel" autocomplete="tel" placeholder="Phone number">
            </div>
          </label>
          <button id="transferResolveBtn" class="android-primary-button transfer-primary-button" type="button">Continue</button>
        </div>

        <div class="transfer-favorite-panel">
          <div class="transfer-panel-heading">
            <h3>Favorite Account</h3>
            <button id="transferFavoriteRefreshBtn" class="transfer-mini-button" type="button" aria-label="Refresh favorite accounts">Refresh</button>
          </div>
          <div id="transferFavoriteList" class="transfer-favorite-list" aria-live="polite">
            <div class="transfer-empty-card">No favorite accounts yet.</div>
          </div>
        </div>
      </div>

      <div id="transferStepAmount" class="transfer-step">
        <div class="transfer-step-card transfer-amount-card">
          <div class="step-copy transfer-step-title"><h3>Transfer Amount</h3></div>
          <div id="transferReceiverCard" class="transfer-verified-card"></div>
          <label class="transfer-field" for="transferAmountInput">
            <span>Amount</span>
            <div class="transfer-money-wrap"><b id="transferCurrencyPrefix">BDT</b><input id="transferAmountInput" type="number" inputmode="decimal" min="1" step="0.01" autocomplete="off" placeholder="0.00"></div>
          </label>
          <p id="transferMinimumHint" class="transfer-minimum-hint">Minimum transfer amount is 1.00 BDT.</p>
          <button id="transferAmountNextBtn" class="transfer-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="transferStepPin" class="transfer-step">
        <div class="transfer-step-card transfer-verify-card">
          <div class="step-copy transfer-step-title"><h3>Verify to Continue</h3></div>
          <div id="transferVerifySummary" class="transfer-verify-summary"></div>
          <div class="transfer-verification-area">
            <p>Secure verification</p>
            <span>Confirm this transfer with your transaction PIN.</span>
          </div>
          <label class="transfer-field" for="transferPinInput"><span>Enter PIN</span><input id="transferPinInput" type="password" inputmode="numeric" autocomplete="off" maxlength="4" placeholder="PIN"></label>
          <button id="transferPreviewBtn" class="transfer-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="transferStepReview" class="transfer-step">
        <div class="transfer-step-card transfer-preview-card">
          <div class="step-copy transfer-step-title"><h3>Z-Pay Transfer Preview</h3></div>
          <div id="transferReviewRows" class="review-rows"></div>
          <label class="transfer-field transfer-review-reference" for="transferReferenceInput"><span>Reference <small>Optional</small></span><input id="transferReferenceInput" maxlength="80" autocomplete="off" placeholder="Enter reference (optional)"></label>
          <button id="transferHoldConfirmBtn" class="transfer-hold-control transfer-hold-button" type="button" aria-label="Tap and hold to confirm transfer">
            <span class="transfer-hold-progress" aria-hidden="true"></span>
            <span class="transfer-hold-progress-dot" aria-hidden="true"></span>
            <span class="transfer-hold-bubble transfer-hold-bubble-one" aria-hidden="true"></span>
            <span class="transfer-hold-bubble transfer-hold-bubble-two" aria-hidden="true"></span>
            <img class="transfer-hold-logo" src="/assets/brand/zpay-icon.png" alt="" draggable="false">
            <span class="transfer-hold-label">Tap and hold to confirm transfer</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<?php user_page_end($page); ?>
