<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function zpay_mysql_table_prefix(): string
{
    $prefix = defined('MYSQL_TABLE_PREFIX')
        ? trim((string)constant('MYSQL_TABLE_PREFIX'))
        : 'zps_';

    if ($prefix === '' || preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
        throw new RuntimeException('Invalid MySQL table prefix.');
    }

    return $prefix;
}

function zpay_mysql_table(string $suffix): string
{
    if ($suffix === '' || preg_match('/^[A-Za-z0-9_]+$/', $suffix) !== 1) {
        throw new InvalidArgumentException('Invalid MySQL table name.');
    }

    return '`' . zpay_mysql_table_prefix() . $suffix . '`';
}

function zpay_mysql_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The PDO MySQL extension is unavailable.');
    }

    $dsn = defined('MYSQL_DSN') ? trim((string)constant('MYSQL_DSN')) : '';
    $user = defined('MYSQL_USER') ? (string)constant('MYSQL_USER') : '';
    $password = defined('MYSQL_PASSWORD') ? (string)constant('MYSQL_PASSWORD') : '';
    $timeout = defined('MYSQL_CONNECT_TIMEOUT_SECONDS')
        ? max(1, min(30, (int)constant('MYSQL_CONNECT_TIMEOUT_SECONDS')))
        : 5;

    if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:')) {
        throw new RuntimeException('MySQL connection settings are unavailable.');
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function zpay_mysql_is_retryable(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }

    $sqlState = (string)$error->getCode();
    $driverCode = isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;

    return $sqlState === '40001' || in_array($driverCode, [1205, 1213], true);
}

function zpay_mysql_assert_environment(string $expected): void
{
    $expected = strtoupper(trim($expected));
    if ($expected === '' || preg_match('/^[A-Z0-9_]{1,16}$/D', $expected) !== 1) {
        throw new InvalidArgumentException('Invalid MySQL environment guard.');
    }

    $guard = zpay_mysql_table('environment_guard');
    $statement = zpay_mysql_pdo()->query("SELECT environment FROM {$guard} WHERE id = 1");
    $actual = strtoupper(trim((string)$statement->fetchColumn()));
    if (!hash_equals($expected, $actual)) {
        throw new RuntimeException('MySQL environment guard mismatch.');
    }
}

function zpay_mysql_transaction(callable $operation, int $maxAttempts = 3): mixed
{
    $pdo = zpay_mysql_pdo();
    $maxAttempts = max(1, min(5, $maxAttempts));

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $operation($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($attempt >= $maxAttempts || !zpay_mysql_is_retryable($error)) {
                throw $error;
            }

            usleep(25000 * $attempt);
        }
    }

    throw new RuntimeException('MySQL transaction retry limit reached.');
}
