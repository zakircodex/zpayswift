<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function migration_usage(): void
{
    fwrite(STDOUT, <<<TEXT
Usage:
  php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php [--dry-run]
  php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php --execute [--mode=import|reconcile|final-delta] [--path=NODE]
  php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php --verify-only [--path=NODE]

Writes are refused unless the private config sets MIGRATION_TARGET to "stage"
and MYSQL_MIGRATION_ALLOW_WRITE to true. Repeat --path to process selected trees.
TEXT);
}

function migration_parse_arguments(array $arguments): array
{
    $options = [
        'config' => trim((string)(getenv('ZPAY_MIGRATION_CONFIG_PATH') ?: '')),
        'execute' => false,
        'verify_only' => false,
        'dry_run' => false,
        'mode' => 'IMPORT',
        'paths' => [],
        'max_retries' => 3,
    ];

    foreach ($arguments as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            migration_usage();
            exit(0);
        }
        if ($argument === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if ($argument === '--verify-only') {
            $options['verify_only'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if (str_starts_with($argument, '--config=')) {
            $options['config'] = trim(substr($argument, 9));
            continue;
        }
        if (str_starts_with($argument, '--path=')) {
            $path = trim(substr($argument, 7));
            if ($path === '') {
                throw new InvalidArgumentException('Migration path cannot be empty. Use --path=/ for the root.');
            }
            $options['paths'][] = $path === '/' ? '' : $path;
            continue;
        }
        if (str_starts_with($argument, '--mode=')) {
            $mode = strtoupper(str_replace('-', '_', trim(substr($argument, 7))));
            if (!in_array($mode, ['IMPORT', 'RECONCILE', 'FINAL_DELTA'], true)) {
                throw new InvalidArgumentException('Migration mode must be import, reconcile or final-delta.');
            }
            $options['mode'] = $mode;
            continue;
        }
        if (preg_match('/^--max-retries=([1-5])$/D', $argument, $match) === 1) {
            $options['max_retries'] = (int)$match[1];
            continue;
        }

        throw new InvalidArgumentException('Unknown migration argument: ' . $argument);
    }

    $selectedModes = (int)$options['execute'] + (int)$options['verify_only'] + (int)$options['dry_run'];
    if ($selectedModes > 1) {
        throw new InvalidArgumentException('--execute, --verify-only and --dry-run are mutually exclusive.');
    }
    if ($selectedModes === 0) {
        $options['dry_run'] = true;
    }

    return $options;
}

function migration_require_constant(string $name): mixed
{
    if (!defined($name)) {
        throw new RuntimeException('Migration configuration is incomplete: ' . $name);
    }

    return constant($name);
}

function migration_source_read(string $path, bool $withEtag = true): array
{
    $headers = $withEtag ? ['X-Firebase-ETag: true'] : [];
    $response = fb_firebase_request('GET', $path, null, [], $headers, $withEtag);
    if (empty($response['ok'])) {
        throw new RuntimeException('Firebase source read failed with HTTP ' . (int)($response['status'] ?? 0));
    }

    $value = $response['json'] ?? null;
    $etag = trim((string)($response['headers']['etag'] ?? ''));
    if ($etag === '') {
        $etag = 'sha256:' . bin2hex(mysql_migration_hash($value));
    }

    return ['value' => $value, 'etag' => $etag];
}

function migration_source_paths(array $requestedPaths, ?string &$rootEtag = null): array
{
    $rootEtag = null;
    if ($requestedPaths !== []) {
        $paths = array_map('mysql_fb_normalize_path', $requestedPaths);
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $paths;
    }

    $response = fb_firebase_request(
        'GET',
        '',
        null,
        ['shallow' => 'true']
    );
    if (empty($response['ok'])) {
        throw new RuntimeException('Firebase source inventory failed with HTTP ' . (int)($response['status'] ?? 0));
    }

    $inventory = $response['json'] ?? null;
    // Firebase rejects requests that combine shallow=true with ETag headers.
    // A canonical inventory signature still detects added or removed root trees;
    // every selected tree is independently read twice and content-verified below.
    $rootEtag = 'sha256:' . bin2hex(mysql_migration_hash($inventory));
    if ($inventory === null) {
        return [];
    }
    if (!is_array($inventory)) {
        return [''];
    }

    $paths = array_map('strval', array_keys($inventory));
    sort($paths, SORT_STRING);
    return $paths;
}

function migration_path_label(string $path): string
{
    return $path === '' ? '(root)' : $path;
}

function migration_error_code(Throwable $error): string
{
    $name = strtoupper((new ReflectionClass($error))->getShortName());
    $name = preg_replace('/[^A-Z0-9_]+/', '_', $name) ?: 'ERROR';
    return substr($name, 0, 64);
}

function migration_process_path(string $path, bool $execute, int $maxRetries): array
{
    $lastSource = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $before = migration_source_read($path);
        $lastSource = $before['value'];

        if ($execute) {
            $write = mysql_fb_put_path($path, $lastSource);
            if (empty($write['ok'])) {
                throw new RuntimeException('MySQL target write failed.');
            }
            mysql_migration_checkpoint($path, $lastSource, 'MIGRATED');
        }

        $target = mysql_fb_get_path($path);
        $after = migration_source_read($path);
        $sourceStable = hash_equals((string)$before['etag'], (string)$after['etag'])
            && mysql_migration_values_match($before['value'], $after['value']);

        if (!$sourceStable) {
            $lastSource = $after['value'];
            continue;
        }

        if (mysql_migration_values_match($after['value'], $target)) {
            mysql_migration_checkpoint($path, $after['value'], 'VERIFIED');
            return ['ok' => true, 'state' => 'VERIFIED', 'attempts' => $attempt];
        }

        $lastSource = $after['value'];
    }

    mysql_migration_checkpoint($path, $lastSource, 'MISMATCH', 'SOURCE_OR_TARGET_CHANGED');
    return ['ok' => false, 'state' => 'MISMATCH', 'attempts' => $maxRetries];
}

try {
    $options = migration_parse_arguments(array_slice($argv, 1));
    $configPath = (string)$options['config'];
    if ($configPath === '' || !is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('A readable private migration config is required.');
    }

    require_once $configPath;
    migration_require_constant('FIREBASE_DB_URL');
    migration_require_constant('FIREBASE_AUTH');

    $apiRoot = dirname(__DIR__);
    require_once $apiRoot . '/lib/firebase.php';
    require_once $apiRoot . '/lib/mysql_migration.php';

    $rootEtagBefore = null;
    $paths = migration_source_paths((array)$options['paths'], $rootEtagBefore);
    if (!empty($options['dry_run'])) {
        fwrite(STDOUT, 'mode=DRY_RUN' . PHP_EOL);
        fwrite(STDOUT, 'source_trees=' . count($paths) . PHP_EOL);
        foreach ($paths as $path) {
            fwrite(STDOUT, 'path=' . migration_path_label($path) . PHP_EOL);
        }
        exit(0);
    }

    if (strtolower((string)migration_require_constant('MIGRATION_TARGET')) !== 'stage') {
        throw new RuntimeException('Migration writes are restricted to the stage target.');
    }
    if (migration_require_constant('MYSQL_MIGRATION_ALLOW_WRITE') !== true) {
        throw new RuntimeException('Stage migration writes are not enabled by private configuration.');
    }
    migration_require_constant('MYSQL_DSN');
    migration_require_constant('MYSQL_USER');
    migration_require_constant('MYSQL_PASSWORD');
    zpay_mysql_assert_environment('STAGE');

    $run = mysql_migration_run_start((string)$options['mode']);
    $processed = 0;
    $mismatches = 0;
    $failed = 0;
    $runCompleted = false;

    try {
        foreach ($paths as $path) {
            try {
                $result = migration_process_path(
                    $path,
                    !empty($options['execute']),
                    (int)$options['max_retries']
                );
                $processed++;
                if (empty($result['ok'])) {
                    $mismatches++;
                }
                fwrite(
                    STDOUT,
                    'path=' . migration_path_label($path)
                    . ' state=' . (string)$result['state']
                    . ' attempts=' . (int)$result['attempts']
                    . PHP_EOL
                );
            } catch (Throwable $error) {
                $processed++;
                $failed++;
                $code = migration_error_code($error);
                try {
                    mysql_migration_checkpoint($path, null, 'FAILED', $code);
                } catch (Throwable) {
                    // The run summary below remains the authoritative failure signal.
                }
                fwrite(STDERR, 'path=' . migration_path_label($path) . ' state=FAILED code=' . $code . PHP_EOL);
            }

            mysql_migration_run_progress((int)$run['id'], $processed, $mismatches, $path);
        }

        if ((array)$options['paths'] === []) {
            $rootEtagAfter = null;
            $finalPaths = migration_source_paths([], $rootEtagAfter);
            $inventoryChanged = $finalPaths !== $paths
                || ($rootEtagBefore !== '' && $rootEtagAfter !== '' && $rootEtagBefore !== $rootEtagAfter);
            if ($inventoryChanged) {
                $mismatches++;
                fwrite(STDERR, "source_inventory=CHANGED rerun_required=true\n");
                mysql_migration_run_progress((int)$run['id'], $processed, $mismatches, '');
            }
        }

        $runCompleted = $mismatches === 0 && $failed === 0;
        mysql_migration_run_finish((int)$run['id'], $runCompleted, $runCompleted ? null : 'VERIFICATION_FAILED');
    } catch (Throwable $error) {
        mysql_migration_run_finish((int)$run['id'], false, migration_error_code($error));
        throw $error;
    }

    fwrite(STDOUT, 'processed=' . $processed . PHP_EOL);
    fwrite(STDOUT, 'mismatches=' . $mismatches . PHP_EOL);
    fwrite(STDOUT, 'failed=' . $failed . PHP_EOL);
    exit($runCompleted ? 0 : 1);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    migration_usage();
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration stopped: ' . migration_error_code($error) . PHP_EOL);
    exit(1);
}
