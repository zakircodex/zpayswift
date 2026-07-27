<?php
declare(strict_types=1);

$provider = strtoupper((string)($mfsProvider ?? 'BKASH'));
$providerLabel = $provider === 'NAGAD' ? 'Nagad' : 'bKash';
?>
<script>window.USER_MFS_PROVIDER = <?= json_encode($provider, JSON_UNESCAPED_SLASHES) ?>;</script>
<section id="mfsSection" class="page-section mfs-page-section active" aria-labelledby="mfsPageTitle">
  <div class="wizard-card mfs-card">
    <div class="section-head">
      <div>
        <h2 id="mfsPageTitle" class="section-title"><?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?> Send Money</h2>
        <p class="section-sub">Personal <?= htmlspecialchars($providerLabel, ENT_QUOTES, 'UTF-8') ?> request with review, PIN confirmation and secure tracking.</p>
      </div>
    </div>
    <div id="mfsStepForm" class="mfs-step active">
      <div class="choice-grid">
        <a class="choice-btn mfs-provider-choice<?= $provider === 'BKASH' ? ' active' : '' ?>" data-provider="BKASH" href="/user/bkash">bKash<small>Personal</small></a>
        <a class="choice-btn mfs-provider-choice<?= $provider === 'NAGAD' ? ' active' : '' ?>" data-provider="NAGAD" href="/user/nagad">Nagad<small>Personal</small></a>
      </div>
      <div class="field field-top-gap"><label>Receiver Number</label><input id="mfsReceiverNumber" class="input" type="tel" inputmode="numeric" maxlength="11" placeholder="01XXXXXXXXX"></div>
      <div class="wizard-actions"><button id="mfsPreviewBtn" class="btn blue" type="button">Next</button></div>
    </div>
    <div id="mfsStepAmount" class="mfs-step">
      <div class="wizard-step-title">Enter Amount</div>
      <div class="wizard-step-sub">Write amount before PIN confirmation</div>
      <div class="field field-top-gap"><label>Amount BDT</label><input id="mfsAmountBdt" class="input" type="number" inputmode="decimal" step="0.01" min="500" max="50000" placeholder="BDT 500 - 50000"></div>
      <div class="field field-top-gap" id="mfsAmountRmField"><label>Amount RM <span class="muted">Malaysia account</span></label><input id="mfsAmountRm" class="input" type="number" inputmode="decimal" step="0.01" min="0.01" placeholder="RM amount"></div>
      <div id="mfsRateHint" class="mfs-rate-hint"></div>
      <div id="mfsAmountNotice" class="mfs-step-notice"></div>
      <div class="wizard-actions"><button id="mfsAmountBackBtn" class="btn ghost" type="button">Back</button><button id="mfsAmountNextBtn" class="btn green" type="button">Next</button></div>
    </div>
    <div id="mfsStepPin" class="mfs-step">
      <div class="wizard-step-title">Enter PIN</div>
      <div class="wizard-step-sub">PIN is required before final review</div>
      <input id="mfsPin" class="wizard-big-input" type="password" inputmode="numeric" autocomplete="off" placeholder="Enter PIN">
      <div class="wizard-actions"><button id="mfsPinBackBtn" class="btn ghost" type="button">Back</button><button id="mfsConfirmBtn" class="btn green" type="button">Next</button></div>
    </div>
    <div id="mfsStepPreview" class="mfs-step">
      <div class="result-card good"><div class="result-title">Review Send Money</div><div id="mfsPreviewDetails" class="result-text">-</div></div>
      <div class="field field-top-gap"><label>Reference <span class="muted">Optional</span></label><input id="mfsReference" class="input" placeholder="Reference / note"></div>
      <div class="wizard-actions"><button id="mfsBackBtn" class="btn ghost" type="button">Back / Edit</button><button id="mfsSendBtn" class="btn green" type="button">Confirm &amp; Send Money</button></div>
    </div>
    <div class="result-box hidden" aria-hidden="true"><div id="mfsResult" class="result-empty">No send money request created yet.</div></div>
  </div>
</section>
