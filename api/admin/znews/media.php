<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/znews/bootstrap.php';

/*
 * This endpoint and znews/lib/media.php share the basename media.php.
 * Use a temporary distinct entrypoint name while loading the guarded library.
 */
$znewsOriginalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$_SERVER['SCRIPT_FILENAME'] = __FILE__ . '.entrypoint';

try {
    require_once dirname(__DIR__, 2) . '/znews/lib/media.php';
} finally {
    if ($znewsOriginalScriptFilename === null) {
        unset($_SERVER['SCRIPT_FILENAME']);
    } else {
        $_SERVER['SCRIPT_FILENAME'] = $znewsOriginalScriptFilename;
    }
}

api_require_method('GET');
auth_require_admin_session(true);

$mediaId = znews_firebase_key($_GET['media_id'] ?? '', 'media_id');
$row = fb_get(znews_media_path($mediaId));

if (!is_array($row)
    || (int)($row['deleted_at'] ?? 0) > 0
    || strtoupper(trim((string)($row['status'] ?? ''))) === 'DELETED') {
    api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
}

znews_media_stream($row, false);
