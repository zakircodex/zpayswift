<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';

$page = user_page_config([
    'key' => 'bundle',
    'title' => 'Bundle',
    'section_id' => 'bundleSection',
    'body_class' => 'user-bundle-page',
    'page_css' => 'bundle-page.css',
    'page_js' => 'bundle-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_global_loader' => false,
]);

user_page_begin($page);
?>
<section id="bundleSection" class="page-section bundle-page-section active" aria-labelledby="bundlePageTitle">
  <div class="bundle-page-shell">
    <header class="bundle-page-header">
      <a id="bundleBackButton" class="bundle-header-button" href="/user/dashboard" aria-label="Go back">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h1 id="bundlePageTitle">Bundle</h1>
      <a class="bundle-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <div id="bundleScrollBody" class="bundle-scroll-body" aria-live="polite">
      <div id="bundleStepOperator" class="bundle-step active" data-bundle-step="operator">
        <div class="bundle-step-card bundle-operator-card">
          <h2>Select Operator</h2>
          <div id="bundleOperatorGrid" class="bundle-operator-grid" role="list" aria-label="Supported prepaid operators"></div>
        </div>
      </div>

      <div id="bundleStepOffers" class="bundle-step" data-bundle-step="offers">
        <div class="bundle-offers-heading">
          <div id="bundleSelectedOperator" class="bundle-selected-operator" aria-live="polite"></div>
          <h2>Available Bundles</h2>
        </div>
        <div id="bundleOffersGrid" class="bundle-offers-grid" aria-busy="true">
          <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="bundle-offer-card bundle-skeleton-card" aria-hidden="true">
              <span class="bundle-skeleton bundle-skeleton-title"></span>
              <span class="bundle-skeleton bundle-skeleton-pill"></span>
              <span class="bundle-skeleton bundle-skeleton-line"></span>
              <span class="bundle-skeleton bundle-skeleton-line short"></span>
              <span class="bundle-skeleton bundle-skeleton-button"></span>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div id="bundleStepNumber" class="bundle-step" data-bundle-step="number">
        <div class="bundle-selected-summary" id="bundleNumberSummary"></div>
        <div class="bundle-step-card bundle-number-card">
          <h2>Mobile Number</h2>
          <label class="bundle-field-group" for="bundleNumberInput">
            <span class="bundle-field-label">Mobile Number</span>
            <span class="bundle-input-shell">
              <span class="bundle-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h10V5H7Zm3 12h4v1h-4v-1Z"/></svg>
              </span>
              <input id="bundleNumberInput" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX" maxlength="14">
            </span>
          </label>
          <button id="bundleNumberContinueButton" class="bundle-primary-button" type="button">Continue</button>
        </div>

        <div class="bundle-favorite-panel">
          <div class="bundle-panel-heading"><h2>Favorite Number</h2></div>
          <div id="bundleFavoriteList" class="bundle-favorite-list" aria-live="polite">
            <div class="bundle-empty-state">Loading favorite numbers...</div>
          </div>
        </div>
      </div>

      <div id="bundleStepPin" class="bundle-step" data-bundle-step="pin">
        <div class="bundle-step-card bundle-pin-card">
          <h2>Verify to continue</h2>
          <div id="bundlePinSummary" class="bundle-verify-summary"></div>
          <div class="bundle-verification-note">
            <strong>Secure verification</strong>
            <span>Confirm this bundle request with your transaction PIN.</span>
          </div>
          <label class="bundle-field-group" for="bundlePinInput">
            <span class="bundle-field-label">Enter PIN</span>
            <input id="bundlePinInput" class="bundle-pin-input" type="password" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="PIN">
          </label>
          <button id="bundlePinContinueButton" class="bundle-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="bundleStepPreview" class="bundle-step" data-bundle-step="preview">
        <div class="bundle-step-card bundle-preview-card">
          <h2>Bundle Preview</h2>
          <div id="bundlePreviewRows" class="bundle-preview-rows"></div>
          <button id="bundleHoldConfirmButton" class="bundle-hold-control" type="button" aria-label="Tap and hold to confirm bundle">
            <span class="bundle-hold-progress" aria-hidden="true"></span>
            <span class="bundle-hold-dot" aria-hidden="true"></span>
            <span class="bundle-hold-bubble bundle-hold-bubble-one" aria-hidden="true"></span>
            <span class="bundle-hold-bubble bundle-hold-bubble-two" aria-hidden="true"></span>
            <img class="bundle-hold-logo" src="/assets/brand/zpay-icon.png" alt="" draggable="false">
            <span class="bundle-hold-label">Tap and hold to confirm bundle</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<div id="bundleActionModal" class="bundle-action-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="bundleModalTitle" inert>
  <div class="bundle-modal-backdrop" data-bundle-modal-close></div>
  <div class="bundle-modal-card" role="document">
    <button id="bundleModalCloseButton" class="bundle-modal-close" type="button" aria-label="Close">&times;</button>
    <div id="bundleModalIcon" class="bundle-modal-icon" aria-hidden="true"></div>
    <div id="bundleModalSpinner" class="bundle-modal-spinner" aria-hidden="true"></div>
    <h2 id="bundleModalTitle">Bundle</h2>
    <p id="bundleModalMessage"></p>
    <div id="bundleModalBody" class="bundle-modal-body"></div>
    <div id="bundleModalActions" class="bundle-modal-actions"></div>
  </div>
</div>
<?php user_page_end($page); ?>
