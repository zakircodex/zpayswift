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
    fwrite(STDERR, "Z Sky media backfill configuration is unavailable.\n");
    exit(1);
}

require_once $apiRoot . '/znews/bootstrap.php';
require_once $apiRoot . '/znews/lib/media_derivative_backfill.php';

$dryRun = true;
$limit = 10;
$cursor = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($argument === '--apply') {
        $dryRun = false;
        continue;
    }
    if (preg_match('/^--limit=(\d{1,2})$/D', $argument, $match) === 1) {
        $limit = max(1, min(25, (int)$match[1]));
        continue;
    }
    if (preg_match('/^--cursor=([A-Za-z0-9_-]{1,160})$/D', $argument, $match) === 1) {
        $cursor = (string)$match[1];
        continue;
    }
    fwrite(STDERR, "Usage: php api/tools/backfill_znews_media_derivatives.php [--dry-run|--apply] [--limit=10] [--cursor=MEDIA_ID]\n");
    exit(2);
}

$result = znews_media_derivative_backfill_run([
    'dry_run' => $dryRun,
    'limit' => $limit,
    'cursor' => $cursor,
]);
foreach ([
    'mode', 'scanned', 'would_update', 'unchanged', 'skipped', 'errors',
    'original_bytes', 'optimized_bytes', 'saved_bytes', 'has_more', 'next_cursor',
] as $field) {
    $value = $result[$field] ?? '';
    fwrite(STDOUT, $field . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) . PHP_EOL);
}
if (empty($result['ok'])) {
    fwrite(STDERR, 'error=' . (string)($result['code'] ?? 'ZNEWS_MEDIA_BACKFILL_FAILED') . PHP_EOL);
    exit(1);
}
exit(0);
