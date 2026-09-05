<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$fakeDb = [];
$reads = [];
$writes = [];
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zsky-weekly-test-' . bin2hex(random_bytes(5));
mkdir($cacheDir, 0700, true);
define('ZNEWS_WEEKLY_PREVIEW_CACHE_DIR', $cacheDir);

function preview_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function znews_now(): int { return strtotime('2026-09-02 12:00:00 UTC'); }
function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string { return trim((string)$value); }
function znews_creator_normalize_status($value): string {
    return strtoupper(trim((string)$value)) === 'BLOCKED' ? 'BLOCKED' : 'ACTIVE';
}
function znews_view_risk_threshold(): int { return 70; }
function znews_view_path(string $viewId): string { return 'ZNEWS_VIEW_SESSIONS/' . $viewId; }
function fb_get(string $path, array $query = []) {
    global $fakeDb, $reads;
    $reads[] = ['path' => $path, 'query' => $query];
    return $fakeDb[$path] ?? null;
}
function fb_put(string $path, mixed $data): bool {
    global $fakeDb, $writes;
    $fakeDb[$path] = $data;
    $writes[$path] = $data;
    return true;
}
function fb_patch(string $path, array $updates): bool {
    global $writes;
    $writes[$path] = $updates;
    return true;
}

require_once $root . '/api/znews/lib/creator_weekly_reviews.php';
require_once $root . '/api/znews/lib/weekly_live_projection_backfill.php';

$period = znews_weekly_review_period();
$creatorUid = 'creator-a';
$projectionPath = znews_weekly_live_projection_path($creatorUid, (string)$period['period_id']);
$fakeDb['ZNEWS_USER_POSTS/' . $creatorUid] = [
    'post-a' => ['post_id' => 'post-a', 'created_at' => (int)$period['period_start_at'] + 10],
];

foreach ([100, 1000, 5000] as $datasetSize) {
    $rows = [];
    for ($index = 0; $index < $datasetSize; $index++) {
        $rows['view-' . $index] = [
            'schema_version' => 1,
            'view_id' => 'view-' . $index,
            'status' => $index % 5 === 4 ? 'STARTED' : 'COMPLETED',
            'result' => $index % 5 === 0 ? 'VALID' : ($index % 5 === 4 ? 'PENDING' : 'INVALID'),
            'creator_view' => $index % 5 === 1,
            'self_view' => $index % 5 === 1,
            'duplicate' => $index % 5 === 2,
            'spam_view' => $index % 5 === 3,
            'bot_detected' => false,
            'revenue_share_eligible' => $index % 5 === 0,
            'risk_blocked' => $index % 5 === 3,
            'active_seconds' => 25,
            'created_at' => (int)$period['period_start_at'] + 20 + $index,
            'completed_at' => $index % 5 === 4 ? 0 : (int)$period['period_start_at'] + 30 + $index,
            'updated_at' => (int)$period['period_start_at'] + 30 + $index,
        ];
    }
    $fakeDb[$projectionPath] = $rows;
    $reads = [];
    $result = znews_weekly_review_projection_metrics($creatorUid, $period);
    preview_expect(!empty($result['ok']), "Projection metrics failed for {$datasetSize} rows.");
    preview_expect(count($reads) === 2, "RTDB read count was not constant for {$datasetSize} rows.");
    preview_expect(($result['firebase_read_count'] ?? 0) === 2, "Reported RTDB reads were not two for {$datasetSize} rows.");
    preview_expect(($reads[0]['query']['orderBy'] ?? '') === json_encode('created_at') && ($reads[0]['query']['limitToFirst'] ?? 0) === 501, "Current-week post query was not bounded for {$datasetSize} rows.");
    preview_expect(($reads[1]['query']['orderBy'] ?? '') === json_encode('$key') && ($reads[1]['query']['limitToFirst'] ?? 0) === 5001, "Current-week view projection query was not bounded for {$datasetSize} rows.");
    preview_expect(count(array_filter($reads, static fn(array $read): bool => str_starts_with($read['path'], 'ZNEWS_POST_VIEWS/') || str_starts_with($read['path'], 'ZNEWS_VIEW_SESSIONS/'))) === 0, "Runtime used legacy N+1 reads for {$datasetSize} rows.");
}

$sourceView = [
    'view_id' => 'privacy-view',
    'creator_uid' => $creatorUid,
    'status' => 'COMPLETED',
    'result' => 'VALID',
    'viewer_uid' => '',
    'viewer_class' => 'GUEST',
    'self_view' => false,
    'duplicate' => false,
    'bot_detected' => false,
    'guest_spam' => false,
    'revenue_share_eligible' => true,
    'risk_score' => 1,
    'risk_reasons' => [],
    'active_seconds' => 24,
    'created_at' => (int)$period['period_start_at'] + 80,
    'completed_at' => (int)$period['period_start_at'] + 110,
    'fingerprint_hash' => 'PRIVATE_FINGERPRINT',
    'ip_hash' => 'PRIVATE_IP',
    'ua_hash' => 'PRIVATE_UA',
    'session_hash' => 'PRIVATE_SESSION',
    'token_hash' => 'PRIVATE_TOKEN',
];
$safeProjection = znews_weekly_live_projection_row($sourceView, $creatorUid);
foreach (['fingerprint_hash', 'ip_hash', 'ua_hash', 'session_hash', 'token_hash', 'viewer_uid', 'risk_score', 'risk_reasons'] as $privateField) {
    preview_expect(!array_key_exists($privateField, $safeProjection), "Projection contains private field {$privateField}.");
}

$fakeDb[$projectionPath] = ['privacy-view' => $safeProjection];
$reads = [];
$firstPreview = znews_weekly_review_creator_live_preview($creatorUid, [
    'name' => 'Creator A',
    'status' => 'ACTIVE',
]);
preview_expect(!empty($firstPreview['ok']) && empty($firstPreview['cache_hit']), 'Cold preview did not use the bounded projection path.');
preview_expect(($firstPreview['firebase_read_count'] ?? -1) === 2, 'Cold preview did not report exactly two Firebase reads.');
$reads = [];
$cachedPreview = znews_weekly_review_creator_live_preview($creatorUid, [
    'name' => 'Creator A',
    'status' => 'ACTIVE',
]);
preview_expect(!empty($cachedPreview['ok']) && !empty($cachedPreview['cache_hit']), 'Warm preview cache was not used.');
preview_expect(count($reads) === 0, 'Warm preview cache unexpectedly read Firebase metrics.');

$fakeDb['ZNEWS_POST_VIEWS/post-a'] = [
    'privacy-view' => ['view_id' => 'privacy-view', 'created_at' => $sourceView['created_at']],
];
$fakeDb['ZNEWS_VIEW_SESSIONS/privacy-view'] = $sourceView;
$writes = [];
$dryRun = znews_weekly_live_projection_backfill($creatorUid, $period, true);
preview_expect(!empty($dryRun['ok']) && ($dryRun['would_update'] ?? -1) === 0, 'Idempotent projection dry-run changed a current row.');
preview_expect(($dryRun['canonical_roots_modified'] ?? null) === [], 'Backfill reports canonical mutations.');

foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheDir);

if ($failures) {
    fwrite(STDERR, "Z Sky weekly mobile preview performance test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z Sky weekly mobile preview performance test passed.\n";
