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
    fwrite(STDERR, "Z Sky comment audit private configuration is unavailable.\n");
    exit(1);
}

require_once $apiRoot . '/znews/bootstrap.php';
require_once $apiRoot . '/znews/lib/engagement.php';
require_once $apiRoot . '/znews/lib/comments.php';
require_once $apiRoot . '/znews/lib/comments/legacy_pending_audit.php';

$dryRun = true;
$limit = 50;
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
    if (preg_match('/^--limit=(\d{1,3})$/D', $argument, $match) === 1) {
        $limit = max(1, min(100, (int)$match[1]));
        continue;
    }
    if (preg_match('/^--cursor=([A-Za-z0-9_-]{1,160})$/D', $argument, $match) === 1) {
        $cursor = (string)$match[1];
        continue;
    }
    fwrite(STDERR, "Usage: php api/tools/audit_znews_pending_comments.php [--dry-run|--apply] [--limit=50] [--cursor=COMMENT_ID]\n");
    exit(2);
}

$result = znews_legacy_comment_audit_run([
    'dry_run' => $dryRun,
    'limit' => $limit,
    'cursor' => $cursor,
]);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit(empty($result['ok']) ? 1 : 0);
