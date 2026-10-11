<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function production_config_option(array $arguments, string $name, string $default): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return trim(substr($argument, strlen($prefix)));
        }
    }

    return $default;
}

function production_config_export(array $values, string $description): string
{
    ksort($values, SORT_STRING);
    $lines = [
        '<?php',
        'declare(strict_types=1);',
        '',
        '/* ' . $description . ' */',
    ];

    foreach ($values as $name => $value) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', (string)$name) !== 1) {
            continue;
        }
        if (!is_scalar($value) && !is_array($value) && $value !== null) {
            continue;
        }
        $lines[] = "define('" . $name . "', " . var_export($value, true) . ');';
    }

    $lines[] = '';
    return implode(PHP_EOL, $lines);
}

function production_config_write_private(string $path, string $contents, bool $replace): void
{
    $directory = dirname($path);
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false || !is_dir($resolvedDirectory)) {
        throw new RuntimeException('Production private configuration directory is unavailable.');
    }

    $normalizedDirectory = strtolower(str_replace('\\', '/', $resolvedDirectory));
    if (
        str_contains($normalizedDirectory, '/public_html')
        || str_contains($normalizedDirectory, '/stage.zpayswift.com')
    ) {
        throw new RuntimeException('Production configuration cannot be written inside a public document root.');
    }
    if (is_link($path)) {
        throw new RuntimeException('Production configuration output cannot be a symbolic link.');
    }
    if (is_file($path) && !$replace) {
        throw new RuntimeException('Production configuration already exists; pass --replace to refresh it.');
    }

    $temporary = $resolvedDirectory . DIRECTORY_SEPARATOR . '.config-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = fopen($temporary, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to create a private production configuration file.');
    }

    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write the complete production configuration.');
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Unable to flush the production configuration.');
        }
    } finally {
        fclose($handle);
    }

    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to install the production configuration atomically.');
    }
    chmod($path, 0600);
}

try {
    $arguments = array_slice($argv, 1);
    $replace = in_array('--replace', $arguments, true);
    $source = production_config_option(
        $arguments,
        'source',
        '/home/zedpayhe/private/zpayswift/config.php'
    );
    $runtimeOutput = production_config_option(
        $arguments,
        'runtime-output',
        '/home/zedpayhe/private/zpayswift/config.mysql-candidate.php'
    );
    $migrationOutput = production_config_option(
        $arguments,
        'migration-output',
        '/home/zedpayhe/private/zpayswift/migration.mysql-production.php'
    );

    if (!is_file($source) || !is_readable($source) || is_link($source)) {
        throw new RuntimeException('Current production configuration is unavailable.');
    }

    $mysqlDsn = trim((string)(getenv('ZPAY_PRODUCTION_MYSQL_DSN') ?: ''));
    $mysqlUser = trim((string)(getenv('ZPAY_PRODUCTION_MYSQL_USER') ?: ''));
    $mysqlPassword = (string)(getenv('ZPAY_PRODUCTION_MYSQL_PASSWORD') ?: '');
    if (!str_starts_with(strtolower($mysqlDsn), 'mysql:') || $mysqlUser === '' || $mysqlPassword === '') {
        throw new RuntimeException('Production MySQL credentials must be supplied through protected environment variables.');
    }

    $before = get_defined_constants(true)['user'] ?? [];
    require $source;
    $after = get_defined_constants(true)['user'] ?? [];
    $current = array_diff_key($after, $before);

    $runtime = array_replace($current, [
        'APP_ENVIRONMENT' => 'production',
        'APP_PUBLIC_ORIGIN' => 'https://zpayswift.com',
        'DATASTORE_DRIVER' => 'mysql',
        'MYSQL_EXPECTED_ENVIRONMENT' => 'PRODUCTION',
        'MYSQL_DSN' => $mysqlDsn,
        'MYSQL_USER' => $mysqlUser,
        'MYSQL_PASSWORD' => $mysqlPassword,
        'MYSQL_TABLE_PREFIX' => 'zps_',
        'MYSQL_CONNECT_TIMEOUT_SECONDS' => 5,
        'STAGE_AUTH_OTP_PREVIEW_ENABLED' => false,
        'STAGE_AUTH_OTP_PREVIEW_PHONES' => [],
        'STAGE_AUTH_OTP_PREVIEW_PURPOSES' => ['USER_LOGIN'],
    ]);

    $migration = [
        'MIGRATION_TARGET' => 'production',
        'MYSQL_MIGRATION_ALLOW_WRITE' => getenv('ZPAY_PRODUCTION_MIGRATION_ALLOW_WRITE') === '1',
        'MYSQL_MIGRATION_ALLOW_PRODUCTION_WRITE' => getenv('ZPAY_PRODUCTION_MIGRATION_ALLOW_PRODUCTION_WRITE') === '1',
        'PRODUCTION_MAINTENANCE_MARKER' => '/home/zedpayhe/public_html/.deploy-in-progress',
        'FIREBASE_DB_URL' => (string)($current['FIREBASE_DB_URL'] ?? ''),
        'FIREBASE_AUTH' => (string)($current['FIREBASE_AUTH'] ?? ''),
        'FIREBASE_CONNECT_TIMEOUT_SECONDS' => 30,
        'FIREBASE_REQUEST_TIMEOUT_SECONDS' => 300,
        'MYSQL_EXPECTED_ENVIRONMENT' => 'PRODUCTION',
        'MYSQL_DSN' => $mysqlDsn,
        'MYSQL_USER' => $mysqlUser,
        'MYSQL_PASSWORD' => $mysqlPassword,
        'MYSQL_TABLE_PREFIX' => 'zps_',
        'MYSQL_CONNECT_TIMEOUT_SECONDS' => 5,
    ];
    if ($migration['FIREBASE_DB_URL'] === '') {
        throw new RuntimeException('Production Firebase source URL is unavailable.');
    }

    production_config_write_private(
        $runtimeOutput,
        production_config_export($runtime, 'Generated MySQL candidate for Z-Pay Swift production.'),
        $replace
    );
    try {
        production_config_write_private(
            $migrationOutput,
            production_config_export($migration, 'Generated Firebase-to-MySQL production migration configuration.'),
            $replace
        );
    } catch (Throwable $error) {
        if (!$replace) {
            @unlink($runtimeOutput);
        }
        throw $error;
    }

    fwrite(STDOUT, "Production MySQL candidate and migration configurations created.\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Production config build failed: ' . (new ReflectionClass($error))->getShortName() . PHP_EOL);
    exit(1);
}
