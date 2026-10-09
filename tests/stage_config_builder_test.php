<?php
declare(strict_types=1);

function stage_builder_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zpay-stage-config-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    fwrite(STDERR, "FAIL: unable to create test directory\n");
    exit(1);
}

$runtimePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'config.php';
$migrationPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'migration.php';
$sourcePath = $root . '/tests/fixtures/stage_config_source.php';
$builderPath = $root . '/api/tools/build_stage_runtime_config.php';

putenv('ZPAY_STAGE_MYSQL_DSN=mysql:host=localhost;dbname=stage_test;charset=utf8mb4');
putenv('ZPAY_STAGE_MYSQL_USER=stage_user');
putenv('ZPAY_STAGE_MYSQL_PASSWORD=stage-password-for-test');
putenv('ZPAY_STAGE_MIGRATION_ALLOW_WRITE=1');

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($builderPath)
    . ' --source=' . escapeshellarg($sourcePath)
    . ' --output=' . escapeshellarg($runtimePath)
    . ' --migration-output=' . escapeshellarg($migrationPath);
exec($command, $output, $exitCode);

putenv('ZPAY_STAGE_MYSQL_DSN');
putenv('ZPAY_STAGE_MYSQL_USER');
putenv('ZPAY_STAGE_MYSQL_PASSWORD');
putenv('ZPAY_STAGE_MIGRATION_ALLOW_WRITE');

stage_builder_expect($exitCode === 0, 'stage config builder failed');
stage_builder_expect(is_file($runtimePath) && is_file($migrationPath), 'stage config files were not created');

$runtime = (string)file_get_contents($runtimePath);
$migration = (string)file_get_contents($migrationPath);
stage_builder_expect(str_contains($runtime, "'APP_ENVIRONMENT', 'stage'"), 'stage environment marker is missing');
stage_builder_expect(str_contains($runtime, "'DATASTORE_DRIVER', 'mysql'"), 'runtime does not select MySQL');
stage_builder_expect(str_contains($runtime, "'MYR_TO_BDT_RATE', 31.25"), 'non-secret business setting changed');
stage_builder_expect(str_contains($runtime, "'SUBADMIN_API_ALLOW_QUERY_KEY', false"), 'boolean policy setting changed');
stage_builder_expect(str_contains($runtime, "'ADSTERRA_ZSKY24_WEB_ADS_ENABLED', false"), 'stage ads were not disabled');
stage_builder_expect(str_contains($runtime, "'STAGE_AUTH_OTP_PREVIEW_ENABLED', false"), 'stage OTP preview must default to disabled');
stage_builder_expect(str_contains($runtime, "'STAGE_AUTH_OTP_PREVIEW_PHONES', array ("), 'stage OTP preview allowlist is missing');
stage_builder_expect(!str_contains($runtime, 'production-app-key-must-not-copy'), 'production app key leaked to runtime');
stage_builder_expect(!str_contains($runtime, 'production-telegram-token-must-not-copy'), 'Telegram token leaked to runtime');
stage_builder_expect(!str_contains($runtime, 'production-firebase-auth-migration-only'), 'Firebase auth leaked to runtime');
stage_builder_expect(str_contains($migration, 'production-firebase-auth-migration-only'), 'migration source auth is missing');
stage_builder_expect(str_contains($migration, "'MYSQL_MIGRATION_ALLOW_WRITE', true"), 'migration write guard was not enabled');

@unlink($runtimePath);
@unlink($migrationPath);
@rmdir($temporaryRoot);

echo "stage config builder tests passed\n";
