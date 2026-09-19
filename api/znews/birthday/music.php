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
$music = fb_get(birthday_path('MUSIC', $id));
if (!is_array($music) || !znews_bool($music['active'] ?? false, false)) {
    http_response_code(404);
    exit('Not Found');
}
try {
    birthday_stream_file(
        birthday_media_resolve((string)$music['storage_key']),
        (string)$music['mime'],
        'public, max-age=86400',
        true
    );
} catch (Throwable $error) {
    http_response_code(404);
    exit('Not Found');
}
