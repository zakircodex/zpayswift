<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$jsPath = $root . '/api/admin/assets/zsky24-admin.js';
$cssPath = $root . '/api/admin/assets/zsky24-admin.css';
$gatewayPath = $root . '/api/admin/zsky24_creator_admin.php';
$assertions = 0;

function monthly_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

monthly_ui_expect(is_file($jsPath), 'Z Sky admin JavaScript is missing');
monthly_ui_expect(is_file($cssPath), 'Z Sky admin stylesheet is missing');
monthly_ui_expect(is_file($gatewayPath), 'Z Sky admin gateway is missing');
$js = file_get_contents($jsPath);
$css = file_get_contents($cssPath);
$gateway = file_get_contents($gatewayPath);
monthly_ui_expect(is_string($js), 'Z Sky admin JavaScript could not be read');
monthly_ui_expect(is_string($css), 'Z Sky admin stylesheet could not be read');
monthly_ui_expect(is_string($gateway), 'Z Sky admin gateway could not be read');

// Existing creator and weekly screens must remain available.
foreach ([
    'data-zsky-mode="CREATORS"',
    'data-zsky-mode="WEEKLY"',
    'id="zskyCreatorAdminView"',
    'id="zskyWeeklyReviewView"',
    'id="zskyGenerateWeeklyReview"',
    'Check readiness',
] as $required) {
    monthly_ui_expect(str_contains($js, $required), 'existing creator/weekly UI contract is missing: ' . $required);
}

// Monthly UI remains an equal primary control and its summary does not mutate a wallet.
foreach ([
    'data-zsky-mode="MONTHLY"',
    'Monthly summary',
    'id="zskyMonthlyView"',
    'id="zskyMonthlySelect"',
    'id="zskyMonthlyReadOnly"',
    'Read-only preview',
    'Approved eligible views',
    'Reviews approved',
    'Traffic share',
    'Currency snapshot',
    'Review readiness',
    'Adsterra gross USD',
    'Safety reserve USD',
    'Distributable USD',
    'Creator pool USD',
    'Platform share USD',
] as $required) {
    monthly_ui_expect(str_contains($js, $required), 'monthly admin UI contract is missing: ' . $required);
}
monthly_ui_expect(str_contains($css, '.zsky-primary-tabs{display:grid;grid-template-columns:repeat(7,minmax(105px,1fr))'), 'completed desktop Admin tabs are not equal-width');
monthly_ui_expect(str_contains($css, '.zsky-primary-tabs{grid-template-columns:repeat(3,minmax(0,1fr))'), 'tablet Admin tabs are not responsive');
monthly_ui_expect(str_contains($css, '.zsky-primary-tabs{grid-template-columns:1fr 1fr'), 'mobile Admin tabs are not responsive');

// Monthly summary uses GET-only preview endpoints; settlement actions live in the protected Payout workspace.
monthly_ui_expect(str_contains($js, "request('monthly_periods'"), 'monthly period GET is not used');
monthly_ui_expect(str_contains($js, "request('monthly_preview'"), 'monthly preview GET is not used');
monthly_ui_expect(!preg_match("/request\\('monthly_(?:periods|preview)'[^;]*method\\s*:\\s*'POST'/s", $js), 'monthly UI attempts a POST request');
monthly_ui_expect(!str_contains($js, 'monthly_settle'), 'monthly settlement action exists in the UI');
monthly_ui_expect(!str_contains($js, 'monthly_pay'), 'monthly pay action exists in the UI');
monthly_ui_expect(str_contains($js, "request('revenue_sync'") && str_contains($js, "request('payout_execute'"), 'protected settlement workspace actions are missing');

// User-facing copy must make financial safety boundaries clear.
foreach ([
    'Locked Adsterra revenue and FX snapshots are shown without mutating any wallet.',
    'No money is calculated here.',
    'Live account preflight is still required before any future payout.',
] as $required) {
    monthly_ui_expect(str_contains($js, $required), 'monthly safety wording is missing: ' . $required);
}

// Gateway contract remains GET-only for both monthly endpoints.
foreach (['monthly_periods', 'monthly_preview'] as $action) {
    monthly_ui_expect(str_contains($gateway, "if (\$action === '{$action}')"), 'gateway action is missing: ' . $action);
}
monthly_ui_expect(substr_count($gateway, "Monthly period list is GET-only.") === 1, 'monthly period GET-only guard changed');
monthly_ui_expect(substr_count($gateway, "Monthly performance preview is GET-only.") === 1, 'monthly preview GET-only guard changed');

echo "Z Sky 24 monthly admin UI contract passed ({$assertions} assertions).\n";
