<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once dirname(__DIR__) . '/api/lib/mysql_firebase.php';

function mysql_stage_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fixture = [
    'USERS' => [
        'U2' => ['score' => 20, 'enabled' => true],
        'U1' => ['score' => 10, 'enabled' => false],
    ],
    'LIST' => ['first', null, 'third'],
    'FLAG' => true,
];
$rows = mysql_fb_flatten_value('', $fixture);
$rebuilt = mysql_fb_rebuild_rows($rows, '');
mysql_stage_expect($rebuilt === $fixture, 'flattened data must rebuild without changing values or sparse lists');
mysql_stage_expect(mysql_fb_flatten_value('EMPTY', []) === [], 'empty Firebase containers must not be persisted');

$ordered = mysql_fb_apply_query($fixture['USERS'], [
    'orderBy' => '"score"',
    'startAt' => '10',
    'limitToFirst' => '1',
]);
mysql_stage_expect(array_keys((array)$ordered) === ['U1'], 'ordered range and limit query behavior changed');

$equal = mysql_fb_apply_query($fixture['USERS'], [
    'orderBy' => '"enabled"',
    'equalTo' => 'true',
]);
mysql_stage_expect(array_keys((array)$equal) === ['U2'], 'equalTo query behavior changed');

$rootPatch = mysql_fb_patch_targets('', [
    'USER_WALLETS/U1/balance' => 50,
    'USERS/U1/status' => 'ACTIVE',
]);
mysql_stage_expect(
    array_keys($rootPatch) === ['USER_WALLETS/U1/balance', 'USERS/U1/status'],
    'root multi-location patch paths changed'
);

$overlapRejected = false;
try {
    mysql_fb_patch_targets('', ['USERS/U1' => [], 'USERS/U1/status' => 'ACTIVE']);
} catch (InvalidArgumentException) {
    $overlapRejected = true;
}
mysql_stage_expect($overlapRejected, 'overlapping multi-location updates must be rejected');
mysql_stage_expect(mysql_fb_etag_version('W/"42"') === 42, 'weak ETag parsing failed');
mysql_stage_expect(mysql_fb_etag_version('invalid') === null, 'invalid ETag must be rejected');

$root = dirname(__DIR__);
$firebaseSource = (string)file_get_contents($root . '/api/lib/firebase.php');
$adapterSource = (string)file_get_contents($root . '/api/lib/mysql_firebase.php');
$migrationSource = (string)file_get_contents($root . '/api/tools/migrate_firebase_to_mysql.php');
$schema = (string)file_get_contents($root . '/database/mysql/001_firebase_compat.sql');
$configExample = (string)file_get_contents($root . '/api/config.example.php');
$htaccess = (string)file_get_contents($root . '/.htaccess');

mysql_stage_expect(
    str_contains($configExample, "define('DATASTORE_DRIVER', 'firebase')"),
    'production-compatible configuration must default to Firebase'
);
mysql_stage_expect(
    str_contains($firebaseSource, "fb_datastore_driver() === 'mysql'"),
    'Firebase compatibility layer must require an explicit MySQL driver selection'
);
mysql_stage_expect(
    str_contains($adapterSource, 'mysql_fb_prune_empty_ancestors'),
    'MySQL deletes must prune empty Firebase-style ancestors'
);
mysql_stage_expect(
    str_contains($migrationSource, "migration_require_constant('MYSQL_MIGRATION_ALLOW_WRITE') !== true"),
    'migration writes must require an explicit private safety switch'
);
mysql_stage_expect(
    str_contains($migrationSource, "migration_require_constant('MIGRATION_TARGET')"),
    'migration writes must be pinned to the stage target'
);
mysql_stage_expect(
    !str_contains($migrationSource, "['shallow' => 'true'],\n        ['X-Firebase-ETag: true']")
        && str_contains($migrationSource, 'mysql_migration_hash($inventory)'),
    'Firebase inventory must not combine incompatible shallow and ETag requests'
);
mysql_stage_expect(
    str_contains($schema, 'ENGINE=InnoDB')
        && str_contains($schema, 'zps_firebase_versions')
        && str_contains($schema, 'zps_environment_guard'),
    'staging schema must use InnoDB, retain CAS versions and pin the environment'
);
mysql_stage_expect(
    str_contains($htaccess, '.stage-not-ready') && str_contains($htaccess, 'X-Robots-Tag'),
    'stage must remain locked and non-indexable until verification passes'
);

echo "mysql stage migration tests passed\n";
