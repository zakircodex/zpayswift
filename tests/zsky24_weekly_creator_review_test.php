<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$weeklyFakeDb = [];

function weekly_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function weekly_source(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
}

// Minimal stubs for side-effect-free period/share/counting unit tests.
function znews_now(): int { return 1786320000; }
function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string { return trim((string)$value); }
function znews_creator_normalize_status($value): string {
    $status = strtoupper(trim((string)$value));
    return in_array($status, ['ACTIVE', 'BLOCKED'], true) ? $status : 'ACTIVE';
}
function znews_view_path(string $viewId): string { return 'ZNEWS_VIEW_SESSIONS/' . trim($viewId); }
function znews_view_risk_threshold(): int { return 70; }
function fb_get(string $path) {
    global $weeklyFakeDb;
    return $weeklyFakeDb[$path] ?? null;
}

require_once $root . '/api/znews/lib/creator_weekly_reviews.php';

$period = znews_weekly_review_period('2026-08-03', strtotime('2026-08-10 00:00:00 UTC'));
weekly_expect(!empty($period['ok']), 'A Monday UTC period must be accepted.');
weekly_expect(($period['period_start_at'] ?? 0) === strtotime('2026-08-03 00:00:00 UTC'), 'Weekly period start is incorrect.');
weekly_expect(($period['period_end_at'] ?? 0) === strtotime('2026-08-10 00:00:00 UTC'), 'Weekly period end is incorrect.');
weekly_expect(!empty($period['completed']), 'The completed week must be marked completed.');
weekly_expect(empty(znews_weekly_review_period('2026-08-04')['ok']), 'A non-Monday period ID must be rejected.');

$liveAnchor = strtotime('2026-08-05 16:15:00 UTC');
$currentPeriod = znews_weekly_review_period('', $liveAnchor, false);
$previousPeriod = znews_weekly_review_period('', $liveAnchor, true);
weekly_expect(($currentPeriod['period_id'] ?? '') === '2026-08-03', 'Current UTC week boundary is incorrect.');
weekly_expect(empty($currentPeriod['completed']), 'The open current week must not be marked completed.');
weekly_expect(($previousPeriod['period_id'] ?? '') === '2026-07-27', 'Previous completed UTC week boundary is incorrect.');
weekly_expect(!empty($previousPeriod['completed']), 'Previous week must be completed at the live audit anchor.');

$shareRows = [
    ['creator_uid' => 'A', 'eligible_views' => 100],
    ['creator_uid' => 'B', 'eligible_views' => 50],
];
znews_weekly_review_allocate_shares($shareRows);
weekly_expect(array_sum(array_column($shareRows, 'traffic_share_ppm')) === 1000000, 'Traffic shares must allocate exactly 1,000,000 ppm.');
weekly_expect(($shareRows[0]['traffic_share_ppm'] ?? 0) === 666667, 'Largest-remainder allocation for creator A is incorrect.');
weekly_expect(($shareRows[1]['traffic_share_ppm'] ?? 0) === 333333, 'Largest-remainder allocation for creator B is incorrect.');

