<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$css = (string)file_get_contents($root . '/api/admin/assets/admin-ux.css');
$uxJs = (string)file_get_contents($root . '/api/admin/assets/admin-dashboard-ux.js');

$assertions = 0;

function admin_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_ui_expect(str_contains($dashboard, 'class="status-group" id="configStatusStrip"'), 'Configuration status chips must use the flat responsive group');
admin_ui_expect(!str_contains($dashboard, 'class="status-strip" id="configStatusStrip"'), 'Nested status strips must not return');
admin_ui_expect(str_contains($dashboard, 'dashboard-status-cards'), 'Dashboard status cards need a scoped presentation hook');
admin_ui_expect(str_contains($dashboard, 'dashboard-primary-grid'), 'Dashboard primary panels need a scoped grid hook');
admin_ui_expect(str_contains($dashboard, 'worker-metric-grid'), 'Worker summary needs its responsive metric grid');
admin_ui_expect(str_contains($dashboard, 'worker-table-wrap') && str_contains($dashboard, 'workers-table'), 'Worker table presentation hooks are missing');
admin_ui_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-ux.css')"), 'Admin shell CSS must use deploy-safe cache versioning');
admin_ui_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-dashboard-ux.js')"), 'Admin presentation JS must use deploy-safe cache versioning');

foreach (['dashboardSection', 'addMoneySection', 'supportSection', 'topupSection', 'bundleSection', 'bundleOffersSection', 'usersSection', 'operatorsSection', 'zsky24Section'] as $section) {
    admin_ui_expect(str_contains($uxJs, $section . ':['), "Missing topbar presentation metadata for {$section}");
}

admin_ui_expect(str_contains($css, '.admin-premium-body .status-group'), 'Status group styles must remain scoped to Admin');
admin_ui_expect(str_contains($css, '.admin-premium-body .worker-metric-grid'), 'Worker metric styles must remain scoped to Admin');
admin_ui_expect(str_contains($css, '.admin-premium-body .workers-table th'), 'Worker table sticky header rule is missing');
admin_ui_expect(str_contains($css, ':focus-visible'), 'Visible keyboard focus styling is required');
admin_ui_expect(str_contains($css, '@media(max-width:860px)') && str_contains($css, '@media(max-width:600px)') && str_contains($css, '@media(max-width:360px)'), 'Required responsive breakpoints are missing');
admin_ui_expect(str_contains($css, 'overflow-x:hidden') || str_contains($css, 'overflow:visible'), 'Admin shell overflow behavior must be explicit');
admin_ui_expect(str_contains($css, 'width:min(280px,calc(100vw - 42px))'), 'Mobile sidebar must stay within narrow viewports');
admin_ui_expect(str_contains($css, 'grid-template-columns:repeat(2,minmax(0,1fr))'), 'Mobile two-column metric/action layout is missing');

echo "Admin dashboard UI polish tests passed ({$assertions} assertions).\n";
