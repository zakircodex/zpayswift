<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mobileDashboard = (string)file_get_contents($root . '/api/lib/mobile_dashboard.php');
$userDashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$adminDashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$adminProxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$adminJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$tests = 0;

function progressive_dashboard_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

progressive_dashboard_expect(
    str_contains($mobileDashboard, 'function zpay_dash_dashboard_core_payload(array $auth): array')
    && str_contains($mobileDashboard, 'function zpay_dash_dashboard_deferred_payload(array $auth): array')
    && str_contains($mobileDashboard, 'function zpay_dash_dashboard_payload(array $auth): array'),
    'Progressive dashboard payload functions are missing'
);
progressive_dashboard_expect(
    (static function () use ($mobileDashboard): bool {
        $start = strpos($mobileDashboard, 'function zpay_dash_dashboard_core_payload');
        $end = strpos($mobileDashboard, 'function zpay_dash_dashboard_deferred_payload');
        $core = substr($mobileDashboard, $start, $end - $start);
        return !str_contains($core, 'zpay_dash_stats_for_user')
            && !str_contains($core, 'zpay_dash_services_for_role')
            && !str_contains($core, 'zpay_dash_notification_count')
            && !str_contains($core, 'zpay_dash_all_banners');
    })(),
    'Core payload still waits for deferred dashboard reads'
);
progressive_dashboard_expect(
    str_contains($userDashboard, "\$scope === 'core'")
    && str_contains($userDashboard, 'zpay_dash_dashboard_core_payload($auth)')
    && str_contains($userDashboard, "\$scope === 'deferred'")
    && str_contains($userDashboard, 'zpay_dash_dashboard_deferred_payload($auth)')
    && str_contains($userDashboard, 'zpay_dash_dashboard_payload($auth)'),
    'Dashboard scope routing or full-response compatibility is incomplete'
);
progressive_dashboard_expect(
    str_contains($adminDashboard, 'id="dashboardTaglineText"')
    && str_contains($adminDashboard, 'id="dashboardTaglineActive"')
    && str_contains($adminDashboard, "filemtime(__DIR__ . '/assets/dashboard.js')")
    && str_contains($adminDashboard, "filemtime(__DIR__ . '/assets/dashboard.css')"),
    'Admin tagline editor or cache-busting is missing'
);
progressive_dashboard_expect(
    str_contains($adminProxy, "case 'dashboard_config_get':")
    && str_contains($adminProxy, "case 'dashboard_config_save':")
    && str_contains($adminJs, "proxyGet('dashboard_config_get'")
    && str_contains($adminJs, "proxyPost('dashboard_config_save'"),
    'Admin tagline API wiring is incomplete'
);

echo "Android dashboard progressive API tests passed ({$tests} assertions).\n";
