<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'history',
    'title' => 'History',
    'section_id' => 'historySection',
    'body_class' => 'user-history-page',
    'page_css' => 'history-page.css',
    'page_js' => 'history-page.js',
    'active_nav' => 'history',
]);
user_page_begin($page);
?>
<section id="historySection" class="page-section history-page-section active" aria-labelledby="historyPageTitle">
  <div class="history-card">
    <div class="section-head">
      <div>
        <h2 id="historyPageTitle" class="section-title">My History</h2>
        <p class="section-sub">Top-up, bundle, bKash/Nagad and wallet history</p>
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
    <div id="historyList" class="history-list" aria-live="polite">
      <div class="history-item"><div class="history-id">Loading history...</div></div>
    </div>
  </div>
</section>

<div id="detailModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="detailModalTitle">
  <div class="modal-card">
    <button id="closeDetailModalBtn" class="modal-close" type="button" aria-label="Close">&times;</button>
    <h3 id="detailModalTitle" class="modal-title">Request Details</h3>
    <div id="detailGrid" class="detail-grid"></div>
  </div>
</div>
<?php user_page_end($page); ?>
