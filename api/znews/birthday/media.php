<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';
require_once dirname(__DIR__) . '/lib/birthday_media.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    exit('Method Not Allowed');
}
$id = trim((string)($_GET['id'] ?? ''));
if (preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $id) !== 1) {
    http_response_code(404);
    exit('Not Found');
}
$media = fb_get(birthday_path('MEDIA', $id));
if (!is_array($media) || strtoupper((string)($media['kind'] ?? '')) !== 'PHOTO') {
    http_response_code(404);
    exit('Not Found');
}
$status = strtoupper((string)($media['status'] ?? ''));
$cache = 'private, no-store';
if ($status === 'DRAFT') {
    $draftId = trim((string)($_GET['draft'] ?? ''));
    $token = trim((string)(api_get_header('X-Draft-Token') ?? ''));
    $draft = birthday_load_draft($draftId, $token);
    if (!hash_equals((string)($draft['photo_media_id'] ?? ''), $id)) {
        http_response_code(404);
        exit('Not Found');
    }
} elseif ($status === 'ACTIVE') {
    $universe = birthday_universe_by_id((string)($media['universe_id'] ?? ''));
    if (!is_array($universe)
        || !hash_equals((string)($universe['photo_media_id'] ?? ''), $id)
        || strtoupper((string)($universe['status'] ?? '')) !== 'ACTIVE'
        || (int)($universe['expires_at'] ?? 0) <= birthday_now()) {
        http_response_code(404);
        exit('Not Found');
    }
    $cache = 'public, max-age=86400, immutable';
} else {
    http_response_code(404);
    exit('Not Found');
}
$path = '';
try {
    $path = birthday_media_resolve((string)$media['storage_key']);
} catch (Throwable $error) {
    $path = '';
}
if ($path !== '' && is_file($path) && is_readable($path)) {
    birthday_stream_file($path, (string)$media['mime'], $cache, false);
}
$content = birthday_media_blob_bytes($media);
if (is_string($content)) {
    birthday_stream_bytes($content, (string)$media['mime'], $cache);
}
http_response_code(404);
exit('Not Found');
