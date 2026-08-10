<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';

$services = [
    [
        'title' => 'Add Money',
        'description' => 'Add wallet funds through available payment channels.',
        'href' => '/user/add-money',
        'icon' => 'M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V8h-5a3 3 0 0 0 0 6h5v3.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm11 3.5a1 1 0 1 0 0 2h5v-2h-5Z',
    ],
    [
        'title' => 'Z-Pay Transfer',
        'description' => 'Send money securely inside Z-Pay Swift.',
        'href' => '/user/transfer',
        'icon' => 'm15.5 4 4 4-4 4V9H5V7h10.5V4ZM8.5 12v3H19v2H8.5v3l-4-4 4-4Z',
    ],
    [
        'title' => 'Mobile Top-Up',
        'description' => 'Recharge mobile numbers with request tracking.',
        'href' => '/user/topup',
        'icon' => 'M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v12h8V5H8Zm3 14v1h2v-1h-2Z',
    ],
    [
        'title' => 'Bundle',
        'description' => 'Submit mobile bundle requests and track status.',
        'href' => '/user/bundle',
        'icon' => 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z',
    ],
    [
        'title' => 'bKash',
        'description' => 'Request bKash send money support.',
        'href' => '/user/bkash',
        'icon' => 'm4 12 15-8-4.5 16-3.2-6.1L4 12Zm7.8-.2 1.7 3.2 1.9-6.7-6.3 3.4 2.7.1Z',
    ],
    [
        'title' => 'Nagad',
        'description' => 'Request Nagad send money support.',
        'href' => '/user/nagad',
        'icon' => 'm4 12 15-8-4.5 16-3.2-6.1L4 12Zm7.8-.2 1.7 3.2 1.9-6.7-6.3 3.4 2.7.1Z',
    ],
    [
        'title' => 'Request Tracking',
        'description' => 'Check request status from History and notifications.',
        'href' => '/user/history',
        'icon' => 'M12 3a9 9 0 1 1-8.5 12h2.2A7 7 0 1 0 5 12H2l4-4 4 4H7a5 5 0 1 1 1.5 3.6l1.4-1.4A3 3 0 1 0 9 12h3V7h2v7H9V9.4l-1.8 1.8A7 7 0 0 0 12 19a7 7 0 0 0 0-14Z',
    ],
    [
        'title' => 'Support',
        'description' => 'Contact support for unresolved requests.',
        'href' => '/user/contact-us',
        'icon' => 'M12 3a9 9 0 0 0-9 9v4a3 3 0 0 0 3 3h2v-8H5.1a7 7 0 0 1 13.8 0H16v8h2.1A3.1 3.1 0 0 1 15 21h-3v-2h3a1 1 0 0 0 1-1v-7h3v5h1v-4a9 9 0 0 0-9-9Z',
    ],
];

$steps = [
    'Choose a service',
    'Verify securely with your transaction PIN',
    'Submit and track your request',
    'Receive status updates and notifications',
];

$notices = [
    'Requests may require admin processing.',
    'Processing time may vary.',
    'Verify receiver number and amount before confirming.',
    'Never share PIN, OTP or password.',
    'Contact support for unresolved requests.',
];

$securityNotes = [
    'PIN and OTP must remain private.',
    'Account activity is limited to the authenticated user.',
    'Location, device and security checks may be used where applicable.',
    'Suspicious activity may require account review.',
    'Z-Pay Swift support will never ask users to send PIN or password through chat.',
];

$page = user_page_config([
    'key' => 'information',
    'title' => 'Information',
    'section_id' => 'informationSection',
    'body_class' => 'user-info-page',
    'page_css' => 'information-page.css',
    'page_js' => 'information-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_drawer' => false,
    'show_bottom_nav' => true,
    'show_global_loader' => false,
]);

user_page_begin($page);
?>
<section id="informationSection" class="page-section information-page-section active" aria-labelledby="informationPageTitle">
  <div class="information-page-shell">
    <header class="information-page-header">
      <a id="informationBackButton" class="information-header-button" href="/user/dashboard" aria-label="Go back">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
      </a>
      <h1 id="informationPageTitle">Information</h1>
      <a class="information-header-button notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </header>

    <div id="informationScrollBody" class="information-scroll-body">
      <div class="information-content">
        <section class="information-card information-intro-card" aria-labelledby="informationIntroTitle">
          <img src="/assets/brand/zpay-icon.png" alt="Z-Pay Swift logo" width="92" height="92">
          <h2 id="informationIntroTitle">Z-Pay Swift</h2>
          <strong>Fast, secure, easy tracking.</strong>
          <p>Private wallet, mobile top-up and remittance support service.</p>
        </section>

        <section class="information-card" aria-labelledby="informationServicesTitle">
          <h2 id="informationServicesTitle">Available Services</h2>
          <div class="information-service-list">
            <?php foreach ($services as $service): ?>
              <a class="information-service-row" href="<?= htmlspecialchars($service['href'], ENT_QUOTES, 'UTF-8') ?>">
                <span class="information-service-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="<?= htmlspecialchars($service['icon'], ENT_QUOTES, 'UTF-8') ?>"/></svg></span>
                <span class="information-service-copy">
                  <strong><?= htmlspecialchars($service['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <small><?= htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8') ?></small>
                </span>
                <span class="information-row-arrow" aria-hidden="true">&rsaquo;</span>
              </a>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="information-card" aria-labelledby="informationStepsTitle">
          <h2 id="informationStepsTitle">How It Works</h2>
          <div class="information-step-list">
            <?php foreach ($steps as $index => $step): ?>
              <div class="information-step-row"><span><?= $index + 1 ?></span><p><?= htmlspecialchars($step, ENT_QUOTES, 'UTF-8') ?></p></div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="information-card" aria-labelledby="informationNoticeTitle">
          <h2 id="informationNoticeTitle">Important Notice</h2>
          <div class="information-bullet-list">
            <?php foreach ($notices as $notice): ?>
              <div class="information-bullet-row"><i aria-hidden="true"></i><p><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p></div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="information-card" aria-labelledby="informationSecurityTitle">
          <h2 id="informationSecurityTitle">Security &amp; Privacy</h2>
          <div class="information-bullet-list">
            <?php foreach ($securityNotes as $note): ?>
              <div class="information-bullet-row"><i aria-hidden="true"></i><p><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></p></div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="information-card" aria-labelledby="informationSupportTitle">
          <h2 id="informationSupportTitle">Support &amp; Contact</h2>
          <div class="information-action-list">
            <a class="information-action-button" href="/user/contact-us">Contact Us</a>
            <a class="information-action-button primary" href="/user/support">Support Requests</a>
          </div>
        </section>

        <section class="information-card" aria-labelledby="informationAppTitle">
          <h2 id="informationAppTitle">App Information</h2>
          <div class="information-app-rows">
            <div class="information-app-row"><small>App Name</small><strong>Z-Pay Swift</strong></div>
            <div class="information-app-row"><small>Version</small><strong>Web 1.0.0</strong></div>
            <div class="information-app-row"><small>Copyright</small><strong>&copy; 2026 Z-Pay Swift. All rights reserved.</strong></div>
          </div>
          <div class="information-action-list information-policy-actions">
            <a class="information-action-button" href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>
            <a class="information-action-button" href="/terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>
          </div>
        </section>
      </div>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
