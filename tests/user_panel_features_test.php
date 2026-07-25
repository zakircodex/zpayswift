<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'dashboard' => $root . '/api/user/dashboard.php',
    'proxy' => $root . '/api/user/proxy.php',
    'dashboard_js' => $root . '/api/user/assets/dashboard.js',
    'app_js' => $root . '/api/user/assets/user-app.js',
    'app_css' => $root . '/api/user/assets/user-app.css',
    'profile_update' => $root . '/api/user/profile_update.php',
    'transfer_create' => $root . '/api/transfer/create.php',
    'support' => $root . '/api/lib/support.php',
];

$source = [];
foreach ($files as $name => $path) {
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "FAIL: unable to read {$path}\n");
        exit(1);
    }
    $source[$name] = $value;
}

$tests = 0;

function expect_true(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function contains(string $source, string $needle): bool
{
    return str_contains($source, $needle);
}

foreach (['servicesSection', 'transferSection', 'profileSection', 'supportSection', 'notificationsSection'] as $sectionId) {
    expect_true(contains($source['dashboard'], 'id="' . $sectionId . '"'), "missing User Panel section {$sectionId}");
}

foreach (['overviewSection', 'addMoneySection', 'transferSection', 'historySection', 'profileSection'] as $sectionId) {
    expect_true(contains($source['dashboard'], 'data-page-section="' . $sectionId . '"'), "missing primary navigation destination {$sectionId}");
}

expect_true(
    contains($source['app_css'], "body.user-authenticated[data-active-section]:not([data-active-section='overviewSection']) .hero-card"),
    'balance hero is not explicitly restricted to the Dashboard'
);

foreach (['profile_get', 'profile_update', 'profile_photo_upload', 'profile_change_password', 'profile_change_pin'] as $action) {
    expect_true(contains($source['proxy'], "case '{$action}':"), "missing profile proxy action {$action}");
}

expect_true(
    contains($source['proxy'], "array_key_exists('name', \$body)")
    && contains($source['proxy'], "array_key_exists('email', \$body)"),
    'profile proxy must explicitly whitelist editable fields'
);
expect_true(
    !preg_match("/\$profileBody\s*\[\s*['\"](?:role|pricing_country|wallet_currency|status)['\"]\s*\]/", $source['proxy']),
    'profile proxy forwards a forbidden authority field'
);
expect_true(
    contains($source['profile_update'], 'FIELD_NOT_ALLOWED'),
    'profile backend no longer rejects authority-field updates'
);

foreach (['transfer_recipient', 'transfer_preview', 'transfer_create', 'transfer_history'] as $action) {
    expect_true(contains($source['proxy'], "case '{$action}':"), "missing transfer proxy action {$action}");
}
expect_true(
    strpos($source['proxy'], 'user_proxy_validate_transaction_pin(', strpos($source['proxy'], "case 'transfer_preview':")) !== false,
    'website transfer preview does not validate the transaction PIN server-side'
);
expect_true(
    contains($source['proxy'], "'preview_token' => trim")
    && contains($source['transfer_create'], 'zpay_transfer_claim_preview_token'),
    'transfer execution is not bound to the existing preview-token flow'
);
expect_true(
    contains($source['app_js'], 'app.transfer.submitting')
    && contains($source['app_js'], 'Press & Hold to Transfer'),
    'transfer confirmation lacks duplicate-submit or Android-style hold control'
);

foreach (['support_config', 'support_list', 'support_details', 'support_create', 'support_reply', 'support_attachment'] as $action) {
    expect_true(contains($source['proxy'], "case '{$action}':"), "missing support proxy action {$action}");
}
expect_true(
    contains($source['support'], 'function support_user_can_access')
    && contains($source['support'], "return \$uid !== '' && \$uid === (string)(\$ticket['uid'] ?? '');"),
    'support ownership check is missing or weakened'
);
expect_true(
    contains($source['app_js'], "makeIdempotencyKey('SUPPORT-CREATE')")
    && contains($source['app_js'], "makeIdempotencyKey('SUPPORT-REPLY')"),
    'support create/reply idempotency is not wired'
);
expect_true(
    contains($source['app_js'], 'option.dataset.relatedType')
    && contains($source['app_js'], "selectedOptions?.[0]?.dataset.relatedType"),
    'related support request type is not preserved independently from its category'
);
expect_true(
    contains($source['proxy'], "'support/attachment.php?'")
    && contains($source['proxy'], "header('Cache-Control: private, no-store"),
    'private support attachment bridge is missing or cacheable'
);
expect_true(
    contains($source['dashboard'], 'support-contact-hero-panel')
    && contains($source['dashboard'], 'Get In Touch')
    && contains($source['dashboard'], 'supportOpenRequestsButton')
    && contains($source['dashboard'], 'supportRequestWorkspace')
    && contains($source['dashboard'], 'supportStartChatButton'),
    'Android-style Contact Us landing layout is missing'
);
expect_true(
    contains($source['app_css'], "#supportSection .support-contact-hero-panel")
    && contains($source['app_css'], "body.user-authenticated[data-active-section='supportSection'] .bottom-nav")
    && contains($source['app_css'], "#supportSection .support-floating-button")
    && contains($source['app_css'], "#supportSection .support-scroll-body")
    && contains($source['app_css'], 'height: 100dvh'),
    'Contact Us page scoped fixed/scroll Android-style CSS is missing'
);
expect_true(
    contains($source['app_js'], 'function showSupportHome')
    && contains($source['app_js'], 'function showSupportWorkspace')
    && contains($source['app_js'], 'function openSupportEntry')
    && contains($source['app_js'], 'function startSupportChat')
    && contains($source['app_js'], 'openSupportTicketCandidate')
    && contains($source['app_js'], 'supportNotificationBadge')
    && contains($source['dashboard_js'], 'window.zpaySupportShowWorkspace'),
    'Contact Us navigation/workspace JavaScript is missing'
);

expect_true(
    contains($source['app_js'], 'function escapeHtml')
    && contains($source['app_js'], 'text.textContent = String(message.message'),
    'support/profile rendering lacks the expected XSS-safe text path'
);
expect_true(
    contains($source['app_js'], "['SESSION_EXPIRED', 'AUTH_REQUIRED', 'UNAUTHORIZED']"),
    'multipart User Panel requests do not handle session expiry'
);
expect_true(
    contains($source['dashboard_js'], "p === '/user/profile'")
    && contains($source['dashboard_js'], "p === '/user/support'")
    && contains($source['dashboard_js'], "p === '/user/transfer'")
    && contains($source['dashboard_js'], "p === '/user/notifications'"),
    'User Panel route mapping is incomplete'
);
expect_true(
    contains($source['app_js'], "sectionChanged(document.body.getAttribute('data-active-section') || 'overviewSection')"),
    'direct User Panel routes do not initialize their feature data'
);
expect_true(
    contains($source['dashboard'], 'id="notificationsPageTitle"')
    && contains($source['dashboard'], 'id="notificationPageLive"')
    && contains($source['app_js'], "window.openSection?.('notificationsSection')")
    && !contains($source['app_js'], 'notificationModal')
    && contains($source['dashboard_js'], "sidebar.toggleAttribute('inert', hiddenFromLayout)"),
    'dedicated notification page or mobile navigation accessibility guard is missing'
);

echo "User Panel feature tests passed ({$tests} assertions).\n";
