<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/download.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read download.php.\n");
    exit(1);
}

if (!str_contains($source, "'/downloads/Z-Pay-Swift-1.18.apk'")) {
    fwrite(STDERR, "The public downloader does not target the immutable Android 1.18 artifact.\n");
    exit(1);
}

if (!str_contains($source, "\$downloadName = 'zpay-swift.apk';")) {
    fwrite(STDERR, "The public APK download filename changed unexpectedly.\n");
    exit(1);
}

fwrite(STDOUT, "Android 1.18 download release contract passed.\n");
