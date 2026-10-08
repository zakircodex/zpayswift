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

$etagRead = mysql_fb_request('GET', 'USERS/U1', null, [], ['X-Firebase-ETag: true'], true);
$etag = (string)($etagRead['headers']['etag'] ?? '');
mysql_integration_expect($etag !== '', 'ETag read failed');

$cas = mysql_fb_put_path('USERS/U1/score', 11, mysql_fb_version_etag(0));
mysql_integration_expect(!empty($cas['ok']), 'first CAS write failed');
$stale = mysql_fb_put_path('USERS/U1/score', 12, mysql_fb_version_etag(0));
mysql_integration_expect(empty($stale['ok']) && (int)$stale['status'] === 412, 'stale CAS write was accepted');
mysql_integration_expect(mysql_fb_get_path('USERS/U1/score') === 11, 'stale CAS changed the value');

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

mysql_fb_put_path('', null);
mysql_integration_expect(mysql_fb_get_path('') === null, 'root delete failed');

echo "mysql firebase integration tests passed\n";
