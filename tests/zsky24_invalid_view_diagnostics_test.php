<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$diagFakeDb = [];

function diag_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function diag_source(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
}

function znews_now(): int { return strtotime('2026-08-07 14:00:00 UTC'); }
function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string { return trim((string)$value); }
function znews_creator_normalize_status($value): string { return strtoupper(trim((string)$value)) === 'BLOCKED' ? 'BLOCKED' : 'ACTIVE'; }
function znews_view_path(string $viewId): string { return 'ZNEWS_VIEW_SESSIONS/' . trim($viewId); }
function znews_view_risk_threshold(): int { return 70; }
function znews_view_min_read(): int { return 15; }
function znews_view_min_active(): int { return 10; }
function fb_get(string $path) {
    global $diagFakeDb;
    return $diagFakeDb[$path] ?? null;
}

require_once $root . '/api/znews/lib/creator_weekly_reviews.php';
require_once $root . '/api/znews/lib/creator_view_diagnostics.php';

$start = strtotime('2026-08-01 00:00:00 UTC');
$end = strtotime('2026-08-08 00:00:00 UTC');
$period = [
    'period_start_at' => $start,
    'period_end_at' => $end,
];

$diagFakeDb = [
    'ZNEWS_USER_POSTS/creator-a' => [
        'post-1' => ['post_id' => 'post-1', 'created_at' => $start + 60],
    ],
    'ZNEWS_POST_VIEWS/post-1' => [
        'fast' => ['view_id' => 'fast', 'created_at' => $start + 100],
        'duplicate' => ['view_id' => 'duplicate', 'created_at' => $start + 200],
        'spam' => ['view_id' => 'spam', 'created_at' => $start + 300],
        'bot' => ['view_id' => 'bot', 'created_at' => $start + 400],
        'valid' => ['view_id' => 'valid', 'created_at' => $start + 500],
        'creator' => ['view_id' => 'creator', 'created_at' => $start + 600],
    ],
    'ZNEWS_VIEW_SESSIONS/fast' => [
        'view_id' => 'fast', 'status' => 'COMPLETED', 'result' => 'INVALID',
        'viewer_uid' => '', 'viewer_class' => 'GUEST', 'self_view' => false,
        'duplicate' => false, 'bot_detected' => false, 'guest_spam' => false,
        'revenue_share_eligible' => true, 'risk_score' => 65,
        'risk_reasons' => ['READ_TIME_TOO_SHORT', 'ACTIVE_TIME_TOO_SHORT', 'HEARTBEAT_REQUIRED'],
        'active_seconds' => 1, 'created_at' => $start + 100, 'completed_at' => $start + 105,
        'ip_hash' => 'secret-ip-hash', 'ua_hash' => 'secret-ua-hash', 'fingerprint_hash' => 'secret-fingerprint',
    ],
    'ZNEWS_VIEW_SESSIONS/duplicate' => [
        'view_id' => 'duplicate', 'status' => 'COMPLETED', 'result' => 'SUSPICIOUS',
        'viewer_uid' => '', 'viewer_class' => 'GUEST', 'self_view' => false,
        'duplicate' => true, 'bot_detected' => false, 'guest_spam' => false,
        'revenue_share_eligible' => true, 'risk_score' => 60,
        'risk_reasons' => ['DEDUPLICATE_WINDOW_MATCH', 'DUPLICATE_VIEW'],
        'active_seconds' => 20, 'created_at' => $start + 200, 'completed_at' => $start + 230,
    ],
    'ZNEWS_VIEW_SESSIONS/spam' => [
        'view_id' => 'spam', 'status' => 'BLOCKED', 'result' => 'INVALID',
        'viewer_uid' => '', 'viewer_class' => 'GUEST', 'self_view' => false,
        'duplicate' => false, 'bot_detected' => false, 'guest_spam' => true,
        'revenue_share_eligible' => false, 'risk_score' => 100,
        'risk_reasons' => ['GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'],
        'active_seconds' => 0, 'created_at' => $start + 300, 'completed_at' => $start + 301,
    ],
    'ZNEWS_VIEW_SESSIONS/bot' => [
        'view_id' => 'bot', 'status' => 'BLOCKED', 'result' => 'INVALID',
        'viewer_uid' => '', 'viewer_class' => 'GUEST', 'self_view' => false,
        'duplicate' => false, 'bot_detected' => true, 'guest_spam' => false,
        'revenue_share_eligible' => false, 'risk_score' => 100,
        'risk_reasons' => ['BOT_USER_AGENT'],
        'active_seconds' => 0, 'created_at' => $start + 400, 'completed_at' => $start + 401,
    ],
    'ZNEWS_VIEW_SESSIONS/valid' => [
        'view_id' => 'valid', 'status' => 'COMPLETED', 'result' => 'VALID',
        'viewer_uid' => '', 'viewer_class' => 'GUEST', 'self_view' => false,
        'duplicate' => false, 'bot_detected' => false, 'guest_spam' => false,
        'revenue_share_eligible' => true, 'risk_score' => 5,
        'risk_reasons' => [], 'active_seconds' => 25,
        'created_at' => $start + 500, 'completed_at' => $start + 530,
    ],
    'ZNEWS_VIEW_SESSIONS/creator' => [
        'view_id' => 'creator', 'status' => 'COMPLETED', 'result' => 'VALID',
        'viewer_uid' => 'creator-a', 'viewer_class' => 'CREATOR', 'self_view' => true,
        'duplicate' => false, 'bot_detected' => false, 'guest_spam' => false,
        'revenue_share_eligible' => false, 'risk_score' => 0,
        'risk_reasons' => [], 'active_seconds' => 30,
        'created_at' => $start + 600, 'completed_at' => $start + 640,
    ],
];

