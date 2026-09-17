<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

$root = dirname(__DIR__);
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files, SORT_STRING);
$excludedPrefixes = ['znews_', 'zsky24_'];
$executed = 0;

foreach ($files as $file) {
    $name = basename($file);
    if ($name === basename(__FILE__)) {
        continue;
    }

    $excluded = false;
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($name, $prefix)) {
            $excluded = true;
            break;
        }
    }
    if ($excluded) {
        continue;
    }

    fwrite(STDOUT, "\n== {$name} ==\n");
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "Core web suite failed at {$name}.\n");
        exit($exitCode);
    }
    $executed++;
}

if ($executed < 40) {
    fwrite(STDERR, "Core web suite discovered too few tests ({$executed}).\n");
    exit(1);
}

fwrite(STDOUT, "\nCore web regression suite passed ({$executed} test files).\n");
