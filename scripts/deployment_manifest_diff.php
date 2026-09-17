<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php deployment_manifest_diff.php PREVIOUS CURRENT\n");
    exit(2);
}

function deployment_manifest_path_allowed(string $path): bool
{
    if (
        $path === ''
        || str_starts_with($path, '/')
        || str_contains($path, '..')
        || str_contains($path, '\\')
        || preg_match('/^[A-Za-z0-9._@+\/-]+$/D', $path) !== 1
    ) {
        return false;
    }

    $allowed = preg_match(
        '#^(?:\.htaccess$|\.well-known/|index\.html$|download\.php$|track\.html$|privacy\.html$|terms\.html$|apply-subadmin\.html$|deploy_version\.txt$|assets/|docs/|images/|logo/|znews/|api/)#D',
        $path
    ) === 1;
    if (!$allowed) {
        return false;
    }

    $protected = preg_match(
        '#^(?:api/config\.php$|api/storage/(?!\.htaccess$)|api/storage_private/|private/|logs/|downloads/|cgi-bin/)#D',
        $path
    ) === 1;

    return !$protected && !in_array(basename($path), ['.env', 'error_log'], true);
}

function deployment_manifest_read(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Deployment manifest could not be read.');
    }

    $files = [];
    foreach ($lines as $line) {
        $entry = trim((string)$line);
        if (!deployment_manifest_path_allowed($entry)) {
            throw new RuntimeException('Deployment manifest contains an unsafe path.');
        }
        $files[$entry] = true;
    }

    ksort($files, SORT_STRING);
    return $files;
}

try {
    $previous = deployment_manifest_read($argv[1]);
    $current = deployment_manifest_read($argv[2]);
    if (!$current) {
        throw new RuntimeException('Current deployment manifest is empty.');
    }

    foreach (array_keys(array_diff_key($previous, $current)) as $stalePath) {
        fwrite(STDOUT, $stalePath . PHP_EOL);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Deployment manifest validation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
