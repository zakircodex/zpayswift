<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';

$page = user_page_config([
    'key' => 'topup',
    'title' => 'Mobile Top-Up',
    'section_id' => 'topupSection',
    'body_class' => 'user-topup-page',
    'page_css' => 'topup-page.css',
    'page_js' => 'topup-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_global_loader' => false,
]);

user_page_begin($page);
?>
<section id="topupSection" class="page-section topup-page-section active" aria-labelledby="topupPageTitle">
  <div class="topup-page-shell">
    <header class="topup-page-header">
      <a id="topupBackButton" class="topup-header-button" href="/user/dashboard" aria-label="Go back">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h1 id="topupPageTitle">Mobile Top-Up</h1>
      <a class="topup-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <div id="topupScrollBody" class="topup-scroll-body" aria-live="polite">
      <div id="topupStepNumber" class="topup-step active" data-topup-step="number">
        <div class="topup-step-card topup-number-card">
          <h2>Mobile Top-Up</h2>

          <div class="topup-field-group">
            <span class="topup-field-label">Select Country</span>
            <button id="topupCountryButton" class="topup-country-control" type="button" aria-haspopup="dialog">
              <span id="topupCountryCodeBadge" class="topup-country-code" aria-hidden="true">BD</span>
              <span class="topup-country-copy">
                <strong id="topupCountryName">Bangladesh</strong>
                <small id="topupCountryDialCode">+880</small>
              </span>
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
            </button>
          </div>

          <label class="topup-field-group" for="topupNumberInput">
            <span class="topup-field-label">Mobile Number</span>
            <span class="topup-input-shell">
              <span class="topup-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h10V5H7Zm3 12h4v1h-4v-1Z"/></svg>
              </span>
              <input id="topupNumberInput" type="tel" inputmode="tel" autocomplete="tel" placeholder="Mobile number">
            </span>
          </label>

          <button id="topupNumberContinueButton" class="topup-primary-button" type="button">Continue</button>
        </div>

        <div class="topup-favorite-panel">
          <div class="topup-panel-heading">
            <h2>Favorite Number</h2>
          </div>
          <div id="topupFavoriteList" class="topup-favorite-list" aria-live="polite">
            <div class="topup-empty-state">No favorite numbers yet.</div>
          </div>
        </div>
      </div>

      <div id="topupStepAmount" class="topup-step" data-topup-step="amount">
        <div class="topup-step-card topup-amount-card">
          <h2>Top-Up Amount</h2>
          <div id="topupAmountSummary" class="topup-selection-summary"></div>

          <div class="topup-amount-heading">
            <span>Top-Up Amount</span>
            <small id="topupAmountCurrency">BDT</small>
          </div>
          <div id="topupPresetGrid" class="topup-preset-grid" aria-label="Preset top-up amounts">
            <button class="topup-preset-button" type="button" data-topup-amount="20">20 BDT</button>
            <button class="topup-preset-button" type="button" data-topup-amount="50">50 BDT</button>
            <button class="topup-preset-button" type="button" data-topup-amount="100">100 BDT</button>
            <button class="topup-preset-button" type="button" data-topup-amount="200">200 BDT</button>
            <button class="topup-preset-button" type="button" data-topup-amount="500">500 BDT</button>
            <button class="topup-preset-button" type="button" data-topup-amount="1000">1000 BDT</button>
          </div>

          <label class="topup-field-group topup-custom-amount" for="topupAmountInput">
            <span class="topup-field-label">Custom Amount</span>
            <span class="topup-money-shell">
              <strong id="topupAmountPrefix">BDT</strong>
              <input id="topupAmountInput" type="number" inputmode="decimal" min="20" max="1000" step="0.01" autocomplete="off" placeholder="0.00">
            </span>
          </label>
          <p id="topupMinimumHint" class="topup-minimum-hint">Minimum top-up amount is 20 BDT.</p>
          <button id="topupAmountContinueButton" class="topup-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="topupStepPin" class="topup-step" data-topup-step="pin">
        <div class="topup-step-card topup-pin-card">
          <h2>Verify to Continue</h2>
          <div id="topupPinSummary" class="topup-verify-summary"></div>
          <div class="topup-verification-note">
            <strong>Secure verification</strong>
            <span>Confirm this top-up with your transaction PIN.</span>
          </div>
          <label class="topup-field-group" for="topupPinInput">
            <span class="topup-field-label">Enter PIN</span>
            <input id="topupPinInput" class="topup-pin-input" type="password" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="PIN">
          </label>
          <button id="topupPinContinueButton" class="topup-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="topupStepPreview" class="topup-step" data-topup-step="preview">
        <div class="topup-step-card topup-preview-card">
          <h2>Top-Up Preview</h2>
          <div id="topupPreviewRows" class="topup-preview-rows"></div>
          <button id="topupHoldConfirmButton" class="topup-hold-control" type="button" aria-label="Tap and hold to confirm top-up">
            <span class="topup-hold-progress" aria-hidden="true"></span>
            <span class="topup-hold-dot" aria-hidden="true"></span>
            <span class="topup-hold-bubble topup-hold-bubble-one" aria-hidden="true"></span>
            <span class="topup-hold-bubble topup-hold-bubble-two" aria-hidden="true"></span>
            <img class="topup-hold-logo" src="/assets/brand/zpay-icon.png" alt="" draggable="false">
            <span class="topup-hold-label">Tap and hold to confirm top-up</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<div id="topupActionModal" class="topup-action-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="topupModalTitle" inert>
  <div class="topup-modal-backdrop" data-topup-modal-close></div>
  <div class="topup-modal-card" role="document">
    <button id="topupModalCloseButton" class="topup-modal-close" type="button" aria-label="Close">&times;</button>
    <div id="topupModalIcon" class="topup-modal-icon" aria-hidden="true"></div>
    <div id="topupModalSpinner" class="topup-modal-spinner" aria-hidden="true"></div>
    <h2 id="topupModalTitle">Mobile Top-Up</h2>
    <p id="topupModalMessage"></p>
    <div id="topupModalBody" class="topup-modal-body"></div>
    <div id="topupModalActions" class="topup-modal-actions"></div>
  </div>
</div>
<?php user_page_end($page); ?>
