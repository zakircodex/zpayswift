<?php
declare(strict_types=1);

$assertions = 0;
$writes = 0;
$now = strtotime('2026-08-07 16:20:00 UTC');
$fixture = [];

function monthly_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_now(): int
{
    global $now;
    return $now;
}

function znews_calendar_review_start_days(): array
{
    return [1, 8, 15, 22];
}

function znews_calendar_review_scheme(): string
{
    return 'CALENDAR_01_07_08_14_15_21_22_EOM_V1';
}

function znews_calendar_review_period(string $periodId = '', ?int $anchor = null, string $mode = 'CURRENT'): array
{
    $anchor = $anchor ?? znews_now();
    $timezone = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $periodId, $timezone);
    if (!$date instanceof DateTimeImmutable || !in_array((int)$date->format('j'), [1, 8, 15, 22], true)) {
        return ['ok' => false];
    }
    $day = (int)$date->format('j');
    $end = $day === 1 ? $date->setDate((int)$date->format('Y'), (int)$date->format('m'), 8)
        : ($day === 8 ? $date->setDate((int)$date->format('Y'), (int)$date->format('m'), 15)
        : ($day === 15 ? $date->setDate((int)$date->format('Y'), (int)$date->format('m'), 22)
        : $date->modify('first day of next month')));
    return [
        'ok' => true,
        'period_id' => $periodId,
        'period_start_date' => $periodId,
        'period_end_date' => $end->modify('-1 day')->format('Y-m-d'),
        'completed' => $end->getTimestamp() <= $anchor,
    ];
}

function znews_weekly_review_period_path(string $periodId): string
{
    return 'ZNEWS_WEEKLY_REVIEW_PERIODS/' . $periodId;
}

function znews_weekly_review_creator_path(string $periodId, string $uid): string
{
    return 'ZNEWS_WEEKLY_REVIEWS/' . $periodId . '/' . $uid;
}

function znews_weekly_review_creator_registry_rows(): array
{
    return [
        [
            'creator_uid' => 'creator_a',
            'name' => 'Creator A',
            'status' => 'ACTIVE',
            'wallet_currency_snapshot' => 'MYR',
            'account_country_snapshot' => 'MY',
        ],
        [
            'creator_uid' => 'creator_b',
            'name' => 'Creator B',
            'status' => 'ACTIVE',
            'wallet_currency_snapshot' => 'BDT',
            'account_country_snapshot' => 'BD',
        ],
    ];
}

function znews_creator_normalize_status($value): string
{
    return strtoupper((string)$value) === 'BLOCKED' ? 'BLOCKED' : 'ACTIVE';
}

function znews_weekly_review_status($value, string $fallback = 'UNDER_REVIEW'): string
{
    $status = strtoupper(trim((string)$value));
    return in_array($status, ['UNDER_REVIEW', 'APPROVED', 'HELD'], true) ? $status : $fallback;
}

function fb_get(string $path)
{
    global $fixture;
    return $fixture[$path] ?? null;
}

function fb_put(string $path, $value): bool
{
    global $writes;
    $writes++;
    throw new RuntimeException('Monthly preview attempted a Firebase write: ' . $path);
}

function fb_patch(string $path, array $value): bool
{
    global $writes;
    $writes++;
    throw new RuntimeException('Monthly preview attempted a Firebase patch: ' . $path);
}

function fb_delete(string $path): bool
{
    global $writes;
    $writes++;
    throw new RuntimeException('Monthly preview attempted a Firebase delete: ' . $path);
}

require_once dirname(__DIR__) . '/api/znews/lib/creator_monthly_performance.php';

function seed_period(string $periodId, int $aEligible, int $bEligible, string $aStatus = 'APPROVED', string $bStatus = 'APPROVED'): void
{
    global $fixture;
    $fixture[znews_weekly_review_period_path($periodId)] = [
        'period_id' => $periodId,
        'period_scheme' => znews_calendar_review_scheme(),
        'status' => 'UNDER_REVIEW',
        'generated_at' => 100,
    ];
    foreach ([
        'creator_a' => [$aEligible, $aStatus],
        'creator_b' => [$bEligible, $bStatus],
    ] as $uid => [$eligible, $status]) {
        $fixture[znews_weekly_review_creator_path($periodId, $uid)] = [
            'period_id' => $periodId,
            'period_scheme' => znews_calendar_review_scheme(),
            'creator_uid' => $uid,
            'review_status' => $status,
            'raw_views' => $eligible + 2,
            'eligible_views' => $eligible,
            'invalid_views' => 2,
            'spam_views' => 1,
            'duplicate_views' => 1,
            'pending_views' => 0,
            'creator_views_excluded' => 1,
            'self_views_excluded' => 1,
            'eligible_read_seconds' => $eligible * 20,
            'reviewed_at' => 200,
        ];
    }
}

// Completed July: all four periods generated and approved.
seed_period('2026-07-01', 10, 5);
seed_period('2026-07-08', 20, 5);
seed_period('2026-07-15', 30, 10);
seed_period('2026-07-22', 40, 20);

