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
    'show_header' => false,
    'show_drawer' => false,
    'show_global_loader' => false,
]);
user_page_begin($page);
?>
<section id="historySection" class="page-section history-page-section active" aria-labelledby="historyPageTitle">
  <div class="history-page-shell">
    <header class="history-page-header">
      <a id="historyBackButton" class="history-header-button" href="/user/dashboard" aria-label="Back to dashboard">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h1 id="historyPageTitle">History</h1>
      <a class="history-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <main class="history-page-body">
      <div id="historyLive" class="visually-hidden" aria-live="polite"></div>
      <div id="historyList" class="history-list" aria-busy="true">
        <?php for ($index = 0; $index < 4; $index++): ?>
          <div class="history-skeleton" aria-hidden="true">
            <div class="history-skeleton-head"><span></span><i></i></div>
            <b></b><b></b><b></b><b></b>
          </div>
        <?php endfor; ?>
      </div>
    </main>
  </div>

  <div id="historyDetailModal" class="history-detail-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="historyDetailTitle" inert>
    <div class="history-detail-backdrop" data-history-modal-close></div>
    <div class="history-detail-card" tabindex="-1">
      <h2 id="historyDetailTitle">Transaction</h2>
      <div id="historyDetailStatus" class="history-status pending">Pending</div>
      <div id="historyDetailRows" class="history-detail-rows"></div>
      <div id="historyDetailActions" class="history-detail-actions"></div>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
