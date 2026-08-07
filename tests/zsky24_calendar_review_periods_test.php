<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$calendarFakeDb = [];

function calendar_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function calendar_source(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
}

function znews_now(): int { return strtotime('2026-08-07 14:00:00 UTC'); }
function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string { return trim((string)$value); }
function znews_creator_normalize_status($value): string {
    $status = strtoupper(trim((string)$value));
    return in_array($status, ['ACTIVE', 'BLOCKED'], true) ? $status : 'ACTIVE';
}
function fb_get(string $path) {
    global $calendarFakeDb;
    return $calendarFakeDb[$path] ?? null;
}

require_once $root . '/api/znews/lib/creator_weekly_reviews.php';
require_once $root . '/api/znews/lib/creator_calendar_reviews.php';

$anchor = strtotime('2026-08-07 14:00:00 UTC');
$current = znews_calendar_review_period('', $anchor);
calendar_expect(!empty($current['ok']), 'Current calendar period must resolve.');
calendar_expect(($current['period_id'] ?? '') === '2026-08-01', 'August 7 must belong to the 01-07 period.');
calendar_expect(($current['period_end_date'] ?? '') === '2026-08-07', '01-07 period end date is incorrect.');
calendar_expect(($current['period_end_at'] ?? 0) === strtotime('2026-08-08 00:00:00 UTC'), '01-07 period must close at August 8 UTC midnight.');
calendar_expect(($current['lifecycle_status'] ?? '') === 'LIVE', 'The current 01-07 period must be LIVE before August 8 UTC.');
calendar_expect(empty($current['can_generate']), 'A LIVE period must not be generateable.');

$periodTwo = znews_calendar_review_period('', strtotime('2026-08-08 12:00:00 UTC'));
calendar_expect(($periodTwo['period_id'] ?? '') === '2026-08-08', 'August 8 must start the 08-14 period.');
calendar_expect(($periodTwo['period_end_date'] ?? '') === '2026-08-14', '08-14 period end date is incorrect.');

$periodThree = znews_calendar_review_period('', strtotime('2026-08-19 12:00:00 UTC'));
calendar_expect(($periodThree['period_id'] ?? '') === '2026-08-15', 'August 19 must belong to the 15-21 period.');
calendar_expect(($periodThree['period_end_date'] ?? '') === '2026-08-21', '15-21 period end date is incorrect.');

$periodFour = znews_calendar_review_period('', strtotime('2026-08-28 12:00:00 UTC'));
calendar_expect(($periodFour['period_id'] ?? '') === '2026-08-22', 'August 28 must belong to the 22-EOM period.');
calendar_expect(($periodFour['period_end_date'] ?? '') === '2026-08-31', 'August 22 period must end on the last day of August.');
calendar_expect(($periodFour['period_end_at'] ?? 0) === strtotime('2026-09-01 00:00:00 UTC'), '22-EOM period must close at next-month UTC midnight.');

$february = znews_calendar_review_period('2028-02-22', strtotime('2028-03-02 00:00:00 UTC'));
calendar_expect(($february['period_end_date'] ?? '') === '2028-02-29', '22-EOM must handle leap-year February.');
calendar_expect(!empty($february['completed']), 'Past leap-year period must be completed.');

$invalid = znews_calendar_review_period('2026-08-07', $anchor);
calendar_expect(empty($invalid['ok']), 'Only 01, 08, 15 and 22 may be canonical period IDs.');

$previous = znews_calendar_review_period('', $anchor, 'PREVIOUS_COMPLETED');
calendar_expect(($previous['period_id'] ?? '') === '2026-07-22', 'Previous completed period before August 01-07 must be July 22-EOM.');
calendar_expect(($previous['period_end_date'] ?? '') === '2026-07-31', 'Previous July period end date is incorrect.');
calendar_expect(!empty($previous['completed']), 'Previous calendar period must be completed.');

