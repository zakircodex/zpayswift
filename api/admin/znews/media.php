<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 2) . '/znews/lib/media.php';

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
