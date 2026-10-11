<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function production_runtime_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$configPath = $argv[1] ?? '';
if ($configPath === '' || !is_file($configPath) || !is_readable($configPath) || is_link($configPath)) {
    production_runtime_fail('Private production runtime configuration is unavailable.');
}

require $configPath;
require dirname(__DIR__) . '/api/lib/mysql.php';

$valid = defined('APP_ENVIRONMENT')
    && constant('APP_ENVIRONMENT') === 'production'
    && defined('APP_PUBLIC_ORIGIN')
    && constant('APP_PUBLIC_ORIGIN') === 'https://zpayswift.com'
    && defined('DATASTORE_DRIVER')
    && constant('DATASTORE_DRIVER') === 'mysql'
    && defined('MYSQL_EXPECTED_ENVIRONMENT')
    && constant('MYSQL_EXPECTED_ENVIRONMENT') === 'PRODUCTION'
    && defined('STAGE_AUTH_OTP_PREVIEW_ENABLED')
    && constant('STAGE_AUTH_OTP_PREVIEW_ENABLED') === false;
if (!$valid) {
    production_runtime_fail('Production runtime guard mismatch.');
}

try {
    zpay_mysql_assert_expected_environment();
    $health = zpay_mysql_pdo()->query('SELECT 1')->fetchColumn();
    if ((int)$health !== 1) {
        throw new RuntimeException('Unexpected database health result.');
    }
} catch (Throwable) {
    production_runtime_fail('Production MySQL environment guard failed.');
}

fwrite(STDOUT, "Production runtime guard passed.\n");
