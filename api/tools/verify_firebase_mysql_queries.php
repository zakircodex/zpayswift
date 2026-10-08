<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function query_parity_config_path(array $arguments): string
{
    foreach ($arguments as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            fwrite(STDOUT, "Usage: php api/tools/verify_firebase_mysql_queries.php --config=/absolute/migration.php\n");
            exit(0);
        }
        if (str_starts_with($argument, '--config=')) {
            return trim(substr($argument, 9));
        }
        throw new InvalidArgumentException('Unknown query parity argument.');
    }

    return trim((string)(getenv('ZPAY_MIGRATION_CONFIG_PATH') ?: ''));
}

function query_parity_top_level_keys(mixed $value): array
{
    return is_array($value) ? array_map('strval', array_keys($value)) : [];
}

function query_parity_cases(): array
{
    return [
        'users_shallow' => ['USERS', ['shallow' => 'true']],
        'topup_pending_shallow' => ['TOPUP_REQUESTS/PENDING', ['shallow' => 'true']],
        'mfs_pending_shallow' => ['MFS_REQUESTS/PENDING', ['shallow' => 'true']],
        'system_logs_key_window' => ['SYSTEM_LOGS', [
            'orderBy' => json_encode('$key', JSON_UNESCAPED_SLASHES),
            'limitToLast' => 25,
        ]],
        'user_api_key_window' => ['USER_API_REQUESTS', [
            'orderBy' => json_encode('$key', JSON_UNESCAPED_SLASHES),
            'limitToLast' => 25,
        ]],
        'znews_media_key_window' => ['ZNEWS_MEDIA', [
            'orderBy' => json_encode('$key', JSON_UNESCAPED_SLASHES),
            'limitToFirst' => 25,
        ]],
        'znews_feed_created_window' => ['ZNEWS_PUBLIC_FEED', [
            'orderBy' => json_encode('created_at', JSON_UNESCAPED_SLASHES),
            'limitToLast' => 25,
        ]],
    ];
}

try {
    $configPath = query_parity_config_path(array_slice($argv, 1));
    if ($configPath === '' || !is_file($configPath) || !is_readable($configPath)) {
        throw new RuntimeException('A readable private migration config is required.');
    }

    require_once $configPath;
    if (!defined('MIGRATION_TARGET') || strtolower((string)MIGRATION_TARGET) !== 'stage') {
        throw new RuntimeException('Query parity checks are restricted to the stage target.');
    }

    $apiRoot = dirname(__DIR__);
    require_once $apiRoot . '/lib/firebase.php';
    require_once $apiRoot . '/lib/mysql_migration.php';
    zpay_mysql_assert_environment('STAGE');

    $failed = 0;
    foreach (query_parity_cases() as $label => [$path, $query]) {
        $source = fb_firebase_request('GET', $path, null, $query);
        $target = mysql_fb_request('GET', $path, null, $query);
        $sourceValue = $source['json'] ?? null;
        $targetValue = $target['json'] ?? null;
        $valuesMatch = !empty($source['ok'])
            && !empty($target['ok'])
            && mysql_migration_values_match($sourceValue, $targetValue);
        $orderMatches = query_parity_top_level_keys($sourceValue) === query_parity_top_level_keys($targetValue);
        $matched = $valuesMatch && $orderMatches;
        if (!$matched) {
            $failed++;
        }

        fwrite(
            $matched ? STDOUT : STDERR,
            'case=' . $label
            . ' state=' . ($matched ? 'MATCH' : 'MISMATCH')
            . ' source_status=' . (int)($source['status'] ?? 0)
            . ' target_status=' . (int)($target['status'] ?? 0)
            . PHP_EOL
        );
    }

    fwrite(STDOUT, 'query_cases=' . count(query_parity_cases()) . PHP_EOL);
    fwrite(STDOUT, 'mismatches=' . $failed . PHP_EOL);
    exit($failed === 0 ? 0 : 1);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(2);
} catch (Throwable $error) {
    $code = strtoupper((new ReflectionClass($error))->getShortName());
    fwrite(STDERR, 'Query parity verification stopped: ' . $code . PHP_EOL);
    exit(1);
}
