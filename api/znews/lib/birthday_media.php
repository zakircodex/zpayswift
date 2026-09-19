<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/media.php';

function birthday_media_max_bytes(): int
{
    $configured = defined('BIRTHDAY_UNIVERSE_PHOTO_MAX_BYTES')
        ? (int)constant('BIRTHDAY_UNIVERSE_PHOTO_MAX_BYTES')
        : 5 * 1024 * 1024;
    return max(1024 * 1024, min(5 * 1024 * 1024, $configured));
}

function birthday_storage_root(): string
{
    if (defined('BIRTHDAY_UNIVERSE_STORAGE_DIR') && trim((string)constant('BIRTHDAY_UNIVERSE_STORAGE_DIR')) !== '') {
        return rtrim(trim((string)constant('BIRTHDAY_UNIVERSE_STORAGE_DIR')), DIRECTORY_SEPARATOR);
    }
    $privateConfig = function_exists('app_private_config_path') ? app_private_config_path() : '';
    $privateRoot = $privateConfig !== '' ? dirname($privateConfig) : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private';
    return $privateRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'znews' . DIRECTORY_SEPARATOR . 'birthday';
}

function birthday_media_directory(string $kind, ?int $now = null): string
{
    $kind = strtolower(trim($kind));
    if (!in_array($kind, ['photos', 'music'], true)) {
        throw new RuntimeException('Invalid Birthday Universe storage type.');
    }
    $now = $now ?? birthday_now();
    $relative = $kind . '/' . date('Y/m', $now);
    $path = birthday_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Birthday Universe storage is unavailable.');
    }
    return $path;
}

function birthday_media_key(string $kind, string $id, string $extension, ?int $now = null): string
{
    $kind = strtolower(trim($kind));
    $id = strtolower(znews_firebase_key($id, $kind . '_id'));
    $extension = strtolower(trim($extension));
    $allowed = $kind === 'photos' ? ['jpg', 'png', 'webp'] : ['mp3', 'm4a', 'ogg'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Unsupported Birthday Universe media extension.');
    }
    $now = $now ?? birthday_now();
    return $kind . '/' . date('Y/m', $now) . '/birthday_' . $id . '.' . $extension;
}

