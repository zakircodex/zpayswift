<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function activation_option(array $arguments, string $name, string $default): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return trim(substr($argument, strlen($prefix)));
        }
    }

    return $default;
}

function activation_private_directory(string $path): string
{
    $directory = realpath(dirname($path));
    if ($directory === false || !is_dir($directory)) {
        throw new RuntimeException('Private configuration directory is unavailable.');
    }

    $normalized = strtolower(str_replace('\\', '/', $directory));
    if (str_contains($normalized, '/public_html') || str_contains($normalized, '/stage.zpayswift.com')) {
        throw new RuntimeException('Configuration path cannot be inside a public document root.');
    }

    return $directory;
}

function activation_write_atomic(string $path, string $contents): void
{
    $directory = activation_private_directory($path);
    if (is_link($path)) {
        throw new RuntimeException('Configuration path cannot be a symbolic link.');
    }

    $temporary = $directory . DIRECTORY_SEPARATOR . '.activate-' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = fopen($temporary, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to create atomic configuration candidate.');
    }

    try {
        if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new RuntimeException('Unable to write atomic configuration candidate.');
        }
    } finally {
        fclose($handle);
    }

    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to activate configuration atomically.');
    }
    chmod($path, 0600);
}

function activation_verify(string $verifier, string $config): bool
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($verifier)
        . ' ' . escapeshellarg($config);
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    return $exitCode === 0;
}

try {
    $arguments = array_slice($argv, 1);
    if (!in_array('--confirm-production-cutover', $arguments, true)) {
        throw new RuntimeException('Activation requires --confirm-production-cutover.');
    }

    $current = activation_option($arguments, 'current', '/home/zedpayhe/private/zpayswift/config.php');
    $candidate = activation_option(
        $arguments,
        'candidate',
        '/home/zedpayhe/private/zpayswift/config.mysql-candidate.php'
    );
    $marker = activation_option(
        $arguments,
        'maintenance-marker',
        '/home/zedpayhe/public_html/.deploy-in-progress'
    );
    $backupDirectory = activation_option(
        $arguments,
        'backup-directory',
        '/home/zedpayhe/private/zpayswift/cutover-backups'
    );
    $verifier = activation_option(
        $arguments,
        'verifier',
        dirname(__DIR__, 2) . '/scripts/verify_mysql_production_runtime.php'
    );

    $expectedCurrent = '/home/zedpayhe/private/zpayswift/config.php';
    $expectedCandidate = '/home/zedpayhe/private/zpayswift/config.mysql-candidate.php';
    $expectedMarker = '/home/zedpayhe/public_html/.deploy-in-progress';
    $expectedVerifier = realpath(dirname(__DIR__, 2) . '/scripts/verify_mysql_production_runtime.php');
    if (
        !hash_equals($expectedCurrent, str_replace('\\', '/', $current))
        || !hash_equals($expectedCandidate, str_replace('\\', '/', $candidate))
        || !hash_equals($expectedMarker, str_replace('\\', '/', $marker))
        || $expectedVerifier === false
        || realpath($verifier) !== $expectedVerifier
    ) {
        throw new RuntimeException('Production activation path guard mismatch.');
    }

    if (!is_file($marker) || is_link($marker) || basename($marker) !== '.deploy-in-progress') {
        throw new RuntimeException('Production maintenance marker is unavailable.');
    }
    if (
        !is_file($current) || !is_readable($current) || is_link($current)
        || !is_file($candidate) || !is_readable($candidate) || is_link($candidate)
    ) {
        throw new RuntimeException('Current or candidate production configuration is unavailable.');
    }
    if (!is_file($verifier) || !is_readable($verifier) || basename($verifier) !== 'verify_mysql_production_runtime.php') {
        throw new RuntimeException('Production runtime verifier is unavailable.');
    }

    $currentDirectory = activation_private_directory($current);
    $candidateDirectory = activation_private_directory($candidate);
    if (!hash_equals($currentDirectory, $candidateDirectory)) {
        throw new RuntimeException('Current and candidate configurations must share one private directory.');
    }
    if (!activation_verify($verifier, $candidate)) {
        throw new RuntimeException('Production MySQL candidate verification failed.');
    }

    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Unable to create production cutover backup directory.');
    }
    $resolvedBackupDirectory = realpath($backupDirectory);
    if ($resolvedBackupDirectory === false || dirname($resolvedBackupDirectory) !== $currentDirectory) {
        throw new RuntimeException('Production cutover backup directory must be inside the private config directory.');
    }

    $currentContents = file_get_contents($current);
    $candidateContents = file_get_contents($candidate);
    if ($currentContents === false || $candidateContents === false || $candidateContents === '') {
        throw new RuntimeException('Unable to read production configuration contents.');
    }

    $backup = $resolvedBackupDirectory . DIRECTORY_SEPARATOR
        . 'config.firebase.' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(4)) . '.php';
    activation_write_atomic($backup, $currentContents);
    if (!hash_equals(hash('sha256', $currentContents), hash_file('sha256', $backup) ?: '')) {
        throw new RuntimeException('Production Firebase configuration backup verification failed.');
    }

    activation_write_atomic($current, $candidateContents);
    if (!activation_verify($verifier, $current)) {
        activation_write_atomic($current, $currentContents);
        throw new RuntimeException('Production MySQL activation failed and the Firebase configuration was restored.');
    }

    fwrite(STDOUT, 'Production MySQL runtime activated. Backup: ' . $backup . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Production activation stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
