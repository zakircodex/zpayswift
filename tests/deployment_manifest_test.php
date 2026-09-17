<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/scripts/deployment_manifest_diff.php';
$temp = sys_get_temp_dir() . '/zpay-deploy-manifest-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);

function deployment_manifest_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function deployment_manifest_run(string $tool, string $previous, string $current, int &$exitCode): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($tool)
        . ' '
        . escapeshellarg($previous)
        . ' '
        . escapeshellarg($current);
    $output = [];
    exec($command . ' 2>&1', $output, $exitCode);
    return $output;
}

$previous = $temp . '/previous.txt';
$current = $temp . '/current.txt';
file_put_contents($previous, "index.html\napi/removed.php\napi/storage/.htaccess\n");
file_put_contents($current, "index.html\napi/storage/.htaccess\n");
$exitCode = 0;
$output = deployment_manifest_run($tool, $previous, $current, $exitCode);
deployment_manifest_expect($exitCode === 0, 'valid manifests must be accepted');
deployment_manifest_expect($output === ['api/removed.php'], 'only removed tracked files may be pruned');

file_put_contents($previous, "index.html\napi/storage/user-upload.jpg\n");
deployment_manifest_run($tool, $previous, $current, $exitCode);
deployment_manifest_expect($exitCode !== 0, 'dynamic storage paths must never enter the deletion list');

file_put_contents($previous, "index.html\n../../private/config.php\n");
deployment_manifest_run($tool, $previous, $current, $exitCode);
deployment_manifest_expect($exitCode !== 0, 'path traversal in a remote manifest must fail closed');

@unlink($previous);
@unlink($current);
@rmdir($temp);

echo "Deployment manifest tests passed.\n";
