<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/*
 * This endpoint and lib/media.php intentionally share the basename media.php.
 * The library's direct-execution guard compares SCRIPT_FILENAME basenames, so
 * expose a temporary distinct entrypoint name while loading the library.
 */
$znewsOriginalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$_SERVER['SCRIPT_FILENAME'] = __FILE__ . '.entrypoint';

try {
    require_once dirname(__DIR__) . '/lib/media_policy.php';
} finally {
    if ($znewsOriginalScriptFilename === null) {
        unset($_SERVER['SCRIPT_FILENAME']);
    } else {
        $_SERVER['SCRIPT_FILENAME'] = $znewsOriginalScriptFilename;
    }
}

api_require_method('GET');

$mediaId = znews_firebase_key($_GET['media_id'] ?? '', 'media_id');
$row = znews_media_public_record_strict($mediaId);
znews_media_stream($row, true);