function birthday_media_resolve(string $key): string
{
    $key = trim(str_replace('\\', '/', $key), '/');
    if (preg_match('#^(?:photos|music)/[0-9]{4}/(?:0[1-9]|1[0-2])/birthday_[a-z0-9_-]+\.(?:jpg|png|webp|mp3|m4a|ogg)$#D', $key) !== 1) {
        throw new RuntimeException('Invalid Birthday Universe media path.');
    }
    $root = rtrim(birthday_storage_root(), DIRECTORY_SEPARATOR);
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    $rootReal = realpath($root);
    $directoryReal = realpath(dirname($path));
    if ($rootReal !== false && $directoryReal !== false
        && !str_starts_with($directoryReal . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Invalid Birthday Universe media path.');
    }
    return $path;
}

function birthday_photo_validate(array $file): array
{
    $size = max(0, (int)($file['size'] ?? 0));
    if ($size > birthday_media_max_bytes()) {
        api_response(false, 'BIRTHDAY_PHOTO_TOO_LARGE', 'Your image is too large. Maximum size is 5 MB.', [
            'max_bytes' => birthday_media_max_bytes(),
        ], 422);
    }
    return znews_media_validate_upload($file);
}

function birthday_photo_store(array $validated, string $targetType, string $targetId): array
{
    $optimized = znews_media_optimize_file((string)$validated['tmp'], (string)$validated['mime']);
    if (empty($optimized['ok']) || !is_file((string)($optimized['tmp'] ?? ''))) {
        api_response(false, 'BIRTHDAY_PHOTO_OPTIMIZATION_FAILED', 'Image could not be optimized.', [], 422);
    }
    $mediaId = znews_make_id('ZBM');
    $now = birthday_now();
    $key = birthday_media_key('photos', $mediaId, (string)$optimized['extension'], $now);
    $directory = birthday_media_directory('photos', $now);
    $target = $directory . DIRECTORY_SEPARATOR . basename($key);
    if (!@rename((string)$optimized['tmp'], $target)) {
        if (!@copy((string)$optimized['tmp'], $target)) {
            @unlink((string)$optimized['tmp']);
            api_response(false, 'BIRTHDAY_PHOTO_STORE_FAILED', 'Image could not be stored.', [], 503);
        }
        @unlink((string)$optimized['tmp']);
    }
    @chmod($target, 0640);
    $row = [
        'id' => $mediaId,
        'kind' => 'PHOTO',
        'target_type' => strtoupper($targetType),
        'target_id' => $targetId,
        'draft_id' => strtoupper($targetType) === 'DRAFT' ? $targetId : '',
        'universe_id' => strtoupper($targetType) === 'UNIVERSE' ? $targetId : '',
        'storage_key' => $key,
        'mime' => (string)$optimized['mime'],
        'size_bytes' => max(0, (int)$optimized['size_bytes']),
        'width' => max(0, (int)$optimized['width']),
        'height' => max(0, (int)$optimized['height']),
        'sha256' => (string)$optimized['sha256'],
        'status' => strtoupper($targetType) === 'UNIVERSE' ? 'ACTIVE' : 'DRAFT',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => strtoupper($targetType) === 'DRAFT'
            ? $now + (int)birthday_settings()['draft_ttl_seconds']
            : 0,
    ];
    if (!fb_put(birthday_path('MEDIA', $mediaId), $row)) {
        @unlink($target);
        api_response(false, 'BIRTHDAY_PHOTO_RECORD_FAILED', 'Image could not be stored.', [], 503);
    }
    return $row;
}

function birthday_photo_attach_to_draft(array $draft, array $media): array
{
    $oldId = trim((string)($draft['photo_media_id'] ?? ''));
    $draft['photo_media_id'] = (string)$media['id'];
    $draft['updated_at'] = birthday_now();
    $updates = [birthday_path('DRAFTS', (string)$draft['id']) => $draft];
    if ($oldId !== '') {
        $updates[birthday_path('MEDIA', $oldId) . '/status'] = 'REPLACED';
        $updates[birthday_path('MEDIA', $oldId) . '/updated_at'] = birthday_now();
    }
    if (!fb_patch('', $updates)) {
        birthday_media_delete_files($media);
        fb_delete(birthday_path('MEDIA', (string)$media['id']));
        api_response(false, 'BIRTHDAY_PHOTO_ATTACH_FAILED', 'Image could not be attached to the draft.', [], 503);
    }
    return $draft;
}

function birthday_photo_attach_to_universe(array $universe, array $media): array
{
    $oldId = trim((string)($universe['photo_media_id'] ?? ''));
    $universe['photo_media_id'] = (string)$media['id'];
    $universe['updated_at'] = birthday_now();
    $updates = [birthday_path('UNIVERSES', (string)$universe['id']) => $universe];
    if ($oldId !== '') {
        $updates[birthday_path('MEDIA', $oldId) . '/status'] = 'REPLACED';
        $updates[birthday_path('MEDIA', $oldId) . '/updated_at'] = birthday_now();
    }
    if (!fb_patch('', $updates)) {
        birthday_media_delete_files($media);
        fb_delete(birthday_path('MEDIA', (string)$media['id']));
        api_response(false, 'BIRTHDAY_PHOTO_ATTACH_FAILED', 'Image could not be attached to the Universe.', [], 503);
    }
    return $universe;
}

function birthday_media_delete_files(array $media): void
{
    $key = trim((string)($media['storage_key'] ?? ''));
    if ($key === '') {
        return;
    }
    try {
        $path = birthday_media_resolve($key);
        if (is_file($path)) {
            @unlink($path);
        }
    } catch (Throwable $error) {
        // Cleanup remains idempotent even when a stale key is malformed.
    }
}

function birthday_stream_file(string $path, string $mime, string $cacheControl, bool $allowRanges = false): void
{
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Not Found');
    }
    $size = max(0, (int)filesize($path));
    $start = 0;
    $end = max(0, $size - 1);
    $status = 200;
    if ($allowRanges && isset($_SERVER['HTTP_RANGE'])
        && preg_match('/^bytes=(\d*)-(\d*)$/D', trim((string)$_SERVER['HTTP_RANGE']), $matches) === 1) {
        if ($matches[1] !== '') {
            $start = max(0, (int)$matches[1]);
        }
        if ($matches[2] !== '') {
            $end = min($end, (int)$matches[2]);
        }
        if ($start <= $end && $start < $size) {
            $status = 206;
        } else {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
    }
    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $length);
    header('Cache-Control: ' . $cacheControl);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: ' . ($allowRanges ? 'bytes' : 'none'));
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        exit;
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        http_response_code(503);
        exit;
    }
    if ($start > 0) {
        fseek($handle, $start);
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $remaining));
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