$start = (int)$period['period_start_at'];
$end = (int)$period['period_end_at'];
$weeklyFakeDb = [
    'ZNEWS_USER_POSTS/creator-a' => [
        'post-1' => ['post_id' => 'post-1', 'created_at' => $start + 60],
    ],
    'ZNEWS_POST_VIEWS/post-1' => [
        'valid-guest' => ['view_id' => 'valid-guest', 'created_at' => $start + 100],
        'creator-self' => ['view_id' => 'creator-self', 'created_at' => $start + 200],
        'duplicate-guest' => ['view_id' => 'duplicate-guest', 'created_at' => $start + 300],
        'spam-guest' => ['view_id' => 'spam-guest', 'created_at' => $start + 400],
        'pending-guest' => ['view_id' => 'pending-guest', 'created_at' => $start + 500],
        'future-guest' => ['view_id' => 'future-guest', 'created_at' => $end + 100],
    ],
    'ZNEWS_VIEW_SESSIONS/valid-guest' => [
        'view_id' => 'valid-guest', 'status' => 'COMPLETED', 'result' => 'VALID',
        'viewer_uid' => '', 'self_view' => false, 'duplicate' => false,
        'bot_detected' => false, 'guest_spam' => false, 'revenue_share_eligible' => true,
        'risk_score' => 10, 'active_seconds' => 25,
        'created_at' => $start + 100, 'completed_at' => $start + 130,
    ],
    'ZNEWS_VIEW_SESSIONS/creator-self' => [
        'view_id' => 'creator-self', 'status' => 'COMPLETED', 'result' => 'VALID',
        'viewer_uid' => 'creator-a', 'viewer_class' => 'CREATOR', 'self_view' => true,
        'duplicate' => false, 'bot_detected' => false, 'guest_spam' => false,
        'revenue_share_eligible' => false, 'risk_score' => 0, 'active_seconds' => 30,
        'created_at' => $start + 200, 'completed_at' => $start + 240,
    ],
    'ZNEWS_VIEW_SESSIONS/duplicate-guest' => [
        'view_id' => 'duplicate-guest', 'status' => 'COMPLETED', 'result' => 'SUSPICIOUS',
        'viewer_uid' => '', 'self_view' => false, 'duplicate' => true,
        'bot_detected' => false, 'guest_spam' => false, 'revenue_share_eligible' => true,
        'risk_score' => 60, 'active_seconds' => 20,
        'created_at' => $start + 300, 'completed_at' => $start + 330,
    ],
    'ZNEWS_VIEW_SESSIONS/spam-guest' => [
        'view_id' => 'spam-guest', 'status' => 'BLOCKED', 'result' => 'INVALID',
        'viewer_uid' => '', 'self_view' => false, 'duplicate' => false,
        'bot_detected' => false, 'guest_spam' => true, 'revenue_share_eligible' => false,
        'risk_score' => 100, 'risk_reasons' => ['GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'],
        'active_seconds' => 0, 'created_at' => $start + 400, 'completed_at' => $start + 401,
    ],
    'ZNEWS_VIEW_SESSIONS/pending-guest' => [
        'view_id' => 'pending-guest', 'status' => 'STARTED', 'result' => 'PENDING',
        'viewer_uid' => '', 'self_view' => false, 'duplicate' => false,
        'bot_detected' => false, 'guest_spam' => false, 'revenue_share_eligible' => true,
        'risk_score' => 5, 'active_seconds' => 5,
        'created_at' => $start + 500, 'completed_at' => 0,
    ],
    'ZNEWS_VIEW_SESSIONS/future-guest' => [
        'view_id' => 'future-guest', 'status' => 'COMPLETED', 'result' => 'VALID',
        'viewer_uid' => '', 'self_view' => false, 'duplicate' => false,
        'bot_detected' => false, 'guest_spam' => false, 'revenue_share_eligible' => true,
        'risk_score' => 0, 'active_seconds' => 30,
        'created_at' => $end + 100, 'completed_at' => $end + 140,
    ],
];

$metricsResult = znews_weekly_review_creator_metrics('creator-a', $period);
$metrics = (array)($metricsResult['metrics'] ?? []);
weekly_expect(!empty($metricsResult['ok']), 'Weekly view metrics fixture failed to calculate.');
weekly_expect(($metrics['post_count'] ?? -1) === 1, 'Post count must include posts created inside the selected UTC week.');
weekly_expect(($metrics['raw_views'] ?? -1) === 5, 'Raw views must include only view sessions inside the selected UTC week.');
weekly_expect(($metrics['eligible_views'] ?? -1) === 1, 'One valid guest view must be eligible.');
weekly_expect(($metrics['invalid_views'] ?? -1) === 2, 'Duplicate and spam guest views must be invalid.');
weekly_expect(($metrics['spam_views'] ?? -1) === 1, 'Guest window spam must be counted separately.');
weekly_expect(($metrics['duplicate_views'] ?? -1) === 1, 'Duplicate views must be counted separately.');
weekly_expect(($metrics['creator_views_excluded'] ?? -1) === 1, 'Authenticated creator views must be excluded.');
weekly_expect(($metrics['self_views_excluded'] ?? -1) === 1, 'Creator self-view must be excluded.');
weekly_expect(($metrics['pending_views'] ?? -1) === 1, 'Incomplete view sessions must remain pending.');
weekly_expect(($metricsResult['source_view_count'] ?? -1) === 6, 'Source audit count must include the future record before period filtering.');

