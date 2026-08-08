<?php
declare(strict_types=1);

$provider = strtoupper(trim((string)($mfsProvider ?? 'BKASH')));
if (!in_array($provider, ['BKASH', 'NAGAD'], true)) {
    $provider = 'BKASH';
}

$providerLabel = $provider === 'NAGAD' ? 'Nagad' : 'bKash';
$pageTitle = $providerLabel . ' Send Money';
$trackingBase = function_exists('app_api_url')
    ? app_api_url('mfs/receipt.php')
    : 'https://zpayswift.com/api/mfs/receipt.php';
?>
<script>
window.USER_MFS_CONFIG = <?= json_encode([
    'provider' => $provider,
    'provider_label' => $providerLabel,
], JSON_UNESCAPED_SLASHES) ?>;
</script>

<section
  id="mfsSection"
  class="page-section mfs-page-section active"
  aria-labelledby="mfsPageTitle"
  data-provider="<?= htmlspecialchars($provider, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
  data-provider-label="<?= htmlspecialchars($providerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
  data-tracking-base="<?= htmlspecialchars($trackingBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
>
  <div class="mfs-page-shell">
    <header class="mfs-page-header">
      <a id="mfsBackButton" class="mfs-header-button" href="/user/dashboard" aria-label="Go back">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h1 id="mfsPageTitle"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <a class="mfs-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <div id="mfsScrollBody" class="mfs-scroll-body" aria-live="polite">
      <div id="mfsStepReceiver" class="mfs-step active" data-mfs-step="receiver">
        <div class="mfs-step-card mfs-receiver-card">
          <h2>Receiver Number</h2>
          <label class="mfs-field" for="mfsReceiverNumber">
            <span>Receiver Number</span>
            <span class="mfs-input-shell">
              <span class="mfs-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h10V5H7Zm3 12h4v1h-4v-1Z"/></svg>
              </span>
              <input id="mfsReceiverNumber" type="tel" inputmode="numeric" autocomplete="tel" maxlength="14" placeholder="01XXXXXXXXX">
            </span>
          </label>
          <button id="mfsReceiverContinue" class="mfs-primary-button" type="button">Continue</button>
        </div>

        <div class="mfs-favorite-card">
          <div class="mfs-panel-heading">
            <h2>Favorite Number</h2>
          </div>
          <div id="mfsFavoriteList" class="mfs-favorite-list" aria-live="polite">
            <div class="mfs-empty-state">No favorite numbers yet.</div>
          </div>
        </div>
      </div>

      <div id="mfsStepAmount" class="mfs-step" data-mfs-step="amount">
        <div class="mfs-step-card mfs-amount-card">
          <h2>Amount</h2>
          <div id="mfsAmountSummary" class="mfs-selection-summary"></div>
          <div id="mfsRateCard" class="mfs-rate-card hidden" aria-hidden="true">
            <span>Current Rate</span>
            <strong id="mfsRateText">-</strong>
          </div>
          <label class="mfs-field" for="mfsAmountBdt">
            <span>BDT Amount</span>
            <span class="mfs-money-shell">
              <b>BDT</b>
              <input id="mfsAmountBdt" type="number" inputmode="decimal" autocomplete="off" min="500" max="50000" step="0.01" placeholder="0.00">
            </span>
          </label>
          <p class="mfs-minimum-hint">Minimum send money amount is 500 BDT.</p>
          <div id="mfsMyrEstimate" class="mfs-payable-hint hidden" aria-hidden="true">
            <span>MYR payable</span>
            <strong>Confirmed securely in preview</strong>
          </div>
          <button id="mfsAmountContinue" class="mfs-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="mfsStepPin" class="mfs-step" data-mfs-step="pin">
        <div class="mfs-step-card mfs-pin-card">
          <h2>Verify to continue</h2>
          <div id="mfsPinSummary" class="mfs-selection-summary"></div>
          <div class="mfs-verification-note">
            <strong>Secure verification</strong>
            <span>Confirm this <?= htmlspecialchars($providerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> request with your transaction PIN.</span>
          </div>
          <label class="mfs-field" for="mfsPin">
            <span>Enter PIN</span>
            <input id="mfsPin" class="mfs-pin-input" type="password" inputmode="numeric" autocomplete="off" maxlength="6" placeholder="PIN">
          </label>
          <button id="mfsPinContinue" class="mfs-primary-button" type="button">Continue</button>
        </div>
      </div>

      <div id="mfsStepPreview" class="mfs-step" data-mfs-step="preview">
        <div class="mfs-step-card mfs-preview-card">
          <h2><?= htmlspecialchars($providerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> Preview</h2>
          <div id="mfsPreviewRows" class="mfs-preview-rows"></div>
          <label class="mfs-field mfs-reference-field" for="mfsReference">
            <span>Reference <small>Optional</small></span>
            <input id="mfsReference" type="text" autocomplete="off" maxlength="80" placeholder="Enter reference (optional)">
          </label>
          <button id="mfsHoldConfirm" class="mfs-hold-control" type="button" aria-label="Tap and hold to confirm <?= htmlspecialchars($providerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <span class="mfs-hold-progress" aria-hidden="true"></span>
            <span class="mfs-hold-dot" aria-hidden="true"></span>
            <span class="mfs-hold-bubble mfs-hold-bubble-one" aria-hidden="true"></span>
            <span class="mfs-hold-bubble mfs-hold-bubble-two" aria-hidden="true"></span>
            <img class="mfs-hold-logo" src="/assets/brand/zpay-icon.png" alt="" draggable="false">
            <span class="mfs-hold-label">Tap and hold to confirm <?= htmlspecialchars($providerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<div id="mfsActionModal" class="mfs-action-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mfsModalTitle" inert>
  <div class="mfs-modal-backdrop" data-mfs-modal-close></div>
  <div class="mfs-modal-card" role="document">
    <button id="mfsModalClose" class="mfs-modal-close" type="button" aria-label="Close">&times;</button>
    <div id="mfsModalIcon" class="mfs-modal-icon" aria-hidden="true"></div>
    <div id="mfsModalSpinner" class="mfs-modal-spinner" aria-hidden="true"></div>
    <h2 id="mfsModalTitle"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
    <p id="mfsModalMessage"></p>
    <div id="mfsModalBody" class="mfs-modal-body"></div>
    <div id="mfsModalActions" class="mfs-modal-actions"></div>
  </div>
</div>