function birthday_music_validate(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = max(0, (int)($file['size'] ?? 0));
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        api_response(false, 'BIRTHDAY_MUSIC_UPLOAD_INVALID', 'Music upload could not be verified.', [], 400);
    }
    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        api_response(false, 'BIRTHDAY_MUSIC_TOO_LARGE', 'Music file must not exceed 15 MB.', [], 422);
    }
    $mime = znews_media_detect_mime($tmp);
    $allowed = [
        'audio/mpeg' => ['mp3', 'audio/mpeg'],
        'audio/mp3' => ['mp3', 'audio/mpeg'],
        'audio/ogg' => ['ogg', 'audio/ogg'],
        'application/ogg' => ['ogg', 'audio/ogg'],
        'audio/mp4' => ['m4a', 'audio/mp4'],
        'audio/x-m4a' => ['m4a', 'audio/mp4'],
    ];
    if (!isset($allowed[$mime])) {
        api_response(false, 'BIRTHDAY_MUSIC_UNSUPPORTED', 'Only MP3, OGG and M4A audio is supported.', [], 422);
    }
    $head = file_get_contents($tmp, false, null, 0, 32);
    $extension = $allowed[$mime][0];
    $signatureOk = is_string($head) && match ($extension) {
        'mp3' => str_starts_with($head, 'ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
        'ogg' => str_starts_with($head, 'OggS'),
        'm4a' => strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp',
        default => false,
    };
    if (!$signatureOk) {
        api_response(false, 'BIRTHDAY_MUSIC_INVALID_SIGNATURE', 'Music file signature is invalid.', [], 422);
    }
    return [
        'tmp' => $tmp,
        'size' => $size,
        'extension' => $extension,
        'mime' => $allowed[$mime][1],
        'sha256' => (string)hash_file('sha256', $tmp),
    ];
}

function birthday_music_store(array $validated, string $name, int $duration, string $adminUid): array
{
    $safeName = birthday_bounded_text($name, 1, 80, 'music name');
    $musicId = znews_make_id('ZMU');
    $now = birthday_now();
    $key = birthday_media_key('music', $musicId, (string)$validated['extension'], $now);
    $target = birthday_media_directory('music', $now) . DIRECTORY_SEPARATOR . basename($key);
    if (!@move_uploaded_file((string)$validated['tmp'], $target)) {
        if (!@copy((string)$validated['tmp'], $target)) {
            api_response(false, 'BIRTHDAY_MUSIC_STORE_FAILED', 'Music could not be stored.', [], 503);
        }
    }
    @chmod($target, 0640);
    $row = [
        'id' => $musicId,
        'name' => $safeName,
        'duration' => max(0, min(7200, $duration)),
        'storage_key' => $key,
        'mime' => (string)$validated['mime'],
        'size_bytes' => (int)$validated['size'],
        'sha256' => (string)$validated['sha256'],
        'active' => true,
        'rights_confirmed' => true,
        'created_by' => $adminUid,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    if (!fb_put(birthday_path('MUSIC', $musicId), $row)) {
        @unlink($target);
        api_response(false, 'BIRTHDAY_MUSIC_RECORD_FAILED', 'Music could not be saved.', [], 503);
    }
    return $row;
}
