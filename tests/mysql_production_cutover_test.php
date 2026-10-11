<?php
declare(strict_types=1);

function production_cutover_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zpay-production-config-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryRoot, 0700, true) && !is_dir($temporaryRoot)) {
    fwrite(STDERR, "FAIL: unable to create test directory\n");
    exit(1);
}

$runtimePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'config.mysql-candidate.php';
$migrationPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'migration.mysql-production.php';
$sourcePath = $root . '/tests/fixtures/stage_config_source.php';
$builderPath = $root . '/api/tools/build_production_mysql_config.php';

putenv('ZPAY_PRODUCTION_MYSQL_DSN=mysql:host=localhost;dbname=production_test;charset=utf8mb4');
putenv('ZPAY_PRODUCTION_MYSQL_USER=production_user');
putenv('ZPAY_PRODUCTION_MYSQL_PASSWORD=production-password-for-test');
putenv('ZPAY_PRODUCTION_MIGRATION_ALLOW_WRITE=1');
putenv('ZPAY_PRODUCTION_MIGRATION_ALLOW_PRODUCTION_WRITE=1');

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($builderPath)
    . ' --source=' . escapeshellarg($sourcePath)
    . ' --runtime-output=' . escapeshellarg($runtimePath)
    . ' --migration-output=' . escapeshellarg($migrationPath);
exec($command, $output, $exitCode);

putenv('ZPAY_PRODUCTION_MYSQL_DSN');
putenv('ZPAY_PRODUCTION_MYSQL_USER');
putenv('ZPAY_PRODUCTION_MYSQL_PASSWORD');
putenv('ZPAY_PRODUCTION_MIGRATION_ALLOW_WRITE');
putenv('ZPAY_PRODUCTION_MIGRATION_ALLOW_PRODUCTION_WRITE');

production_cutover_expect($exitCode === 0, 'production config builder failed');
production_cutover_expect(is_file($runtimePath) && is_file($migrationPath), 'production config files were not created');

$runtime = (string)file_get_contents($runtimePath);
$migration = (string)file_get_contents($migrationPath);
$migrationTool = (string)file_get_contents($root . '/api/tools/migrate_firebase_to_mysql.php');
$activationTool = (string)file_get_contents($root . '/api/tools/activate_production_mysql_config.php');
$runtimeVerifier = (string)file_get_contents($root . '/scripts/verify_mysql_production_runtime.php');
$cutoverScript = (string)file_get_contents($root . '/scripts/cutover_mysql_production.sh');
$adapter = (string)file_get_contents($root . '/api/lib/mysql_firebase.php');
$firebase = (string)file_get_contents($root . '/api/lib/firebase.php');
$productionSchema = (string)file_get_contents($root . '/database/mysql/001_firebase_compat_production.sql');
$configExample = (string)file_get_contents($root . '/api/config.example.php');
$cpanel = (string)file_get_contents($root . '/.cpanel.yml');

production_cutover_expect(str_contains($runtime, "'APP_ENVIRONMENT', 'production'"), 'production environment marker is missing');
production_cutover_expect(str_contains($runtime, "'DATASTORE_DRIVER', 'mysql'"), 'production candidate does not select MySQL');
production_cutover_expect(str_contains($runtime, "'MYSQL_EXPECTED_ENVIRONMENT', 'PRODUCTION'"), 'production database guard is missing');
production_cutover_expect(str_contains($runtime, 'production-app-key-must-not-copy'), 'production runtime secret was not preserved');
production_cutover_expect(str_contains($runtime, 'production-telegram-token-must-not-copy'), 'production integration setting was not preserved');
production_cutover_expect(str_contains($migration, "'MIGRATION_TARGET', 'production'"), 'production migration target is missing');
production_cutover_expect(str_contains($migration, "'MYSQL_MIGRATION_ALLOW_WRITE', true"), 'general migration write opt-in is missing');
production_cutover_expect(str_contains($migration, "'MYSQL_MIGRATION_ALLOW_PRODUCTION_WRITE', true"), 'production migration write opt-in is missing');
production_cutover_expect(str_contains($migration, "'PRODUCTION_MAINTENANCE_MARKER'"), 'production maintenance marker is missing');
production_cutover_expect(str_contains($migration, 'production-firebase-auth-migration-only'), 'Firebase source credential is missing from private migration config');

production_cutover_expect(
    str_contains($productionSchema, "environment = 'PRODUCTION'")
        && !str_contains($productionSchema, "environment = 'STAGE'"),
    'production schema is not pinned to the PRODUCTION environment'
);
production_cutover_expect(
    str_contains($configExample, "define('APP_ENVIRONMENT', 'production')")
        && str_contains($configExample, "define('MYSQL_EXPECTED_ENVIRONMENT', 'PRODUCTION')"),
    'example configuration does not document the production environment guards'
);
production_cutover_expect(
    str_contains($migrationTool, '--confirm-production')
        && str_contains($migrationTool, '--confirm-final-delta')
        && str_contains($migrationTool, 'MYSQL_MIGRATION_ALLOW_PRODUCTION_WRITE')
        && str_contains($migrationTool, 'PRODUCTION_MAINTENANCE_MARKER'),
    'production migration double confirmation or maintenance guard is missing'
);
production_cutover_expect(
    str_contains($adapter, 'zpay_mysql_assert_expected_environment()'),
    'runtime datastore requests do not enforce the environment guard'
);
production_cutover_expect(
    str_contains($firebase, 'fb_production_mutation_locked')
        && str_contains($firebase, '/home/zedpayhe/public_html/.deploy-in-progress')
        && str_contains($firebase, "'status' => 503"),
    'production maintenance does not pause background datastore writes'
);
production_cutover_expect(
    str_contains($activationTool, 'config.firebase.')
        && str_contains($activationTool, 'activation_write_atomic')
        && str_contains($activationTool, 'Firebase configuration was restored'),
    'atomic activation and rollback contract is incomplete'
);
production_cutover_expect(
    str_contains($runtimeVerifier, "constant('APP_ENVIRONMENT') === 'production'")
        && str_contains($runtimeVerifier, 'zpay_mysql_assert_expected_environment()'),
    'production runtime verifier is incomplete'
);
production_cutover_expect(
    str_contains($cutoverScript, 'ACTIVATE_PRODUCTION_MYSQL')
        && str_contains($cutoverScript, '--mode=final-delta')
        && str_contains($cutoverScript, '.deploy-in-progress')
        && str_contains($cutoverScript, 'maintenance marker remains'),
    'production cutover orchestration does not fail closed'
);
production_cutover_expect(
    str_contains($cpanel, 'REPOPATH=/home/zedpayhe/repositories/zpayswift')
        && str_contains($cpanel, 'DEPLOYPATH=/home/zedpayhe/public_html')
        && !str_contains($cpanel, 'deploy_mysql_stage.sh'),
    'production candidate cPanel contract still targets stage'
);

@unlink($runtimePath);
@unlink($migrationPath);
@rmdir($temporaryRoot);

echo "mysql production cutover tests passed\n";
