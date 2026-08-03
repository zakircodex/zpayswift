<?php
declare(strict_types=1);

function expect_contains(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function expect_not_contains(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$script = (string)file_get_contents($root . '/api/admin/assets/zsky24-admin.js');
$style = (string)file_get_contents($root . '/api/admin/assets/zsky24-admin.css');

expect_contains($dashboard, 'data-section="zsky24Section"', 'Z Sky 24 admin navigation must exist.');
expect_contains($dashboard, 'id="zsky24Section"', 'Isolated Z Sky 24 section must exist.');
expect_contains($dashboard, '/api/admin/assets/zsky24-admin.js?v=2', 'Isolated admin controller must be loaded.');
expect_contains($dashboard, '/api/admin/assets/zsky24-admin.css?v=1', 'Isolated admin styles must be loaded.');

foreach ([
    "proxy_forward_admin_post('znews/ads/impressions/recheck.php'",
    "proxy_forward_admin_post('znews/ads/settlements/settle.php'",
    "proxy_forward_admin_post('znews/transfers/approve.php'",
    "proxy_forward_admin_post('znews/transfers/reject.php'",
] as $route) {
    expect_contains($proxy, $route, 'All Z Sky mutations must pass through the CSRF-protected admin proxy.');
}

expect_contains($script, 'expected_updated_at', 'Optimistic locking must be sent for admin mutations.');
expect_contains($script, 'idempotency_key', 'Idempotency keys must be sent for admin mutations.');
expect_contains($script, "window.dispatchEvent(new CustomEvent('zsky24:admin-ready'))", 'Late admin-controller readiness recovery is missing.');
expect_contains($script, "if (!zskyState.loaded) await load(); else render();", 'Tabs must recover a missed initial queue load.');
expect_contains($script, "empty('Loading Z Sky 24 data…')", 'Initial Z Sky 24 loading feedback is missing.');
expect_contains($script, "proxyPost('zsky24_settlement_settle'", 'Verified settlement must use the existing endpoint.');
expect_contains($script, "proxyPost('zsky24_transfer_approve'", 'Transfer approval must use the existing endpoint.');
expect_contains($script, "proxyPost('zsky24_transfer_reject'", 'Transfer rejection must use the existing endpoint.');
expect_not_contains($script, 'creator_amount:', 'The browser must never submit creator settlement value.');
expect_not_contains($script, 'reported_revenue_micros:', 'The browser must never submit provider revenue.');
expect_not_contains($script, 'manual_credit', 'The panel must not invent an unsupported manual-credit mutation.');
expect_contains($style, '@media(max-width:560px)', 'The Z Sky admin panel must retain a mobile layout.');

echo "PASS: Z Sky 24 admin creator credit panel assertions.\n";