$engine = weekly_source('api/znews/lib/creator_weekly_reviews.php');
$mine = weekly_source('api/znews/reviews/mine.php');
$gateway = weekly_source('api/admin/zsky24_creator_admin.php');
$adminJs = weekly_source('api/admin/assets/zsky24-admin.js');
$adminCss = weekly_source('api/admin/assets/zsky24-admin.css');
$index = weekly_source('znews/index.html');
$bootstrap = weekly_source('znews/assets/znews-bootstrap.js');
$weeklyJs = weekly_source('znews/assets/znews-weekly-review.js');
$embeddedWorker = weekly_source('znews/sw.js');
$standaloneWorker = weekly_source('znews/sw-root.js');
$deploy = weekly_source('.github/workflows/cpanel-production-deploy.yml');

foreach ([
    'ZNEWS_WEEKLY_REVIEW_PERIODS/',
    'ZNEWS_WEEKLY_REVIEWS/',
    'ZNEWS_WEEKLY_REVIEWS_BY_CREATOR/',
    'UNDER_REVIEW',
    'APPROVED',
    'HELD',
    'creator_views_excluded',
    'self_views_excluded',
    'traffic_share_ppm',
    'revenue_share_eligible',
] as $marker) {
    weekly_expect(str_contains($engine, $marker), "Weekly review engine marker is missing: {$marker}");
}
weekly_expect(str_contains($engine, '$viewerUid !=='), 'Authenticated creator views are not explicitly excluded.');
weekly_expect(str_contains($engine, "['duplicate']"), 'Duplicate views are not excluded.');
weekly_expect(str_contains($engine, "['guest_spam']"), 'Guest spam views are not excluded.');
weekly_expect(str_contains($engine, "['bot_detected']"), 'Bot views are not excluded.');
weekly_expect(str_contains($engine, 'ZNEWS_WEEKLY_PERIOD_NOT_COMPLETED'), 'Open weeks must not be finalized.');
weekly_expect(str_contains($engine, 'fb_get_with_etag') && str_contains($engine, 'fb_put_if_match'), 'Weekly review status/locks must use optimistic concurrency.');

foreach (['USERS/', 'WALLETS/', 'WALLET_', 'ZNEWS_BALANCES', 'ZNEWS_LEDGER', 'ZNEWS_TRANSFER_REQUESTS'] as $forbidden) {
    weekly_expect(!str_contains($engine, $forbidden), "Weekly review engine touches forbidden financial/core storage: {$forbidden}");
}
weekly_expect(!preg_match('/amount_(?:micros|usd)|revenue_(?:micros|usd)|payout_amount/i', $engine), 'Weekly review engine must not calculate money.');

weekly_expect(str_contains($mine, "znews/reviews/mine.php") === false, 'Creator endpoint unexpectedly references itself.');
weekly_expect(str_contains($mine, 'auth_require_user(false)'), 'Creator weekly report must use the existing authenticated Z-Pay session.');
weekly_expect(str_contains($mine, 'money_fields_present'), 'Creator response must state that money fields are absent.');

foreach (['weekly_periods', 'weekly_review', 'weekly_generate', 'weekly_status'] as $action) {
    weekly_expect(str_contains($gateway, "'{$action}'"), "Admin gateway action is missing: {$action}");
}
weekly_expect(str_contains($gateway, 'X-CSRF-TOKEN'), 'Weekly admin writes lost CSRF protection.');
weekly_expect(str_contains($gateway, 'auth_require_admin_session(true)'), 'Weekly admin actions lost live admin authorization.');
weekly_expect(str_contains($gateway, 'creator_payout_batches.php'), 'Existing five-creator payout preflight was removed.');

