<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function stage_config_option(array $arguments, string $name, string $default): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return trim(substr($argument, strlen($prefix)));
        }
    }

    return $default;
}

function stage_config_is_sensitive_name(string $name): bool
{
    return preg_match(
        '/(?:KEY|TOKEN|SECRET|PASSWORD|CREDENTIAL|SENDER_ID|CHAT_ID|ADMIN_IDS|_EMAIL|_AUTH)(?:_|$)/',
        $name
    ) === 1;
}

function stage_config_random_secret(): string
{
    return bin2hex(random_bytes(32));
}

function stage_config_export(array $values): string
{
    ksort($values, SORT_STRING);
    $lines = [
        '<?php',
        'declare(strict_types=1);',
        '',
        '/* Generated for the isolated Z-Pay Swift staging environment. */',
    ];

    foreach ($values as $name => $value) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', (string)$name) !== 1) {
            continue;
        }
        $lines[] = "define('" . $name . "', " . var_export($value, true) . ');';
    }

    $lines[] = '';
    return implode(PHP_EOL, $lines);
}

function stage_config_write_private(string $path, string $contents, bool $replace): void
{
    $directory = dirname($path);
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false || !is_dir($resolvedDirectory)) {
        throw new RuntimeException('Stage private configuration directory is unavailable.');
    }

    $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);
    if (
        str_contains($normalizedDirectory, '/public_html')
        || str_contains($normalizedDirectory, '/stage.zpayswift.com')
    ) {
        throw new RuntimeException('Stage configuration cannot be written inside a public document root.');
    }
    if (is_file($path) && !$replace) {
        throw new RuntimeException('Stage configuration already exists; pass --replace to rotate it.');
    }

    $temporary = $resolvedDirectory . DIRECTORY_SEPARATOR . '.config-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = fopen($temporary, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to create a private stage configuration file.');
    }

    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write the complete stage configuration.');
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Unable to flush the stage configuration.');
        }
    } finally {
        fclose($handle);
    }

    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to install the stage configuration atomically.');
    }
    chmod($path, 0600);
}

try {
    $arguments = array_slice($argv, 1);
    $replace = in_array('--replace', $arguments, true);
    $source = stage_config_option($arguments, 'source', '/home/zedpayhe/private/zpayswift/config.php');
    $output = stage_config_option($arguments, 'output', '/home/zedpayhe/private/zpayswift-stage/config.php');
    $migrationOutput = stage_config_option(
        $arguments,
        'migration-output',
        '/home/zedpayhe/private/zpayswift-stage/migration.php'
    );

    if (!is_file($source) || !is_readable($source)) {
        throw new RuntimeException('Production configuration is unavailable for safe staging derivation.');
    }

    $mysqlDsn = trim((string)(getenv('ZPAY_STAGE_MYSQL_DSN') ?: ''));
    $mysqlUser = trim((string)(getenv('ZPAY_STAGE_MYSQL_USER') ?: ''));
    $mysqlPassword = (string)(getenv('ZPAY_STAGE_MYSQL_PASSWORD') ?: '');
    if (!str_starts_with(strtolower($mysqlDsn), 'mysql:') || $mysqlUser === '' || $mysqlPassword === '') {
        throw new RuntimeException('Stage MySQL credentials must be supplied through protected environment variables.');
    }

    $before = get_defined_constants(true)['user'] ?? [];
    require $source;
    $after = get_defined_constants(true)['user'] ?? [];
    $production = array_diff_key($after, $before);

    $stage = [];
    foreach ($production as $name => $value) {
        if (stage_config_is_sensitive_name((string)$name)) {
            $stage[$name] = is_bool($value) ? $value : '';
            continue;
        }
        if (str_ends_with((string)$name, '_PATH') || str_ends_with((string)$name, '_DIR')) {
            $stage[$name] = '';
            continue;
        }
        if (is_scalar($value) || is_array($value) || $value === null) {
            $stage[$name] = $value;
        }
    }

    $stage = array_replace($stage, [
        'APP_ENVIRONMENT' => 'stage',
        'APP_PUBLIC_ORIGIN' => 'https://stage.zpayswift.com',
        'APP_PRIVATE_SMS_BRIDGE_PATH' => '/home/zedpayhe/private/zpayswift-stage/disabled-auth-sms-bridge.php',
        'STAGE_AUTH_OTP_PREVIEW_ENABLED' => false,
        'STAGE_AUTH_OTP_PREVIEW_PHONES' => [],
        'STAGE_AUTH_OTP_PREVIEW_PURPOSES' => ['USER_LOGIN'],
        'APP_KEY' => stage_config_random_secret(),
        'WORKER_KEY' => stage_config_random_secret(),
        'ADMIN_KEY' => stage_config_random_secret(),
        'SECURITY_HASH_SECRET' => stage_config_random_secret(),
        'ZNEWS_HANDOFF_ENCRYPTION_KEY' => stage_config_random_secret(),
        'ZNEWS_AD_DELIVERY_SIGNING_KEY' => stage_config_random_secret(),
        'DATASTORE_DRIVER' => 'mysql',
        'MYSQL_EXPECTED_ENVIRONMENT' => 'STAGE',
        'MYSQL_DSN' => $mysqlDsn,
        'MYSQL_USER' => $mysqlUser,
        'MYSQL_PASSWORD' => $mysqlPassword,
        'MYSQL_TABLE_PREFIX' => 'zps_',
        'MYSQL_CONNECT_TIMEOUT_SECONDS' => 5,
        'FIREBASE_DB_URL' => 'https://invalid.local',
        'FIREBASE_AUTH' => '',
        'FIREBASE_DB_SECRET' => '',
        'BIRTHDAY_UNIVERSE_STORAGE_DIR' => '',
        'SECURITY_EXTERNAL_IP_LOOKUP_ENABLED' => false,
        'SECURITY_CLOUDFLARE_ORIGIN_LOCKED' => false,
        'ADSTERRA_ZSKY24_WEB_ADS_ENABLED' => false,
    ]);

    $migration = [
        'MIGRATION_TARGET' => 'stage',
        'MYSQL_MIGRATION_ALLOW_WRITE' => getenv('ZPAY_STAGE_MIGRATION_ALLOW_WRITE') === '1',
        'FIREBASE_DB_URL' => (string)($production['FIREBASE_DB_URL'] ?? ''),
        'FIREBASE_AUTH' => (string)($production['FIREBASE_AUTH'] ?? ''),
        'FIREBASE_CONNECT_TIMEOUT_SECONDS' => 30,
        'FIREBASE_REQUEST_TIMEOUT_SECONDS' => 300,
        'MYSQL_EXPECTED_ENVIRONMENT' => 'STAGE',
        'MYSQL_DSN' => $mysqlDsn,
        'MYSQL_USER' => $mysqlUser,
        'MYSQL_PASSWORD' => $mysqlPassword,
        'MYSQL_TABLE_PREFIX' => 'zps_',
        'MYSQL_CONNECT_TIMEOUT_SECONDS' => 5,
    ];
    if ($migration['FIREBASE_DB_URL'] === '') {
        throw new RuntimeException('Production Firebase source URL is unavailable.');
    }

    stage_config_write_private($output, stage_config_export($stage), $replace);
    try {
        stage_config_write_private($migrationOutput, stage_config_export($migration), $replace);
    } catch (Throwable $error) {
        if (!$replace) {
            @unlink($output);
        }
        throw $error;
    }

    fwrite(STDOUT, "Stage runtime and migration configurations created.\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Stage config build failed: ' . (new ReflectionClass($error))->getShortName() . PHP_EOL);
    exit(1);
}
