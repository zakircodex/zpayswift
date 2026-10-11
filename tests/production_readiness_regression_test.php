<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function production_readiness_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$summary = (string)file_get_contents($root . '/api/admin/users/summary.php');
$workerStatus = (string)file_get_contents($root . '/api/admin/workers/status.php');
$subadminDashboard = (string)file_get_contents($root . '/api/subadmin/dashboard.php');
$subadminJs = (string)file_get_contents($root . '/api/subadmin/assets/subadmin.js');

production_readiness_expect(
    str_contains($dashboard, 'id="dashUsersCount">-</strong>'),
    'Dashboard must not display a misleading zero before the user summary loads'
);
production_readiness_expect(
    str_contains($dashboardJs, "proxyGet('user_summary'")
        && str_contains($dashboardJs, "safeLoad('User Summary', loadUserSummary"),
    'Dashboard fast load must request the exact user summary'
);
production_readiness_expect(
    str_contains($proxy, "case 'user_summary':")
        && str_contains($proxy, "proxy_forward_admin_get('users/summary.php')"),
    'Admin proxy must expose the protected user summary action'
);
production_readiness_expect(
    str_contains($summary, 'auth_require_admin_session();')
        && str_contains($summary, "['shallow' => 'true']"),
    'User summary must require admin auth and avoid loading full user records'
);
production_readiness_expect(
    str_contains($subadminJs, 'window.location.origin + normalized'),
    'Subadmin API guide must expand root-relative API configuration to an absolute origin'
);
production_readiness_expect(
    str_contains($subadminJs, 'const topupEndpoint = APP_API_BASE')
        && str_contains($subadminJs, 'const bundleEndpoint = APP_API_BASE'),
    'Integration examples must use the normalized API base'
);
production_readiness_expect(
    str_contains($subadminDashboard, "filemtime(__DIR__ . '/assets/subadmin.js')")
        && str_contains($subadminDashboard, "filemtime(__DIR__ . '/assets/subadmin.css')"),
    'Subadmin assets must use deploy-safe cache versions'
);
production_readiness_expect(
    str_contains($workerStatus, '$isOnline = $flagOnline && $recentHeartbeat;'),
    'Admin worker summary must not report stale online flags as live workers'
);

echo "Production readiness regression tests passed ({$assertions} assertions).\n";
