<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'add-money',
    'title' => 'Add Money',
    'section_id' => 'addMoneySection',
    'body_class' => 'user-add-money-page',
    'page_css' => 'add-money-page.css',
    'page_js' => 'add-money-page.js',
    'active_nav' => 'add-money',
    'show_header' => false,
    'show_drawer' => false,
    'show_bottom_nav' => true,
]);
user_page_begin($page);
?>
<section id="addMoneySection" class="page-section add-money-page-section active" aria-labelledby="addMoneyPageTitle">
  <header class="add-money-page-header">
    <a id="addMoneyBackButton" class="add-money-header-button" href="/user/dashboard" aria-label="Back to dashboard">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
    </a>
    <h1 id="addMoneyPageTitle">Add Money</h1>
    <a class="add-money-header-button notification-button" href="/user/notifications" aria-label="Notifications">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
      <span data-notification-badge class="notification-badge hidden">0</span>
    </a>
  </header>
  <div class="add-money-page-shell">
    <div id="addMoneyContent" class="add-money-content">
      <div class="add-money-loading-card" role="status">Loading add money settings...</div>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