$july = znews_monthly_performance_preview('2026-07', $now);
monthly_expect(!empty($july['ok']), 'completed monthly preview failed');
monthly_expect(($july['month']['lifecycle_status'] ?? '') === 'COMPLETED', 'July should be completed');
monthly_expect(($july['summary']['generated_period_count'] ?? 0) === 4, 'all four July periods should be generated');
monthly_expect(!empty($july['summary']['all_periods_generated']), 'all-period flag should be true');
monthly_expect(($july['summary']['settlement_eligible_views'] ?? 0) === 140, 'approved eligible views should aggregate to 140');
monthly_expect(($july['summary']['payout_candidate_count'] ?? 0) === 2, 'both creators should be payout candidates');
monthly_expect(($july['summary']['currency_snapshot_counts']['MYR'] ?? 0) === 1, 'MYR snapshot count should be one');
monthly_expect(($july['summary']['currency_snapshot_counts']['BDT'] ?? 0) === 1, 'BDT snapshot count should be one');
monthly_expect(!empty($july['summary']['settlement_ready']), 'completed fully approved month should be settlement-ready preview');
monthly_expect(empty($july['summary']['revenue_amount_calculated']), 'monthly preview must not calculate revenue amount');
monthly_expect(empty($july['summary']['wallet_mutation_performed']), 'monthly preview must report zero wallet mutation');
monthly_expect(!empty($july['summary']['requires_live_payout_preflight']), 'monthly preview must require later live payout preflight');

$items = [];
foreach ((array)$july['items'] as $row) {
    $items[(string)$row['creator_uid']] = $row;
}
monthly_expect(($items['creator_a']['settlement_eligible_views'] ?? 0) === 100, 'Creator A approved eligible total mismatch');
monthly_expect(($items['creator_b']['settlement_eligible_views'] ?? 0) === 40, 'Creator B approved eligible total mismatch');
monthly_expect(($items['creator_a']['settlement_traffic_share_ppm'] ?? 0) === 714286, 'Creator A traffic share must use deterministic largest remainder allocation');
monthly_expect(($items['creator_b']['settlement_traffic_share_ppm'] ?? 0) === 285714, 'Creator B traffic share must complete one million ppm');
monthly_expect((($items['creator_a']['settlement_traffic_share_ppm'] ?? 0) + ($items['creator_b']['settlement_traffic_share_ppm'] ?? 0)) === 1000000, 'monthly traffic shares must sum to one million ppm');

// A held review excludes its eligible views from settlement share and blocks the creator.
$fixture[znews_weekly_review_creator_path('2026-07-22', 'creator_b')]['review_status'] = 'HELD';
$held = znews_monthly_performance_preview('2026-07', $now);
$heldItems = [];
foreach ((array)$held['items'] as $row) {
    $heldItems[(string)$row['creator_uid']] = $row;
}
monthly_expect(($heldItems['creator_b']['settlement_eligible_views'] ?? -1) === 20, 'only approved Creator B periods should contribute to settlement views');
monthly_expect(empty($heldItems['creator_b']['payout_candidate']), 'held creator review must block payout candidacy');
monthly_expect(str_contains((string)$heldItems['creator_b']['payout_block_reason'], 'held'), 'held creator should have a clear block reason');
monthly_expect(empty($held['summary']['settlement_ready']), 'held review must block monthly settlement readiness');

// Restore July and seed only the first August period; August is still open/read-only.
$fixture[znews_weekly_review_creator_path('2026-07-22', 'creator_b')]['review_status'] = 'APPROVED';
seed_period('2026-08-01', 7, 3);
$august = znews_monthly_performance_preview('2026-08', $now);
monthly_expect(($august['month']['lifecycle_status'] ?? '') === 'LIVE', 'August should be live at the test anchor');
monthly_expect(($august['summary']['generated_period_count'] ?? 0) === 1, 'only first August period should be generated');
monthly_expect(empty($august['summary']['settlement_ready']), 'open month must never be settlement-ready');
foreach ((array)$august['items'] as $row) {
    monthly_expect(empty($row['payout_candidate']), 'open-month creator must not be payout candidate');
    monthly_expect(($row['payout_block_reason'] ?? '') === 'Month is still open.', 'open month should have explicit block reason');
}

monthly_expect($writes === 0, 'monthly preview must never write Firebase');

$source = file_get_contents(dirname(__DIR__) . '/api/znews/lib/creator_monthly_performance.php');
monthly_expect(is_string($source), 'monthly performance source could not be read');
monthly_expect(!str_contains($source, "fb_put("), 'monthly performance source must not call fb_put');
monthly_expect(!str_contains($source, "fb_patch("), 'monthly performance source must not call fb_patch');
monthly_expect(!str_contains($source, "fb_delete("), 'monthly performance source must not call fb_delete');
monthly_expect(!str_contains($source, "USERS/"), 'monthly performance source must not read Z-Pay user core');
monthly_expect(!str_contains($source, "USER_WALLETS/"), 'monthly performance source must not read Z-Pay wallet storage');

$gateway = file_get_contents(dirname(__DIR__) . '/api/admin/zsky24_creator_admin.php');
monthly_expect(is_string($gateway), 'Z Sky admin gateway could not be read');
monthly_expect(str_contains($gateway, "creator_monthly_performance.php"), 'admin gateway must load monthly performance library');
monthly_expect(str_contains($gateway, "action === 'monthly_periods'"), 'monthly period endpoint is missing');
monthly_expect(str_contains($gateway, "action === 'monthly_preview'"), 'monthly preview endpoint is missing');
monthly_expect(str_contains($gateway, 'Monthly performance preview is GET-only.'), 'monthly preview must stay GET-only');

echo "Z Sky 24 monthly performance preview passed ({$assertions} assertions).\n";