$catalog = znews_calendar_review_catalog(8, $anchor);
$byId = [];
foreach ($catalog as $row) {
    $byId[(string)($row['period_id'] ?? '')] = $row;
}
calendar_expect(($byId['2026-08-01']['lifecycle_status'] ?? '') === 'LIVE', 'Catalog must expose the current 01-07 period as LIVE.');
calendar_expect(($byId['2026-08-08']['lifecycle_status'] ?? '') === 'UPCOMING', 'Catalog must expose 08-14 as upcoming before August 8 UTC.');
calendar_expect(($byId['2026-08-15']['lifecycle_status'] ?? '') === 'UPCOMING', 'Catalog must expose 15-21 as upcoming.');
calendar_expect(($byId['2026-08-22']['period_end_date'] ?? '') === '2026-08-31', 'Catalog must expose 22-EOM with the real month end.');

$calendar = calendar_source('api/znews/lib/creator_calendar_reviews.php');
$gateway = calendar_source('api/admin/zsky24_creator_admin.php');
$adminJs = calendar_source('api/admin/assets/zsky24-admin.js');
$adminCss = calendar_source('api/admin/assets/zsky24-admin.css');

foreach (['CALENDAR_01_07_08_14_15_21_22_EOM_V1', 'period_scheme', 'live_preview', 'ZNEWS_CALENDAR_PERIOD_NOT_COMPLETED'] as $marker) {
    calendar_expect(str_contains($calendar, $marker), "Calendar review engine marker is missing: {$marker}");
}
calendar_expect(str_contains($calendar, "trim((string)(\$storedPeriod['period_scheme'] ?? '')) !== znews_calendar_review_scheme()"), 'Legacy review rows are not isolated from the calendar scheme.');
calendar_expect(str_contains($calendar, "'schema_version' => 2"), 'Calendar review snapshots must use schema version 2.');
calendar_expect(str_contains($calendar, "'read_only' => true"), 'Live/upcoming calendar periods must be explicitly read-only.');

foreach (['USERS/', 'WALLETS/', 'WALLET_', 'ZNEWS_BALANCES', 'ZNEWS_LEDGER', 'ZNEWS_TRANSFER_REQUESTS'] as $forbidden) {
    calendar_expect(!str_contains($calendar, $forbidden), "Calendar review engine touches forbidden core/financial storage: {$forbidden}");
}
calendar_expect(!preg_match('/payout_amount|wallet_balance|credit_wallet|amount_(?:micros|usd)/i', $calendar), 'Calendar review layer must not calculate or mutate money.');

calendar_expect(str_contains($gateway, 'creator_calendar_reviews.php'), 'Admin gateway does not load the calendar review layer.');
calendar_expect(str_contains($gateway, 'znews_calendar_review_catalog'), 'Admin period list is not using fixed calendar periods.');
calendar_expect(str_contains($gateway, 'znews_calendar_review_generate'), 'Admin generation is not using fixed calendar periods.');
calendar_expect(str_contains($gateway, 'znews_calendar_review_set_status'), 'Admin status updates are not guarded by calendar-period rules.');

foreach (['01–07, 08–14, 15–21 and 22–month end', 'Live period', 'Upcoming', 'Ready to review', 'Duplicate / pending'] as $marker) {
    calendar_expect(str_contains($adminJs, $marker), "User-friendly calendar UI marker is missing: {$marker}");
}
calendar_expect(str_contains($adminJs, "String(meta?.lifecycle_status || '').toUpperCase() !== 'COMPLETED'"), 'Generate/status actions are not blocked for live/upcoming periods.');
calendar_expect(str_contains($adminJs, "data-zsky-weekly-approve") && str_contains($adminJs, "data-zsky-weekly-hold"), 'Approve/Hold actions are missing.');
calendar_expect(str_contains($adminCss, '.zsky-weekly-actions{display:grid;grid-template-columns:repeat(2,minmax(110px,1fr))'), 'Desktop Approve/Hold buttons are not equal-width.');
calendar_expect(str_contains($adminCss, '.zsky-weekly-actions{grid-template-columns:1fr 1fr'), 'Mobile Approve/Hold buttons are not equal-width.');
calendar_expect(str_contains($adminCss, '.zsky-period-action{min-height:48px'), 'Generate/current-period action height is not normalized.');

if ($failures) {
    fwrite(STDERR, "Z Sky 24 calendar review period contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z Sky 24 calendar review period contract passed.\n";
