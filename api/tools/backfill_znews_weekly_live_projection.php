<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

$apiRoot = dirname(__DIR__);
require_once $apiRoot . '/lib/app_paths.php';
$privateConfigPath = app_private_config_path();
if (!is_file($privateConfigPath) || !is_readable($privateConfigPath)) {
    fwrite(STDERR, "Z Sky weekly projection backfill configuration is unavailable.\n");
    exit(1);
}

require_once $apiRoot . '/znews/bootstrap.php';
require_once $apiRoot . '/znews/lib/views.php';
require_once $apiRoot . '/znews/lib/creator_weekly_reviews.php';
require_once $apiRoot . '/znews/lib/weekly_live_projection_backfill.php';

$dryRun = true;
$creatorUid = '';
$periodId = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($argument === '--apply') {
        $dryRun = false;
        continue;
    }
    if (preg_match('/^--creator=([A-Za-z0-9_-]{1,160})$/D', $argument, $match) === 1) {
        $creatorUid = (string)$match[1];
        continue;
    }
    if (preg_match('/^--period=(\d{4}-\d{2}-\d{2})$/D', $argument, $match) === 1) {
        $periodId = (string)$match[1];
        continue;
    }
    fwrite(STDERR, "Usage: php api/tools/backfill_znews_weekly_live_projection.php --creator=UID [--period=YYYY-MM-DD] [--dry-run|--apply]\n");
    exit(2);
}

if ($creatorUid === '') {
    fwrite(STDERR, "A creator UID is required.\n");
    exit(2);
}
$period = znews_weekly_review_period($periodId);
$result = znews_weekly_live_projection_backfill($creatorUid, $period, $dryRun);
foreach ([
    'mode', 'period_id', 'posts_scanned', 'view_index_rows_scanned',
    'current_period_views', 'would_update', 'unchanged',
    'malformed_or_missing', 'applied_paths',
] as $field) {
    fwrite(STDOUT, $field . '=' . (string)($result[$field] ?? '') . PHP_EOL);
}
if (empty($result['ok'])) {
    fwrite(STDERR, 'error=' . (string)($result['code'] ?? 'ZNEWS_WEEKLY_PROJECTION_BACKFILL_FAILED') . PHP_EOL);
    exit(1);
}
exit(0);
