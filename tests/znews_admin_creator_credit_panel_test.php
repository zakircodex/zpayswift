<?php
declare(strict_types=1);

function zsky_admin_expect(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function zsky_admin_reject(string $source, string $needle, string $message): void
{
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$gateway = (string)file_get_contents($root . '/api/admin/zsky24_creator_admin.php');
$script = (string)file_get_contents($root . '/api/admin/assets/zsky24-admin.js');
$style = (string)file_get_contents($root . '/api/admin/assets/zsky24-admin.css');

zsky_admin_expect($dashboard, 'data-section="zsky24Section"', 'Z Sky 24 admin navigation is missing.');
zsky_admin_expect($dashboard, 'id="zsky24Section"', 'Isolated Z Sky 24 section is missing.');
zsky_admin_expect($dashboard, "filemtime(__DIR__ . '/assets/zsky24-admin.js')", 'Admin controller cache version does not follow the deployed file.');
zsky_admin_expect($dashboard, "filemtime(__DIR__ . '/assets/zsky24-admin.css')", 'Admin stylesheet cache version does not follow the deployed file.');

zsky_admin_expect($gateway, "session_name('zawtopup_admin_v3')", 'Creator gateway does not reuse the protected admin session.');
zsky_admin_expect($gateway, "\$_SESSION['admin_csrf']", 'Creator gateway does not read the dashboard CSRF token.');
zsky_admin_expect($gateway, 'hash_equals($storedCsrf, $providedCsrf)', 'Creator gateway lacks timing-safe CSRF validation.');
zsky_admin_expect($gateway, 'auth_require_admin_session(true)', 'Creator gateway lacks live admin authorization.');

zsky_admin_expect($script, "headers['X-CSRF-TOKEN'] = csrf", 'Creator admin mutations do not send the dashboard CSRF token.');
zsky_admin_expect($script, "window.dispatchEvent(new CustomEvent('zsky24:admin-ready'))", 'Late admin-controller readiness recovery is missing.');
zsky_admin_expect($script, 'window.loadZSky24Admin = load', 'Dashboard integration cannot start or refresh the creator panel.');
zsky_admin_expect($script, "if (\$('zsky24Section')?.classList.contains('active'))", 'An initially active creator panel does not load.');
zsky_admin_expect($script, "empty('Loading creators", 'Initial creator loading feedback is missing.');

foreach (["request('creator_status'", "request('payout_preflight'", "request('weekly_generate'", "request('weekly_status'"] as $request) {
    zsky_admin_expect($script, $request, 'A creator review action bypasses the protected gateway.');
}

zsky_admin_reject($script, 'creator_amount:', 'The browser submits a creator settlement value.');
zsky_admin_reject($script, 'reported_revenue_micros:', 'The browser submits provider revenue.');
zsky_admin_reject($script, 'manual_credit', 'The panel invents an unsupported manual-credit mutation.');
zsky_admin_expect($style, '@media(max-width:560px)', 'The Z Sky admin panel lacks its mobile layout.');

echo "Z Sky 24 admin creator panel assertions passed.\n";
