<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'services',
    'title' => 'Services',
    'section_id' => 'servicesSection',
    'body_class' => 'user-services-page',
    'page_css' => 'services-page.css',
    'page_js' => 'services-page.js',
    'active_nav' => '',
]);
user_page_begin($page);
?>
<section id="servicesSection" class="page-section services-page-section active" aria-labelledby="servicesPageTitle">
  <div class="feature-card services-hub">
    <div class="feature-heading">
      <div>
        <span class="feature-eyebrow">Z-Pay Swift</span>
        <h2 id="servicesPageTitle">Services</h2>
        <p>Choose a service to continue.</p>
      </div>
    </div>
    <div class="android-service-grid">
      <a class="android-service-card" href="/user/add-money"><span class="service-glyph">+</span><strong>Add Money</strong></a>
      <a class="android-service-card" href="/user/transfer"><span class="service-glyph">⇄</span><strong>Transfer</strong></a>
      <a class="android-service-card" href="/user/topup"><span class="service-glyph">▯</span><strong>Top-Up</strong></a>
      <a class="android-service-card" href="/user/bkash"><span class="service-glyph service-glyph-send">➤</span><strong>bKash</strong></a>
      <a class="android-service-card" href="/user/nagad"><span class="service-glyph service-glyph-send">➤</span><strong>Nagad</strong></a>
      <a class="android-service-card" href="/user/bundle"><span class="service-glyph">▣</span><strong>Bundle</strong></a>
      <a class="android-service-card" href="/user/history"><span class="service-glyph">↻</span><strong>History</strong></a>
      <a class="android-service-card" href="/user/contact-us"><span class="service-glyph">?</span><strong>Support</strong></a>
      <a class="android-service-card" href="/user/profile"><span class="service-glyph">○</span><strong>Profile</strong></a>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