$metricsResult = znews_weekly_review_creator_metrics('creator-a', $period);
$diagnostics = znews_view_diagnostics_creator_period('creator-a', $period);

diag_expect(!empty($metricsResult['ok']), 'Weekly metrics fixture must calculate.');
diag_expect(!empty($diagnostics['ok']), 'Invalid view diagnostics fixture must calculate.');
diag_expect(($metricsResult['metrics']['invalid_views'] ?? -1) === 4, 'Fixture must contain exactly four invalid guest views.');
diag_expect(($diagnostics['invalid_total'] ?? -1) === 4, 'Diagnostics total must match the weekly invalid-view total.');

$items = [];
foreach ((array)($diagnostics['items'] ?? []) as $item) {
    if (is_array($item)) {
        $items[(string)($item['code'] ?? '')] = $item;
    }
}
diag_expect(($items['READ_TIME_TOO_SHORT']['count'] ?? 0) === 1, 'Fast exit must be classified as reading time too short.');
diag_expect(($items['DUPLICATE_VIEW']['count'] ?? 0) === 1, 'Duplicate view must have its own diagnostic category.');
diag_expect(($items['GUEST_RATE_LIMIT']['count'] ?? 0) === 1, 'Guest spam/rate limit must have its own diagnostic category.');
diag_expect(($items['BOT_TRAFFIC']['count'] ?? 0) === 1, 'Bot traffic must have its own diagnostic category.');
diag_expect(str_contains((string)($diagnostics['summary'] ?? ''), 'Reading time under 15 seconds'), 'Summary must use a human-readable reading-time reason.');
diag_expect(!empty($diagnostics['privacy_safe']), 'Diagnostics response must explicitly be privacy-safe.');

$encoded = json_encode($diagnostics, JSON_UNESCAPED_SLASHES);
foreach (['ip_hash', 'ua_hash', 'fingerprint_hash', 'risk_reasons', 'visitor_hash', 'session_hash'] as $privateMarker) {
    diag_expect(!str_contains((string)$encoded, $privateMarker), "Diagnostics response leaks private marker: {$privateMarker}");
}

$helper = diag_source('api/znews/lib/creator_view_diagnostics.php');
$calendar = diag_source('api/znews/lib/creator_calendar_reviews.php');
foreach (['USERS/', 'WALLETS/', 'WALLET_', 'ZNEWS_BALANCES', 'ZNEWS_LEDGER', 'ZNEWS_TRANSFER_REQUESTS'] as $forbidden) {
    diag_expect(!str_contains($helper, $forbidden), "Diagnostics helper touches forbidden Z-Pay/financial storage: {$forbidden}");
}
diag_expect(str_contains($calendar, "creator_view_diagnostics.php"), 'Calendar review layer must load the diagnostics helper.');
diag_expect(str_contains($calendar, "'invalid_reason_summary'"), 'Calendar creator rows must expose a privacy-safe invalid reason summary.');
diag_expect(str_contains($calendar, "'invalid_reason_counts'"), 'Calendar period summary must expose privacy-safe invalid reason counts.');
diag_expect(str_contains($calendar, '$reviewReason = $livePreview ? $diagnosticSummary'), 'Live preview must show the diagnostic summary in the existing creator description line.');

if ($failures) {
    fwrite(STDERR, "Z Sky 24 invalid view diagnostics test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z Sky 24 invalid view diagnostics test passed.\n";
