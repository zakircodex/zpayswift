<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once dirname(__DIR__) . '/api/lib/mysql_migration.php';

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

$restOrdered = mysql_fb_apply_query([
    'B' => ['score' => 10],
    'A' => ['score' => 20],
], [
    'orderBy' => '"score"',
    'limitToFirst' => '2',
]);
mysql_stage_expect(
    array_keys((array)$restOrdered) === ['A', 'B'],
    'filtered MySQL results must retain Firebase REST key serialization order'
);

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
$inventoryDiff = mysql_migration_inventory_diff(['USERS', 'WALLETS'], ['OLD_TREE', 'USERS']);
mysql_stage_expect(
    $inventoryDiff === ['source_only' => ['WALLETS'], 'target_only' => ['OLD_TREE']],
    'root inventory comparison did not expose source-only and target-only trees'
);

$root = dirname(__DIR__);
$firebaseSource = (string)file_get_contents($root . '/api/lib/firebase.php');
$adapterSource = (string)file_get_contents($root . '/api/lib/mysql_firebase.php');
$migrationSource = (string)file_get_contents($root . '/api/tools/migrate_firebase_to_mysql.php');
$queryParitySource = (string)file_get_contents($root . '/api/tools/verify_firebase_mysql_queries.php');
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
    str_contains($adapterSource, 'mysql_fb_get_path_with_etag')
        && str_contains($adapterSource, 'mysql_fb_lock_versions($pdo, [$path])'),
    'ETag reads must register and lock the version path before reading its value'
);
mysql_stage_expect(
    str_contains($adapterSource, 'mysql_fb_query_candidate_entries')
        && str_contains($adapterSource, 'mysql_fb_read_selected_children')
        && str_contains($adapterSource, 'array_chunk(array_keys($selected), 200)'),
    'bounded MySQL queries must select direct children before rebuilding subtrees'
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
    str_contains($migrationSource, "['target_only']")
        && str_contains($migrationSource, 'state=DELETED')
        && str_contains($migrationSource, 'root_inventory='),
    'final delta must delete and verify target-only root trees'
);
mysql_stage_expect(
    str_contains($queryParitySource, 'query_parity_expected_environment')
        && str_contains($queryParitySource, "'production' => 'PRODUCTION'")
        && str_contains($queryParitySource, 'zpay_mysql_assert_environment($expectedEnvironment)')
        && str_contains($queryParitySource, 'mysql_migration_values_match')
        && str_contains($queryParitySource, '!isset($query[\'shallow\'])')
        && !str_contains($queryParitySource, 'json_encode($sourceValue'),
    'query parity verification must enforce the selected environment and avoid printing source values'
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
