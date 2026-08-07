<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/cpanel-production-deploy.yml';
$dashboardPath = $root . '/api/admin/dashboard.php';
$assertions = 0;

function deploy_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

deploy_expect(is_file($workflowPath), 'production deploy workflow is missing');
$workflow = file_get_contents($workflowPath);
deploy_expect(is_string($workflow), 'production deploy workflow could not be read');

// Production remains an explicit, main-only operation.
deploy_expect(str_contains($workflow, 'workflow_dispatch:'), 'production deploy is not manual');
deploy_expect(str_contains($workflow, 'ref: main'), 'production deploy does not checkout main');
deploy_expect(!str_contains($workflow, 'agent/zsky24-creator-system-hardening'), 'production deploy is pinned to a feature branch');

// New creator-review and anti-fraud contracts must block a bad deployment.
foreach ([
    'php tests/znews_backend_foundation_test.php',
    'php tests/znews_web_ui_foundation_test.php',
    'php tests/znews_end_to_end_contract_test.php',
    'php tests/znews_view_antifraud_test.php',
    'php tests/zsky24_dual_domain_test.php',
    'php tests/zsky24_creator_period_payout_test.php',
    'php tests/zsky24_monthly_performance_preview_test.php',
    'php -l api/admin/zsky24_creator_admin.php',
    'php -l api/znews/lib/creator_monthly_performance.php',
    'node --check api/admin/assets/zsky24-admin.js',
] as $required) {
    deploy_expect(str_contains($workflow, $required), 'production preflight is missing: ' . $required);
}

// Retired per-ad credit, Z Sky balance and withdrawal contracts must not gate production.
foreach ([
    'zsky24_balance_policy_ui_test.php',
    'zsky24_new_tab_balance_sync_test.php',
    'znews_admin_creator_credit_panel_test.php',
    'znews_controlled_ad_test_utility_test.php',
    'znews_auto_credit_test.php',
    'znews_self_view_ad_protection_test.php',
    'tools/zsky24_controlled_ad_test.php',
    'tools/zsky24_auto_settlement_retry.php',
] as $retired) {
    deploy_expect(!str_contains($workflow, $retired), 'retired production contract remains: ' . $retired);
}

// The public package must fail closed if any creator-system runtime file is omitted.
foreach ([
    'test -f deployment/api/admin/zsky24_creator_admin.php',
    'test -f deployment/api/admin/assets/zsky24-admin.js',
    'test -f deployment/api/admin/assets/zsky24-admin.css',
    'test -f deployment/api/znews/lib/creator_registry.php',
    'test -f deployment/api/znews/lib/creator_view_policy.php',
    'test -f deployment/api/znews/lib/creator_payout_batches.php',
    'test -f deployment/api/znews/lib/creator_monthly_performance.php',
] as $packaged) {
    deploy_expect(str_contains($workflow, $packaged), 'deployment package assertion is missing: ' . $packaged);
}

// Z Sky Admin CSS/JS must automatically get a fresh URL when their deployed files change.
deploy_expect(is_file($dashboardPath), 'admin dashboard is missing');
$dashboard = file_get_contents($dashboardPath);
deploy_expect(is_string($dashboard), 'admin dashboard could not be read');
deploy_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/zsky24-admin.css')"), 'Z Sky admin CSS is not content-deploy cache-busted');
deploy_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/zsky24-admin.js')"), 'Z Sky admin JavaScript is not content-deploy cache-busted');
deploy_expect(!str_contains($dashboard, 'zsky24-admin.css?v=1'), 'fixed Z Sky admin CSS cache version remains');
deploy_expect(!str_contains($dashboard, 'zsky24-admin.js?v=2'), 'fixed Z Sky admin JavaScript cache version remains');

deploy_expect(str_contains($workflow, 'mirror --reverse --verbose --parallel=2 deployment/ ${FTP_REMOTE_PATH}'), 'approved deployment directory is not the FTPS source');
deploy_expect(str_contains($workflow, 'Verified production commit: $GITHUB_SHA'), 'live commit verification is missing');

echo "Z Sky 24 production deploy contract passed ({$assertions} assertions).\n";
