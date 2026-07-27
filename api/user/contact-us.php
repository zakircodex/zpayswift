<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'contact-us',
    'title' => 'Contact Us',
    'section_id' => 'supportSection',
    'body_class' => 'user-contact-page',
    'page_css' => 'contact-us-page.css',
    'page_js' => 'contact-us-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_bottom_nav' => false,
]);
user_page_begin($page);
?>
<section id="supportSection" class="page-section support-contact-page-section active">
  <div id="supportHomeView" class="support-contact-shell">
    <div class="support-contact-fixed-area">
      <div class="support-contact-hero-panel">
        <span class="support-contact-bubble one" aria-hidden="true"></span>
        <span class="support-contact-bubble two" aria-hidden="true"></span>
        <span class="support-contact-bubble three" aria-hidden="true"></span>
        <div class="support-contact-toolbar">
          <a class="support-contact-icon-button" href="/user/dashboard" aria-label="Back to dashboard">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 11H7.8l5.6-5.6L12 4 4 12l8 8 1.4-1.4L7.8 13H20v-2Z"/></svg>
          </a>
          <h2>Contact Us</h2>
          <a class="support-contact-icon-button notification-button" href="/user/notifications" aria-label="Open notifications">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.8 2.8 0 0 0 2.7-2h-5.4A2.8 2.8 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5-6.7V3a2 2 0 1 0-4 0v1.3A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg>
            <span data-notification-badge class="notification-badge hidden">0</span>
          </a>
        </div>
        <div class="support-contact-copy"><h3>Get In Touch</h3><p>Always Within Your Reach</p></div>
        <div id="supportContactActions" class="support-contact-actions"></div>
      </div>
    </div>

    <div id="supportContactBody" class="support-scroll-body">
      <div class="support-info-stack">
        <article class="support-info-card"><h3>Support Guidelines</h3><p>Describe the issue clearly. Add a screenshot when useful. Use one ticket for one issue.</p></article>
        <article class="support-info-card"><h3>Security Warning</h3><p>Never share your password, PIN or OTP. Support will never ask for those credentials.</p></article>
        <article class="support-info-card"><h3>Support Hours</h3><p id="supportHoursText">Support hours will be shown when configured.</p></article>
        <article class="support-info-card"><h3>Average Reply Time</h3><p id="supportAverageReplyText">Average reply time will be shown when configured.</p></article>
        <article class="support-info-card support-notice-card"><h3>Support Notice</h3><p id="supportNotice">For faster help, keep your message short and clear.</p></article>
      </div>
    </div>
    <a id="supportOpenRequestsButton" class="support-floating-button" href="/user/support">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 5.5A3.5 3.5 0 0 1 7.5 2h9A3.5 3.5 0 0 1 20 5.5v6A3.5 3.5 0 0 1 16.5 15H11l-5 4v-4.2A3.5 3.5 0 0 1 4 11.5v-6Zm3.5-1.3A1.3 1.3 0 0 0 6.2 5.5v6A1.3 1.3 0 0 0 7.5 12.8h.7v1.6l2-1.6h6.3a1.3 1.3 0 0 0 1.3-1.3v-6a1.3 1.3 0 0 0-1.3-1.3h-9Z"/></svg>
      <span>Support</span>
    </a>
  </div>
</section>
<?php user_page_end($page); ?>