foreach (['Weekly reviews', 'Generate review', 'Raw / eligible', 'Invalid / spam', 'Creator / self excluded'] as $marker) {
    weekly_expect(str_contains($adminJs, $marker), "Admin weekly review UI marker is missing: {$marker}");
}
weekly_expect(str_contains($adminJs, 'BATCH_LIMIT = 5'), 'Five-creator payout preview limit changed.');
weekly_expect(str_contains($adminJs, 'No revenue, balance or payout amount is calculated here.'), 'Admin UI must state that weekly review has no money calculation.');
weekly_expect(str_contains($adminCss, '.zsky-weekly-stat,.zsky-weekly-status{display:grid;gap:5px'), 'Weekly labels and values are not separated into readable blocks.');
weekly_expect(str_contains($adminCss, '.zsky-status-review::after{content:"Under review"'), 'Machine review status is not humanized on the mobile card.');
weekly_expect(str_contains($adminCss, 'Current-week activity appears after that UTC week closes.'), 'Completed-week scope note is missing from the admin UI.');
weekly_expect(str_contains($adminCss, '.zsky-admin-hero{flex-direction:column;align-items:stretch'), 'Mobile creator-control hero does not stack cleanly.');
weekly_expect(str_contains($adminCss, '.zsky-weekly-row .zsky-admin-actions{display:grid;grid-template-columns:1fr 1fr'), 'Mobile review actions are not aligned equally.');

weekly_expect(str_contains($index, '>Weekly performance<'), 'Creator menu does not expose weekly performance.');
weekly_expect(str_contains($index, 'Creator review policy'), 'Creator review policy page is missing.');
weekly_expect(str_contains($index, 'Z Sky 24 does not maintain a creator wallet'), 'No-balance policy is missing.');
weekly_expect(!str_contains($index, '>Creator balance<'), 'Legacy creator balance label remains visible.');
weekly_expect(!str_contains($index, 'Transfer to Z-Pay balance'), 'Legacy transfer action remains visible.');
weekly_expect(!str_contains($index, '৳0.01–৳0.03'), 'Legacy per-ad credit promise remains visible.');
weekly_expect(str_contains($bootstrap, 'znews-weekly-review.js?v=1'), 'Weekly creator report JavaScript is not loaded after creator authentication.');
weekly_expect(str_contains($bootstrap, 'znews-weekly-review.css?v=1'), 'Weekly creator report stylesheet is not loaded after creator authentication.');

weekly_expect(str_contains($weeklyJs, 'retiredBalanceSummary'), 'Legacy Z Sky balance request is not disabled.');
weekly_expect(str_contains($weeklyJs, "znews/reviews/mine.php"), 'Creator weekly report endpoint is not wired.');
weekly_expect(str_contains($weeklyJs, 'Creator views excluded'), 'Creator exclusion metric is not shown.');
weekly_expect(str_contains($weeklyJs, 'No money or balance is calculated'), 'Creator UI does not explain the non-financial review.');

foreach ([
    [$embeddedWorker, 'zsky24-embedded-shell-v27'],
    [$standaloneWorker, 'zsky24-standalone-shell-v27'],
] as [$worker, $cacheName]) {
    weekly_expect(str_contains($worker, $cacheName), "Weekly review service-worker generation is missing: {$cacheName}");
    weekly_expect(str_contains($worker, 'znews-weekly-review.js?v=1'), 'Weekly review JavaScript is missing from a service-worker shell.');
    weekly_expect(str_contains($worker, 'znews-weekly-review.css?v=1'), 'Weekly review CSS is missing from a service-worker shell.');
    weekly_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'A service worker may cache weekly API responses.');
}

weekly_expect(str_contains($deploy, 'tests/zsky24_weekly_creator_review_test.php'), 'Production deploy does not run the weekly creator review gate.');
weekly_expect(str_contains($deploy, 'api/znews/lib/creator_weekly_reviews.php'), 'Production package does not verify the weekly review engine.');
weekly_expect(str_contains($deploy, 'api/znews/reviews/mine.php'), 'Production package does not verify the creator weekly endpoint.');

if ($failures) {
    fwrite(STDERR, "Z Sky 24 weekly creator review contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z Sky 24 weekly creator review contract passed.\n";
