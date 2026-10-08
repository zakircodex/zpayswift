<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/mysql_firebase.php';

function mysql_migration_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('mysql_migration_canonicalize', $value);
    }

    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) {
        $value[$key] = mysql_migration_canonicalize($child);
    }

    return $value;
}

function mysql_migration_json(mixed $value): string
{
    return mysql_fb_json_encode(mysql_migration_canonicalize($value));
}

function mysql_migration_hash(mixed $value, bool $binary = true): string
{
    return hash('sha256', mysql_migration_json($value), $binary);
}

function mysql_migration_run_start(string $mode): array
{
    $mode = strtoupper(trim($mode));
    if (!in_array($mode, ['IMPORT', 'RECONCILE', 'FINAL_DELTA'], true)) {
        throw new InvalidArgumentException('Invalid migration mode.');
    }

    $token = bin2hex(random_bytes(16));
    $runs = zpay_mysql_table('migration_runs');
    $statement = zpay_mysql_pdo()->prepare(
        "INSERT INTO {$runs}
            (run_token, mode, state, started_at, processed_count, mismatch_count)
         VALUES (:token, :mode, 'RUNNING', UTC_TIMESTAMP(6), 0, 0)"
    );
    $statement->execute([':token' => $token, ':mode' => $mode]);

    return ['id' => (int)zpay_mysql_pdo()->lastInsertId(), 'token' => $token];
}

function mysql_migration_run_progress(int $runId, int $processed, int $mismatches, string $path): void
{
    $runs = zpay_mysql_table('migration_runs');
    $statement = zpay_mysql_pdo()->prepare(
        "UPDATE {$runs}
         SET processed_count = :processed,
             mismatch_count = :mismatches,
             last_path = :path
         WHERE id = :id AND state = 'RUNNING'"
    );
    $statement->execute([
        ':processed' => $processed,
        ':mismatches' => $mismatches,
        ':path' => mysql_fb_normalize_path($path),
        ':id' => $runId,
    ]);
}

function mysql_migration_run_finish(int $runId, bool $ok, ?string $errorCode = null): void
{
    $runs = zpay_mysql_table('migration_runs');
    $statement = zpay_mysql_pdo()->prepare(
        "UPDATE {$runs}
         SET state = :state,
             completed_at = UTC_TIMESTAMP(6),
             error_code = :error_code
         WHERE id = :id"
    );
    $statement->execute([
        ':state' => $ok ? 'COMPLETED' : 'FAILED',
        ':error_code' => $errorCode,
        ':id' => $runId,
    ]);
}

function mysql_migration_checkpoint(
    string $path,
    mixed $sourceValue,
    string $state,
    ?string $errorCode = null
): void {
    $state = strtoupper(trim($state));
    if (!in_array($state, ['MIGRATED', 'VERIFIED', 'MISMATCH', 'FAILED'], true)) {
        throw new InvalidArgumentException('Invalid migration checkpoint state.');
    }

    $json = mysql_migration_json($sourceValue);
    $checkpoints = zpay_mysql_table('migration_checkpoints');
    $statement = zpay_mysql_pdo()->prepare(
        "INSERT INTO {$checkpoints}
            (source_path, source_hash, source_bytes, migrated_at, verified_at, state, error_code)
         VALUES
            (:path, :hash, :bytes, UTC_TIMESTAMP(6), :verified_at, :state, :error_code)
         ON DUPLICATE KEY UPDATE
            source_hash = VALUES(source_hash),
            source_bytes = VALUES(source_bytes),
            migrated_at = VALUES(migrated_at),
            verified_at = VALUES(verified_at),
            state = VALUES(state),
            error_code = VALUES(error_code)"
    );
    $statement->bindValue(':path', mysql_fb_normalize_path($path), PDO::PARAM_STR);
    $statement->bindValue(':hash', hash('sha256', $json, true), PDO::PARAM_LOB);
    $statement->bindValue(':bytes', strlen($json), PDO::PARAM_INT);
    $verifiedAt = $state === 'VERIFIED'
        ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')
        : null;
    $statement->bindValue(':verified_at', $verifiedAt);
    $statement->bindValue(':state', $state);
    $statement->bindValue(':error_code', $errorCode);
    $statement->execute();
}

function mysql_migration_checkpoint_state(string $path): ?string
{
    $checkpoints = zpay_mysql_table('migration_checkpoints');
    $statement = zpay_mysql_pdo()->prepare(
        "SELECT state FROM {$checkpoints} WHERE source_path = :path"
    );
    $statement->execute([':path' => mysql_fb_normalize_path($path)]);
    $state = $statement->fetchColumn();

    return is_string($state) && $state !== '' ? $state : null;
}

function mysql_migration_values_match(mixed $source, mixed $target): bool
{
    return hash_equals(mysql_migration_hash($source), mysql_migration_hash($target));
}
