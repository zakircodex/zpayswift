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
]);

user_page_begin($page);
?>
<section id="transferSection" class="page-section transfer-page-section active" aria-labelledby="transferPageTitle">
  <div class="transfer-page-shell">
    <header class="transfer-page-header">
      <a class="transfer-header-button" href="/user/dashboard" aria-label="Back to dashboard">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h2 id="transferPageTitle">Z-Pay Transfer</h2>
      <a class="transfer-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <div class="feature-card transfer-card">
      <div class="android-stepper transfer-progress" aria-label="Transfer progress">
        <span id="transferPill1" class="active">Receiver</span>
        <span id="transferPill2">Amount</span>
        <span id="transferPill3">PIN</span>
        <span id="transferPill4">Review</span>
      </div>

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
        <div id="transferReceiverCard" class="recipient-card transfer-verified-card"></div>
        <label class="feature-field transfer-field" for="transferAmountInput">
          <span>Amount</span>
          <div class="transfer-money-wrap"><b id="transferCurrencyPrefix">BDT</b><input id="transferAmountInput" type="number" inputmode="decimal" min="1" step="0.01" placeholder="0.00"></div>
        </label>
        <div class="feature-actions transfer-actions transfer-single-action"><button id="transferAmountNextBtn" class="android-primary-button" type="button">Continue</button></div>
      </div>

      <div id="transferStepPin" class="transfer-step">
        <div class="transfer-step-card">
          <div class="step-copy transfer-step-title"><h3>Transaction PIN</h3><p>Enter your PIN to prepare a secure preview.</p></div>
          <label class="feature-field transfer-field" for="transferPinInput"><span>4-digit PIN</span><input id="transferPinInput" type="password" inputmode="numeric" autocomplete="off" maxlength="4" placeholder="...."></label>
          <div class="feature-actions transfer-actions transfer-single-action"><button id="transferPreviewBtn" class="android-primary-button" type="button">Review Transfer</button></div>
        </div>
      </div>

      <div id="transferStepReview" class="transfer-step">
        <div class="review-panel transfer-review-panel">
          <h3>Review Transfer</h3>
          <div id="transferReviewRows" class="review-rows"></div>
          <label class="feature-field transfer-field transfer-review-reference" for="transferReferenceInput"><span>Reference <small>Optional</small></span><input id="transferReferenceInput" maxlength="80" placeholder="Enter reference (optional)"></label>
        </div>
        <p class="hold-hint">Tap and hold to confirm transfer</p>
        <button id="transferHoldConfirmBtn" class="hold-confirm-button transfer-hold-button" type="button" aria-label="Tap and hold to confirm transfer">
          <span class="hold-confirm-progress" aria-hidden="true"></span>
          <span class="hold-confirm-label">Tap and hold to confirm transfer</span>
        </button>
        <button class="android-secondary-button full-width" type="button" data-transfer-back="3">Edit Transfer</button>
      </div>
    </div>
  </div>
</section>

<?php user_page_end($page); ?>
