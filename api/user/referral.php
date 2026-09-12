<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';

user_page_start_session();
$queryReferralCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_GET['code'] ?? '')) ?? '');
if (strlen($queryReferralCode) >= 8 && strlen($queryReferralCode) <= 20) {
    $_SESSION['pending_referral_code'] = $queryReferralCode;
}
$pendingReferralCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_SESSION['pending_referral_code'] ?? '')) ?? '');
if (strlen($pendingReferralCode) < 8 || strlen($pendingReferralCode) > 20) {
    $pendingReferralCode = '';
    unset($_SESSION['pending_referral_code']);
}
if (trim((string)($_SESSION['user_session_token'] ?? '')) !== '' && $pendingReferralCode !== '') {
    unset($_SESSION['pending_referral_code']);
}

$page = user_page_config([
    'key' => 'referral',
    'title' => 'Refer & Earn',
    'section_id' => 'referralSection',
    'body_class' => 'user-referral-page',
    'page_css' => 'referral-page.css',
    'page_js' => 'referral-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_drawer' => false,
    'show_bottom_nav' => true,
    'show_global_loader' => false,
]);

user_page_begin($page);
?>
<section id="referralSection" class="page-section referral-page-section active" aria-labelledby="referralPageTitle" aria-busy="true" data-pending-code="<?= htmlspecialchars($pendingReferralCode, ENT_QUOTES, 'UTF-8') ?>">
  <header class="referral-page-header">
    <a class="referral-icon-button" href="/user/dashboard" aria-label="Go back">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
    </a>
    <h1 id="referralPageTitle">Refer &amp; Earn</h1>
    <a class="referral-icon-button notification-button" href="/user/notifications" aria-label="Notifications">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
      <span data-notification-badge class="notification-badge hidden">0</span>
    </a>
  </header>

  <div class="referral-scroll-body">
    <div id="referralStatusMessage" class="referral-status-message hidden" role="status"></div>

    <section class="referral-summary" aria-label="Referral summary">
      <div><small>Total Earned</small><strong id="referralTotalEarned">--</strong></div>
      <div><small>Referred</small><strong id="referralCount">--</strong></div>
      <div><small>Rewarded</small><strong id="referralRewardedCount">--</strong></div>
    </section>

    <section class="referral-panel referral-share-panel" aria-labelledby="referralShareTitle">
      <div class="referral-panel-heading">
        <span class="referral-panel-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 16a3 3 0 0 0-2.4 1.2l-6.7-3.35a3.2 3.2 0 0 0 0-1.7l6.7-3.35A3 3 0 1 0 15 7c0 .15.01.3.04.44L8.3 10.81a3 3 0 1 0 0 4.38l6.74 3.37A3 3 0 1 0 18 16Z"/></svg></span>
        <div><h2 id="referralShareTitle">Your referral link</h2><p>Share it with a new Z-Pay Swift user in your country.</p></div>
      </div>
      <div class="referral-code-row"><code id="referralCode">Loading...</code></div>
      <div class="referral-actions">
        <button id="referralCopyCode" type="button">Copy code</button>
        <button id="referralShare" class="primary" type="button">Share link</button>
      </div>
    </section>

    <section id="referralClaimPanel" class="referral-panel hidden" aria-labelledby="referralClaimTitle">
      <div class="referral-panel-heading">
        <span class="referral-panel-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 4h10v2h3v5c0 3.87-3.13 7-7 7v2h3v2H8v-2h3v-2c-3.87 0-7-3.13-7-7V6h3V4Zm0 4H6v3a5 5 0 0 0 5 5V6H9v5H7V8Zm10 0h-1v3h-2V6h-1v10a5 5 0 0 0 5-5V8h-1Z"/></svg></span>
        <div><h2 id="referralClaimTitle">Have a referral code?</h2><p>Link it before your first bKash or Nagad request.</p></div>
      </div>
      <form id="referralClaimForm" novalidate>
        <label for="referralClaimCode">Referral code</label>
        <div class="referral-claim-row"><input id="referralClaimCode" autocomplete="off" maxlength="20" placeholder="Enter code" required><button id="referralClaimButton" class="primary" type="submit">Apply</button></div>
      </form>
    </section>

    <section id="referralDevicePanel" class="referral-panel referral-device-panel hidden" aria-labelledby="referralDeviceTitle">
      <h2 id="referralDeviceTitle">Device verification pending</h2>
      <p>Open this account in the Z-Pay Swift Android app to activate referral rewards. The account remains fully usable while verification is pending.</p>
    </section>

    <section class="referral-panel" aria-labelledby="referralRulesTitle">
      <h2 id="referralRulesTitle">How rewards work</h2>
      <div id="referralRules" class="referral-rules"><p>Loading reward details...</p></div>
    </section>

    <section class="referral-history-section" aria-labelledby="referralHistoryTitle">
      <div class="referral-history-heading"><h2 id="referralHistoryTitle">Reward history</h2><span id="referralHistoryCurrency"></span></div>
      <div id="referralHistoryList" class="referral-history-list"><div class="referral-empty">Loading history...</div></div>
      <button id="referralSeeMore" class="referral-see-more hidden" type="button">See more</button>
    </section>
  </div>
</section>
<?php user_page_end($page); ?>
