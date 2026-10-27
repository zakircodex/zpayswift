<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit('Not Found');
}

$apiRoot = dirname(__DIR__);
require_once $apiRoot . '/lib/app_paths.php';
$privateConfigPath = app_private_config_path();
if (!is_file($privateConfigPath) || !is_readable($privateConfigPath)) {
    fwrite(STDERR, "Storage cleanup configuration is unavailable.\n");
    exit(1);
}

require_once $apiRoot . '/bootstrap.php';
require_once $apiRoot . '/lib/add_money.php';
require_once $apiRoot . '/lib/support.php';
require_once $apiRoot . '/lib/storage_cleanup.php';

$dryRun = false;
$statusOnly = false;
$requestedLimit = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($argument === '--status') {
        $statusOnly = true;
        continue;
    }
    if (preg_match('/^--limit=(\d{1,3})$/D', $argument, $matches) === 1) {
        $requestedLimit = (int)$matches[1];
        continue;
    }

    fwrite(STDERR, "Usage: php api/tools/cleanup_storage.php [--dry-run] [--status] [--limit=100]\n");
    exit(2);
}

$enabled = storage_cleanup_automatic_enabled();
$categories = storage_cleanup_automatic_categories();
$configuredLimit = storage_cleanup_automatic_batch_limit();
$limit = $requestedLimit === null
    ? $configuredLimit
    : max(1, min($configuredLimit, $requestedLimit));

if ($statusOnly) {
    fwrite(STDOUT, 'enabled=' . ($enabled ? 'true' : 'false') . PHP_EOL);
    fwrite(STDOUT, 'retention_days=' . storage_cleanup_retention_days() . PHP_EOL);
    fwrite(STDOUT, 'batch_limit=' . $configuredLimit . PHP_EOL);
    fwrite(STDOUT, 'categories=' . implode(',', $categories) . PHP_EOL);
    exit($enabled && $categories !== [] ? 0 : 3);
}

if (!$dryRun && !$enabled) {
    fwrite(STDERR, "Automatic storage cleanup is disabled.\n");
    exit(3);
}
if ($categories === []) {
    fwrite(STDERR, "Automatic storage cleanup has no valid categories.\n");
    exit(3);
}

$lock = storage_cleanup_acquire_lock();
if ($lock === false) {
    fwrite(STDOUT, "status=busy\n");
    exit(0);
}

try {
    if ($dryRun) {
        $scan = storage_cleanup_scan($categories);
        $result = [
            'mode' => 'dry-run',
            'eligible_files' => max(0, (int)($scan['total_files'] ?? 0)),
            'eligible_bytes' => max(0, (int)($scan['total_bytes'] ?? 0)),
            'scan_limited' => !empty($scan['scan_limited']),
        ];
    } else {
        $result = storage_cleanup_execute(
            $categories,
            'SYSTEM_AUTOMATIC_STORAGE_CLEANUP',
            [
                'limit' => $limit,
                'trigger' => 'AUTOMATIC',
            ]
        );
        $result['mode'] = 'delete';
    }
} catch (Throwable $exception) {
    error_log('Automatic storage cleanup failed: ' . get_class($exception));
    fwrite(STDERR, "Automatic storage cleanup failed safely.\n");
    exit(1);
} finally {
    storage_cleanup_release_lock($lock);
}

$fields = [
    'mode' => (string)($result['mode'] ?? ($dryRun ? 'dry-run' : 'delete')),
    'run_id' => (string)($result['run_id'] ?? ''),
    'eligible_files' => max(0, (int)($result['eligible_files'] ?? 0)),
    'eligible_bytes' => max(0, (int)($result['eligible_bytes'] ?? 0)),
    'attempted_files' => max(0, (int)($result['attempted_files'] ?? 0)),
    'deleted_files' => max(0, (int)($result['deleted_files'] ?? 0)),
    'deleted_bytes' => max(0, (int)($result['deleted_bytes'] ?? 0)),
    'failed_files' => max(0, (int)($result['failed_files'] ?? 0)),
    'remaining_files' => max(0, (int)($result['remaining_files'] ?? 0)),
    'scan_limited' => !empty($result['scan_limited']) ? 'true' : 'false',
];
foreach ($fields as $name => $value) {
    fwrite(STDOUT, $name . '=' . $value . PHP_EOL);
}

exit(!empty($result['ok']) || $dryRun ? 0 : 1);
