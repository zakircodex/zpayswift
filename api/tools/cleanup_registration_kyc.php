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
    fwrite(STDERR, "KYC cleanup configuration is unavailable.\n");
    exit(1);
}

require_once $apiRoot . '/bootstrap.php';
require_once $apiRoot . '/lib/user_registration_kyc.php';

$dryRun = false;
$statusOnly = false;
$limit = user_registration_kyc_cleanup_batch_limit();
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($argument === '--status') {
        $statusOnly = true;
        continue;
    }
    if (preg_match('/^--limit=(\d{1,4})$/D', $argument, $match) === 1) {
        $limit = max(1, min(1000, (int)$match[1]));
        continue;
    }

    fwrite(STDERR, "Usage: php api/tools/cleanup_registration_kyc.php [--dry-run] [--status] [--limit=100]\n");
    exit(2);
}

if ($statusOnly) {
    $status = user_registration_kyc_cleanup_status();
    foreach (['status', 'last_attempt_at', 'last_completed_at', 'ok', 'scanned', 'deleted_records', 'deleted_files', 'failed'] as $field) {
        if (array_key_exists($field, $status)) {
            $value = is_bool($status[$field]) ? ($status[$field] ? 'true' : 'false') : (string)$status[$field];
            fwrite(STDOUT, $field . '=' . $value . PHP_EOL);
        }
    }
    exit(0);
}

$result = user_registration_kyc_cleanup_run([
    'dry_run' => $dryRun,
    'limit' => $limit,
]);

$fields = [
    'mode' => $dryRun ? 'dry-run' : 'delete',
    'scanned' => (int)$result['scanned'],
    'eligible' => (int)$result['eligible'],
    'claimed' => (int)$result['claimed'],
    'deleted_records' => (int)$result['deleted_records'],
    'deleted_files' => (int)$result['deleted_files'],
    'would_delete' => (int)$result['would_delete'],
    'missing' => (int)$result['missing'],
    'skipped_active' => (int)$result['skipped_active'],
    'skipped_finalized' => (int)$result['skipped_finalized'],
    'skipped_ambiguous' => (int)$result['skipped_ambiguous'],
    'failed' => (int)$result['failed'],
];

foreach ($fields as $name => $value) {
    fwrite(STDOUT, $name . '=' . $value . PHP_EOL);
}

exit(!empty($result['ok']) ? 0 : 1);
