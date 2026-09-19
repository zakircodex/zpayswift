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
    fwrite(STDERR, "Birthday Universe cleanup configuration is unavailable.\n");
    exit(1);
}
require_once $apiRoot . '/znews/bootstrap.php';
require_once $apiRoot . '/znews/lib/birthday.php';
require_once $apiRoot . '/znews/lib/birthday_media.php';
require_once $apiRoot . '/znews/lib/birthday_cleanup.php';

$dryRun = false;
$statusOnly = false;
$limit = 100;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--status') {
        $statusOnly = true;
    } elseif (preg_match('/^--limit=(\d{1,4})$/D', $argument, $matches) === 1) {
        $limit = max(1, min(1000, (int)$matches[1]));
    } else {
        fwrite(STDERR, "Usage: php api/tools/cleanup_birthday_universes.php [--dry-run] [--status] [--limit=100]\n");
        exit(2);
    }
}
if ($statusOnly) {
    $status = fb_get(birthday_path('CLEANUP_LEASES') . '/LAST_RUN');
    echo json_encode(is_array($status) ? $status : ['status' => 'never_run'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
$result = birthday_cleanup_run($dryRun, $limit);
foreach ($result as $field => $value) {
    if (is_scalar($value)) {
        fwrite(STDOUT, $field . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value) . PHP_EOL);
    }
}
exit(!empty($result['ok']) ? 0 : 1);
