<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboardAssertions = 0;
$dashboardDb = [];
$dashboardPatchFailures = [];
$dashboardPatchCalls = [];

function dashboard_config_expect(bool $condition, string $message): void
{
    global $dashboardAssertions;
    $dashboardAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function fb_get(string $path, array $query = []): mixed
{
    global $dashboardDb;
    return $dashboardDb[$path] ?? null;
}

function fb_patch(string $path, array $data): bool
{
    global $dashboardDb, $dashboardPatchFailures, $dashboardPatchCalls;
    $dashboardPatchCalls[] = $path;
    if (!empty($dashboardPatchFailures[$path])) {
        return false;
    }
    $existing = is_array($dashboardDb[$path] ?? null) ? $dashboardDb[$path] : [];
    $dashboardDb[$path] = array_replace_recursive($existing, $data);
    return true;
}

require_once $root . '/api/lib/mobile_dashboard.php';

$dashboardDb = [
    'DASHBOARD_CONFIG' => ['notice_text' => 'Legacy tagline', 'notice_active' => true],
];
dashboard_config_expect(
    (zpay_dash_config_source()['notice_text'] ?? '') === 'Legacy tagline',
    'Legacy dashboard config must remain readable during migration'
);

$dashboardDb['APP_CONFIG/DASHBOARD'] = ['notice_text' => 'Canonical tagline', 'notice_active' => true];
dashboard_config_expect(
    (zpay_dash_config_source()['notice_text'] ?? '') === 'Canonical tagline',
    'Canonical app-config dashboard path must take precedence'
);

$dashboardPatchCalls = [];
$dashboardPatchFailures = [];
$savedPath = zpay_dash_save_config(['notice_text' => 'Saved tagline']);
dashboard_config_expect($savedPath === 'APP_CONFIG/DASHBOARD', 'Save must use the canonical app-config path');
dashboard_config_expect(
    $dashboardPatchCalls === ['APP_CONFIG/DASHBOARD'],
    'A successful canonical save must not perform a second write'
);

$dashboardPatchCalls = [];
$dashboardPatchFailures = ['APP_CONFIG/DASHBOARD' => true];
$savedPath = zpay_dash_save_config(['notice_text' => 'Fallback tagline']);
dashboard_config_expect($savedPath === 'DASHBOARD_CONFIG', 'Legacy write fallback must remain available');
dashboard_config_expect(
    $dashboardPatchCalls === ['APP_CONFIG/DASHBOARD', 'DASHBOARD_CONFIG'],
    'Legacy fallback must run only after the canonical write fails'
);

$longBangla = str_repeat('টাকা পাঠান ', 60);
$cleanBangla = zpay_dash_clean_string($longBangla, 120);
dashboard_config_expect(
    json_encode(['notice_text' => $cleanBangla], JSON_UNESCAPED_UNICODE) !== false,
    'Long Bengali tagline must remain valid UTF-8 after truncation'
);
$characters = preg_split('//u', $cleanBangla, -1, PREG_SPLIT_NO_EMPTY);
dashboard_config_expect(
    is_array($characters) && count($characters) <= 120,
    'Tagline limit must be applied to Unicode characters rather than bytes'
);

echo "Admin dashboard config storage tests passed ({$dashboardAssertions} assertions).\n";
