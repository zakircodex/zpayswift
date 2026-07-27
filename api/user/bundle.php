<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config(['key'=>'bundle','title'=>'Bundle','section_id'=>'bundleSection','body_class'=>'user-bundle-page','page_css'=>'bundle-page.css','page_js'=>'bundle-page.js','active_nav'=>'']);
user_page_begin($page);
?>
      <section id="bundleSection" class="page-section active">
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
  <div class="modal-card">
    <button id="closeBundleBuyModalBtn" class="modal-close" type="button">Ã—</button>

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
      <button id="confirmBundleBuyBtn" class="btn green" type="button">Review Bundle</button>
      <button id="cancelBundleBuyBtn" class="btn ghost" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="detailModal" class="modal">
  <div class="modal-card">
    <button id="closeDetailModalBtn" class="modal-close" type="button">Ã—</button>

    <h3 class="modal-title">Request Details</h3>
    <p class="modal-sub">Detailed view of your request information</p>

    <div class="detail-grid">
      <div class="detail-box"><label>Request ID</label><strong id="detailRequestId">-</strong></div>
      <div class="detail-box"><label>Status</label><strong id="detailStatus">-</strong></div>
<?php user_page_end($page); ?>
