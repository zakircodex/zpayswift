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
    fwrite(STDERR, "MFS counter configuration is unavailable.\n");
    exit(1);
}

require_once $apiRoot . '/bootstrap.php';
require_once $apiRoot . '/lib/mfs.php';

$result = mfs_status_counts_rebuild(true);
if (empty($result['ok'])) {
    fwrite(STDERR, "MFS status counter rebuild failed.\n");
    exit(1);
}

$counts = (array)($result['counts'] ?? []);
foreach (['pending', 'processing', 'done', 'failed'] as $status) {
    fwrite(STDOUT, $status . '=' . (int)($counts[$status] ?? 0) . PHP_EOL);
}

exit(0);
