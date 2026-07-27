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
    'show_bottom_nav' => false,
]);
user_page_begin($page);
?>
<section id="addMoneySection" class="page-section add-money-page-section active" aria-labelledby="addMoneyPageTitle">
  <h1 id="addMoneyPageTitle" class="visually-hidden">Add Money</h1>
  <div class="add-money-page-shell">
    <div id="addMoneyContent" class="add-money-content">
      <div class="add-money-loading-card" role="status">Loading add money settings...</div>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
