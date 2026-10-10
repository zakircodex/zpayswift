<?php
declare(strict_types=1);

$dsn = trim((string)(getenv('ZPAY_TEST_MYSQL_DSN') ?: ''));
if ($dsn === '') {
    echo "mysql firebase integration test skipped (no test DSN)\n";
    exit(0);
}

define('MYSQL_DSN', $dsn);
define('MYSQL_USER', (string)(getenv('ZPAY_TEST_MYSQL_USER') ?: 'root'));
define('MYSQL_PASSWORD', (string)(getenv('ZPAY_TEST_MYSQL_PASSWORD') ?: ''));
define('MYSQL_TABLE_PREFIX', 'zps_');
define('MYSQL_CONNECT_TIMEOUT_SECONDS', 3);
define('MYSQL_EXPECTED_ENVIRONMENT', 'STAGE');

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require_once dirname(__DIR__) . '/api/lib/mysql_migration.php';

function mysql_integration_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

zpay_mysql_assert_environment('STAGE');
$pdo = zpay_mysql_pdo();
foreach (['migration_checkpoints', 'migration_runs', 'firebase_versions', 'firebase_nodes'] as $table) {
    $pdo->exec('DELETE FROM ' . zpay_mysql_table($table));
}

$initial = [
    'USERS' => [
        'U1' => ['name' => 'Alice', 'score' => 10],
        'U2' => ['name' => 'Bob', 'score' => 20],
    ],
    'LIST' => ['first', null, 'third'],
];
$write = mysql_fb_put_path('', $initial);
mysql_integration_expect(!empty($write['ok']), 'root import failed');
mysql_integration_expect(
    mysql_migration_values_match(mysql_fb_get_path(''), $initial),
    'root import did not round-trip'
);

$shallow = mysql_fb_get_path('', ['shallow' => 'true']);
mysql_integration_expect(array_keys((array)$shallow) === ['LIST', 'USERS'], 'root shallow read exposed its internal row');
mysql_integration_expect(mysql_migration_root_paths() === ['LIST', 'USERS'], 'target root inventory is incorrect');

$etagRead = mysql_fb_request('GET', 'USERS/U1', null, [], ['X-Firebase-ETag: true'], true);
$etag = (string)($etagRead['headers']['etag'] ?? '');
mysql_integration_expect($etag !== '', 'ETag read failed');

$cas = mysql_fb_put_path('USERS/U1/score', 11, mysql_fb_version_etag(0));
mysql_integration_expect(!empty($cas['ok']), 'first CAS write failed');
$stale = mysql_fb_put_path('USERS/U1/score', 12, mysql_fb_version_etag(0));
mysql_integration_expect(empty($stale['ok']) && (int)$stale['status'] === 412, 'stale CAS write was accepted');
mysql_integration_expect(mysql_fb_get_path('USERS/U1/score') === 11, 'stale CAS changed the value');

$missingEtag = mysql_fb_request('GET', 'WATCHED/CHILD', null, [], ['X-Firebase-ETag: true'], true);
mysql_integration_expect(($missingEtag['headers']['etag'] ?? '') === '"0"', 'missing-path ETag was not registered');
mysql_fb_put_path('WATCHED', ['CHILD' => 'current']);
$missingStale = mysql_fb_put_path('WATCHED/CHILD', 'stale', '"0"');
mysql_integration_expect(
    empty($missingStale['ok']) && (int)$missingStale['status'] === 412,
    'ancestor mutation did not invalidate a registered missing-path ETag'
);

$patch = mysql_fb_patch_path('', [
    'USER_WALLETS/U1/balance' => 100.5,
    'USERS/U1/status' => 'ACTIVE',
]);
mysql_integration_expect(!empty($patch['ok']), 'root multi-location patch failed');
mysql_integration_expect(mysql_fb_get_path('USER_WALLETS/U1/balance') === 100.5, 'wallet patch is missing');
mysql_integration_expect(mysql_fb_get_path('USERS/U1/status') === 'ACTIVE', 'user patch is missing');

mysql_fb_put_path('SCALAR', 'old');
mysql_fb_put_path('SCALAR/CHILD', 'new');
mysql_integration_expect(mysql_fb_get_path('SCALAR') === ['CHILD' => 'new'], 'scalar ancestor did not become an object');
mysql_fb_put_path('SCALAR/CHILD', null);
mysql_integration_expect(mysql_fb_get_path('SCALAR') === null, 'empty ancestors were not pruned after delete');

$query = mysql_fb_get_path('USERS', [
    'orderBy' => '"score"',
    'startAt' => 12,
    'limitToFirst' => 1,
]);
mysql_integration_expect(array_keys((array)$query) === ['U2'], 'bounded ordered query returned the wrong row');

$keyQuery = mysql_fb_get_path('USERS', [
    'orderBy' => '"$key"',
    'endAt' => '"U1"',
    'limitToLast' => 1,
]);
mysql_integration_expect(array_keys((array)$keyQuery) === ['U1'], 'bounded key query returned the wrong row');

mysql_fb_put_path('OLD_TREE', ['stale' => true]);
$inventoryDiff = mysql_migration_inventory_diff(
    ['LIST', 'USER_WALLETS', 'USERS', 'WATCHED'],
    mysql_migration_root_paths()
);
mysql_integration_expect($inventoryDiff['target_only'] === ['OLD_TREE'], 'target-only root tree was not detected');
foreach ($inventoryDiff['target_only'] as $targetOnlyPath) {
    mysql_fb_put_path($targetOnlyPath, null);
}
mysql_integration_expect(
    mysql_migration_root_paths() === ['LIST', 'USERS', 'USER_WALLETS', 'WATCHED'],
    'target inventory did not converge after deleting a target-only root tree'
);

mysql_fb_put_path('', null);
mysql_integration_expect(mysql_fb_get_path('') === null, 'root delete failed');

echo "mysql firebase integration tests passed\n";
