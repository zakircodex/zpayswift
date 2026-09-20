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

function birthday_audio_max_bytes(): int
{
    $configured = defined('BIRTHDAY_UNIVERSE_AUDIO_MAX_BYTES')
        ? (int)constant('BIRTHDAY_UNIVERSE_AUDIO_MAX_BYTES')
        : 10 * 1024 * 1024;
    return max(1024 * 1024, min(15 * 1024 * 1024, $configured));
}

function birthday_audio_max_duration_seconds(): int
{
    $configured = defined('BIRTHDAY_UNIVERSE_AUDIO_MAX_DURATION_SECONDS')
        ? (int)constant('BIRTHDAY_UNIVERSE_AUDIO_MAX_DURATION_SECONDS')
        : 30;
    return max(5, min(30, $configured));
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
    if (!in_array($kind, ['photos', 'music', 'audio'], true)) {
        throw new RuntimeException('Invalid Birthday Universe storage type.');
    }
    $now = $now ?? birthday_now();
    $relative = $kind . '/' . date('Y/m', $now);
    $path = birthday_storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Birthday Universe storage is unavailable.');
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Birthday Universe storage is not writable.');
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
    if (preg_match('#^(?:photos|music|audio)/[0-9]{4}/(?:0[1-9]|1[0-2])/birthday_[a-z0-9_-]+\.(?:jpg|png|webp|mp3|m4a|ogg)$#D', $key) !== 1) {
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

function birthday_media_upload_request_id($value): string
{
    $requestId = trim((string)$value);
    if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $requestId) !== 1) {
        api_response(false, 'BIRTHDAY_MEDIA_REQUEST_INVALID', 'Upload security token is invalid.', [], 422);
    }
    return $requestId;
}

function birthday_media_deterministic_id(
    string $prefix,
    string $targetType,
    string $targetId,
    string $kind,
    string $requestId
): string {
    if ($requestId === '') {
        return znews_make_id($prefix);
    }
    return $prefix . strtoupper(substr(hash('sha256', implode('|', [
        'birthday-media-v2',
        strtoupper($targetType),
        $targetId,
        strtoupper($kind),
        $requestId,
    ])), 0, 29));
}

function birthday_media_existing_upload(
    string $mediaId,
    string $kind,
    string $targetType,
    string $targetId,
    string $sourceSha256
): ?array {
    $existing = fb_get(birthday_path('MEDIA', $mediaId));
    if (!is_array($existing)) {
        return null;
    }
    $matches = hash_equals(strtoupper($kind), strtoupper((string)($existing['kind'] ?? '')))
        && hash_equals(strtoupper($targetType), strtoupper((string)($existing['target_type'] ?? '')))
        && hash_equals($targetId, (string)($existing['target_id'] ?? ''))
        && hash_equals(strtolower($sourceSha256), strtolower((string)($existing['source_sha256'] ?? '')));
    if (!$matches) {
        api_response(false, 'BIRTHDAY_MEDIA_REQUEST_CONFLICT', 'This upload token was already used for another file.', [], 409);
    }
    return $existing;
}

function birthday_media_store_atomic(string $source, string $target, int $expectedSize, string $expectedSha256): void
{
    $temporary = $target . '.part-' . bin2hex(random_bytes(6));
    $input = @fopen($source, 'rb');
    $output = @fopen($temporary, 'xb');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($temporary);
        throw new RuntimeException('Birthday Universe media storage could not be opened.');
    }
    try {
        $written = stream_copy_to_stream($input, $output);
        fflush($output);
        if (function_exists('fsync')) {
            @fsync($output);
        }
    } finally {
        fclose($input);
        fclose($output);
    }
    $size = is_file($temporary) ? (int)filesize($temporary) : 0;
    $sha256 = is_file($temporary) ? (string)hash_file('sha256', $temporary) : '';
    if ($written === false || $size !== $expectedSize || !hash_equals(strtolower($expectedSha256), strtolower($sha256))) {
        @unlink($temporary);
        throw new RuntimeException('Birthday Universe media failed integrity verification.');
    }
    if (is_file($target)) {
        $existingSize = (int)filesize($target);
        $existingSha = (string)hash_file('sha256', $target);
        if ($existingSize === $expectedSize && hash_equals(strtolower($expectedSha256), strtolower($existingSha))) {
            @unlink($temporary);
            return;
        }
        @unlink($target);
    }
    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('Birthday Universe media could not be committed.');
    }
    @chmod($target, 0640);
    clearstatcache(true, $target);
    if (!is_file($target) || !is_readable($target)
        || (int)filesize($target) !== $expectedSize
        || !hash_equals(strtolower($expectedSha256), strtolower((string)hash_file('sha256', $target)))) {
        @unlink($target);
        throw new RuntimeException('Birthday Universe media could not be read back.');
    }
}

function birthday_media_blob_encode(string $mediaId, string $mime, string $content, string $expectedSha256, int $createdAt): array
{
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $size = strlen($content);
    $maximum = znews_media_optimized_max_bytes();
    if ($size <= 0 || $size > $maximum) {
        throw new RuntimeException('Birthday Universe photo fallback is outside the allowed size.');
    }
    $sha256 = hash('sha256', $content);
    $expectedSha256 = strtolower(trim($expectedSha256));
    if (strlen($expectedSha256) !== 64 || !ctype_xdigit($expectedSha256) || !hash_equals($expectedSha256, $sha256)) {
        throw new RuntimeException('Birthday Universe photo fallback failed integrity validation.');
    }
    if (!isset(znews_media_allowed_types()[strtolower(trim($mime))])) {
        throw new RuntimeException('Birthday Universe photo fallback has an invalid content type.');
    }
    return [
        'media_id' => $mediaId,
        'mime' => strtolower(trim($mime)),
        'size_bytes' => $size,
        'sha256' => $sha256,
        'content_b64' => base64_encode($content),
        'created_at' => $createdAt,
    ];
}

function birthday_media_blob_decode(array $blob, array $media): ?string
{
    $mime = strtolower(trim((string)($blob['mime'] ?? '')));
    $mediaMime = strtolower(trim((string)($media['mime'] ?? '')));
    $size = max(0, (int)($blob['size_bytes'] ?? 0));
    $mediaSize = max(0, (int)($media['size_bytes'] ?? 0));
    $sha256 = strtolower(trim((string)($blob['sha256'] ?? '')));
    $mediaSha256 = strtolower(trim((string)($media['sha256'] ?? '')));
    $encoded = trim((string)($blob['content_b64'] ?? ''));
    $maximum = znews_media_optimized_max_bytes();
    $maximumEncoded = (int)(ceil($maximum / 3) * 4) + 8;
    if ($mime === '' || $mime !== $mediaMime || !isset(znews_media_allowed_types()[$mime])
        || $size <= 0 || $size > $maximum || $mediaSize !== $size
        || strlen($sha256) !== 64 || !ctype_xdigit($sha256)
        || strlen($mediaSha256) !== 64 || !ctype_xdigit($mediaSha256) || !hash_equals($mediaSha256, $sha256)
        || $encoded === '' || strlen($encoded) > $maximumEncoded) {
        return null;
    }
    $content = base64_decode($encoded, true);
    if (!is_string($content) || strlen($content) !== $size || !hash_equals($sha256, hash('sha256', $content))) {
        return null;
    }
    return $content;
}

function birthday_media_blob_bytes(array $media): ?string
{
    $mediaId = trim((string)($media['id'] ?? ''));
    if ($mediaId === '') {
        return null;
    }
    $blob = fb_get(birthday_path('MEDIA_BLOBS', $mediaId));
    return is_array($blob) ? birthday_media_blob_decode($blob, $media) : null;
}

function birthday_photo_validate(array $file): array
{
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        api_response(false, 'BIRTHDAY_PHOTO_SERVER_LIMIT', 'The photo reached the server before it could be resized. Please try again.', [
            'upload_error' => $uploadError,
            'max_bytes' => birthday_media_max_bytes(),
        ], 422);
    }
    $size = max(0, (int)($file['size'] ?? 0));
    if ($size > birthday_media_max_bytes()) {
        api_response(false, 'BIRTHDAY_PHOTO_TOO_LARGE', 'Your image is too large. Maximum size is 5 MB.', [
            'max_bytes' => birthday_media_max_bytes(),
        ], 422);
    }
    return znews_media_validate_upload($file);
}

function birthday_photo_prepare(array $validated): array
{
    $optimized = znews_media_optimize_file((string)$validated['tmp'], (string)$validated['mime']);
    if (!empty($optimized['ok']) && is_file((string)($optimized['tmp'] ?? ''))) {
        return $optimized;
    }

    $source = (string)($validated['tmp'] ?? '');
    $size = max(0, (int)($validated['size'] ?? (is_file($source) ? filesize($source) : 0)));
    $mime = strtolower(trim((string)($validated['mime'] ?? '')));
    $extension = strtolower(trim((string)($validated['extension'] ?? '')));
    $sha256 = strtolower(trim((string)($validated['sha256'] ?? '')));
    $width = max(0, (int)($validated['width'] ?? 0));
    $height = max(0, (int)($validated['height'] ?? 0));
    $allowed = znews_media_allowed_types();
    if (!is_file($source) || !is_readable($source) || $size <= 0 || $size > birthday_media_max_bytes()
        || !isset($allowed[$mime]) || $allowed[$mime] !== $extension
        || $width < 80 || $height < 80 || strlen($sha256) !== 64 || !ctype_xdigit($sha256)) {
        return ['ok' => false, 'code' => 'BIRTHDAY_PHOTO_PREPARATION_FAILED'];
    }
    $temporary = tempnam(sys_get_temp_dir(), 'zsky_birthday_');
    if (!is_string($temporary) || !copy($source, $temporary)) {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        return ['ok' => false, 'code' => 'BIRTHDAY_PHOTO_PREPARATION_WRITE_FAILED'];
    }
    $copiedHash = hash_file('sha256', $temporary);
    if (!is_string($copiedHash) || !hash_equals($sha256, strtolower($copiedHash))) {
        @unlink($temporary);
        return ['ok' => false, 'code' => 'BIRTHDAY_PHOTO_PREPARATION_VERIFY_FAILED'];
    }
    $orientation = znews_media_jpeg_orientation($source, $mime);
    if (in_array($orientation, [5, 6, 7, 8], true)) {
        [$width, $height] = [$height, $width];
    }
    return [
        'ok' => true,
        'tmp' => $temporary,
        'mime' => $mime,
        'extension' => $extension,
        'size_bytes' => $size,
        'width' => $width,
        'height' => $height,
        'sha256' => $sha256,
        'optimization_fallback' => true,
    ];
}

function birthday_photo_store(array $validated, string $targetType, string $targetId, string $requestId = ''): array
{
    $targetType = strtoupper(trim($targetType));
    if (!in_array($targetType, ['DRAFT', 'UNIVERSE'], true)) {
        api_response(false, 'BIRTHDAY_PHOTO_TARGET_INVALID', 'Photo upload target is invalid.', [], 422);
    }
    $sourceHash = $validated['sha256'] ?? hash_file('sha256', (string)$validated['tmp']);
    $sourceSha256 = is_string($sourceHash) ? strtolower(trim($sourceHash)) : '';
    if (strlen($sourceSha256) !== 64 || !ctype_xdigit($sourceSha256)) {
        api_response(false, 'BIRTHDAY_PHOTO_VERIFY_FAILED', 'Image could not be verified.', [], 503);
    }
    $mediaId = birthday_media_deterministic_id('ZBM', $targetType, $targetId, 'PHOTO', $requestId);
    $existing = birthday_media_existing_upload($mediaId, 'PHOTO', $targetType, $targetId, $sourceSha256);
    if (is_array($existing)) {
        try {
            $existingPath = birthday_media_resolve((string)($existing['storage_key'] ?? ''));
            if (is_file($existingPath) && is_readable($existingPath)) {
                return $existing;
            }
        } catch (Throwable $error) {
        }
        if (birthday_media_blob_bytes($existing) !== null) {
            return $existing;
        }
        fb_delete(birthday_path('MEDIA', $mediaId));
    }

    $optimized = birthday_photo_prepare($validated);
    if (empty($optimized['ok']) || !is_file((string)($optimized['tmp'] ?? ''))) {
        api_response(false, 'BIRTHDAY_PHOTO_OPTIMIZATION_FAILED', 'Image could not be optimized.', [], 422);
    }
    if (max(0, (int)($optimized['size_bytes'] ?? 0)) > znews_media_optimized_max_bytes()) {
        @unlink((string)$optimized['tmp']);
        api_response(false, 'BIRTHDAY_PHOTO_OPTIMIZATION_REQUIRED', 'The photo could not be reduced to a safe delivery size. Please select it again.', [], 422);
    }
    $now = birthday_now();
    $key = birthday_media_key('photos', $mediaId, (string)$optimized['extension'], $now);
    try {
        $directory = birthday_media_directory('photos', $now);
    } catch (Throwable $error) {
        @unlink((string)$optimized['tmp']);
        api_response(false, 'BIRTHDAY_PHOTO_STORAGE_UNAVAILABLE', 'Photo storage is temporarily unavailable.', [], 503);
    }
    $target = $directory . DIRECTORY_SEPARATOR . basename($key);
    try {
        birthday_media_store_atomic(
            (string)$optimized['tmp'],
            $target,
            max(0, (int)$optimized['size_bytes']),
            (string)$optimized['sha256']
        );
    } catch (Throwable $error) {
        @unlink((string)$optimized['tmp']);
        api_response(false, 'BIRTHDAY_PHOTO_STORE_FAILED', 'Image could not be stored safely. Please try again.', [], 503);
    }
    @unlink((string)$optimized['tmp']);
    $row = [
        'id' => $mediaId,
        'kind' => 'PHOTO',
        'target_type' => $targetType,
        'target_id' => $targetId,
        'draft_id' => $targetType === 'DRAFT' ? $targetId : '',
        'universe_id' => $targetType === 'UNIVERSE' ? $targetId : '',
        'storage_key' => $key,
        'mime' => (string)$optimized['mime'],
        'size_bytes' => max(0, (int)$optimized['size_bytes']),
        'width' => max(0, (int)$optimized['width']),
        'height' => max(0, (int)$optimized['height']),
        'sha256' => (string)$optimized['sha256'],
        'source_sha256' => $sourceSha256,
        'optimization_fallback' => !empty($optimized['optimization_fallback']),
        'storage_driver' => 'PRIVATE_FILESYSTEM_WITH_FIREBASE_FALLBACK',
        'status' => $targetType === 'UNIVERSE' ? 'ACTIVE' : 'DRAFT',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $targetType === 'DRAFT'
            ? $now + (int)birthday_settings()['draft_ttl_seconds']
            : 0,
    ];
    $storedContent = @file_get_contents($target);
    if (!is_string($storedContent)) {
        @unlink($target);
        api_response(false, 'BIRTHDAY_PHOTO_FALLBACK_FAILED', 'Image could not be prepared for reliable delivery.', [], 503);
    }
    try {
        $blob = birthday_media_blob_encode(
            $mediaId,
            (string)$row['mime'],
            $storedContent,
            (string)$row['sha256'],
            $now
        );
    } catch (Throwable $error) {
        @unlink($target);
        api_response(false, 'BIRTHDAY_PHOTO_FALLBACK_FAILED', 'Image could not be prepared for reliable delivery.', [], 503);
    }
    if (!fb_patch('', [
        birthday_path('MEDIA', $mediaId) => $row,
        birthday_path('MEDIA_BLOBS', $mediaId) => $blob,
    ])) {
        @unlink($target);
        api_response(false, 'BIRTHDAY_PHOTO_RECORD_FAILED', 'Image could not be stored.', [], 503);
    }
    return $row;
}

function birthday_photo_attach_to_draft(array $draft, array $media): array
{
    $oldId = trim((string)($draft['photo_media_id'] ?? ''));
    $draft['photo_media_id'] = (string)$media['id'];
    $draft['photo_width'] = max(0, (int)($media['width'] ?? 0));
    $draft['photo_height'] = max(0, (int)($media['height'] ?? 0));
    $draft['updated_at'] = birthday_now();
    $updates = [birthday_path('DRAFTS', (string)$draft['id']) => $draft];
    if ($oldId !== '' && $oldId !== (string)$media['id']) {
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
    $universe['photo_width'] = max(0, (int)($media['width'] ?? 0));
    $universe['photo_height'] = max(0, (int)($media['height'] ?? 0));
    $universe['updated_at'] = birthday_now();
    $updates = [birthday_path('UNIVERSES', (string)$universe['id']) => $universe];
    if ($oldId !== '' && $oldId !== (string)$media['id']) {
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
    $mediaId = trim((string)($media['id'] ?? ''));
    if ($mediaId !== '') {
        fb_delete(birthday_path('MEDIA_BLOBS', $mediaId));
    }
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

function birthday_stream_bytes(string $content, string $mime, string $cacheControl): void
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: ' . $cacheControl);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: none');
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo $content;
    }
    exit;
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
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffixLength = max(1, min($size, (int)$matches[2]));
            $start = max(0, $size - $suffixLength);
            $end = max(0, $size - 1);
        } elseif ($matches[1] !== '') {
            $start = max(0, (int)$matches[1]);
            if ($matches[2] !== '') {
                $end = min($end, (int)$matches[2]);
            }
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

function birthday_audio_u32be(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) {
        return 0;
    }
    $value = unpack('Nvalue', substr($data, $offset, 4));
    return is_array($value) ? max(0, (int)($value['value'] ?? 0)) : 0;
}

function birthday_audio_u32le(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) {
        return 0;
    }
    $value = unpack('Vvalue', substr($data, $offset, 4));
    return is_array($value) ? max(0, (int)($value['value'] ?? 0)) : 0;
}

function birthday_audio_u64be(string $data, int $offset): float
{
    return (birthday_audio_u32be($data, $offset) * 4294967296.0)
        + birthday_audio_u32be($data, $offset + 4);
}

function birthday_audio_u64le(string $data, int $offset): float
{
    return birthday_audio_u32le($data, $offset)
        + (birthday_audio_u32le($data, $offset + 4) * 4294967296.0);
}

function birthday_audio_mp3_duration(string $data): float
{
    $length = strlen($data);
    $offset = 0;
    if ($length >= 10 && substr($data, 0, 3) === 'ID3') {
        $tagSize = ((ord($data[6]) & 0x7F) << 21)
            | ((ord($data[7]) & 0x7F) << 14)
            | ((ord($data[8]) & 0x7F) << 7)
            | (ord($data[9]) & 0x7F);
        $offset = min($length, 10 + $tagSize + ((ord($data[5]) & 0x10) !== 0 ? 10 : 0));
    }
    $mpeg1Bitrates = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
    $mpeg2Bitrates = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
    $baseRates = [44100, 48000, 32000];
    $duration = 0.0;
    $frames = 0;
    while ($offset + 4 <= $length) {
        $b1 = ord($data[$offset]);
        $b2 = ord($data[$offset + 1]);
        $b3 = ord($data[$offset + 2]);
        if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) {
            $offset++;
            continue;
        }
        $versionBits = ($b2 >> 3) & 0x03;
        $layerBits = ($b2 >> 1) & 0x03;
        $bitrateIndex = ($b3 >> 4) & 0x0F;
        $sampleIndex = ($b3 >> 2) & 0x03;
        $padding = ($b3 >> 1) & 0x01;
        if ($versionBits === 1 || $layerBits !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $sampleIndex === 3) {
            $offset++;
            continue;
        }
        $mpeg1 = $versionBits === 3;
        $bitrate = ($mpeg1 ? $mpeg1Bitrates : $mpeg2Bitrates)[$bitrateIndex];
        $divisor = $versionBits === 3 ? 1 : ($versionBits === 2 ? 2 : 4);
        $sampleRate = (int)($baseRates[$sampleIndex] / $divisor);
        $samples = $mpeg1 ? 1152 : 576;
        $frameLength = (int)floor(($mpeg1 ? 144000 : 72000) * $bitrate / $sampleRate) + $padding;
        if ($frameLength < 24 || $offset + $frameLength > $length) {
            $offset++;
            continue;
        }
        $duration += $samples / $sampleRate;
        $frames++;
        $offset += $frameLength;
    }
    return $frames > 0 ? $duration : 0.0;
}

function birthday_audio_ogg_duration(string $data): float
{
    $sampleRate = 0;
    $vorbis = strpos($data, "\x01vorbis");
    if ($vorbis !== false && $vorbis + 12 <= strlen($data)) {
        $sampleRate = birthday_audio_u32le($data, $vorbis + 8);
    } elseif (strpos($data, 'OpusHead') !== false) {
        $sampleRate = 48000;
    }
    if ($sampleRate <= 0) {
        return 0.0;
    }
    $maximumGranule = 0.0;
    $offset = 0;
    $length = strlen($data);
    while (($page = strpos($data, 'OggS', $offset)) !== false) {
        if ($page + 27 > $length) {
            break;
        }
        $segments = ord($data[$page + 26]);
        if ($page + 27 + $segments > $length) {
            break;
        }
        $granule = birthday_audio_u64le($data, $page + 6);
        if ($granule > $maximumGranule) {
            $maximumGranule = $granule;
        }
        $bodyLength = 0;
        for ($index = 0; $index < $segments; $index++) {
            $bodyLength += ord($data[$page + 27 + $index]);
        }
        $offset = $page + 27 + $segments + $bodyLength;
    }
    return $maximumGranule > 0 ? $maximumGranule / $sampleRate : 0.0;
}

function birthday_audio_mp4_duration(string $data, int $start = 0, ?int $end = null): float
{
    $length = strlen($data);
    $end = min($length, $end ?? $length);
    $offset = max(0, $start);
    while ($offset + 8 <= $end) {
        $size = birthday_audio_u32be($data, $offset);
        $type = substr($data, $offset + 4, 4);
        $header = 8;
        if ($size === 1) {
            if ($offset + 16 > $end) {
                break;
            }
            $size = (int)birthday_audio_u64be($data, $offset + 8);
            $header = 16;
        } elseif ($size === 0) {
            $size = $end - $offset;
        }
        if ($size < $header || $offset + $size > $end) {
            break;
        }
        $payload = $offset + $header;
        if ($type === 'mvhd' && $payload + 20 <= $offset + $size) {
            $version = ord($data[$payload]);
            $timeScaleOffset = $version === 1 ? $payload + 20 : $payload + 12;
            $durationOffset = $version === 1 ? $payload + 24 : $payload + 16;
            $timeScale = birthday_audio_u32be($data, $timeScaleOffset);
            $duration = $version === 1
                ? birthday_audio_u64be($data, $durationOffset)
                : birthday_audio_u32be($data, $durationOffset);
            return $timeScale > 0 && $duration > 0 ? $duration / $timeScale : 0.0;
        }
        if (in_array($type, ['moov', 'trak', 'mdia'], true)) {
            $nested = birthday_audio_mp4_duration($data, $payload, $offset + $size);
            if ($nested > 0) {
                return $nested;
            }
        }
        $offset += $size;
    }
    return 0.0;
}

function birthday_custom_audio_validate(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = max(0, (int)($file['size'] ?? 0));
    $maximumBytes = birthday_audio_max_bytes();
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        api_response(false, 'BIRTHDAY_AUDIO_UPLOAD_INVALID', 'Audio upload could not be verified.', [], 400);
    }
    if ($size <= 0 || $size > $maximumBytes) {
        api_response(false, 'BIRTHDAY_AUDIO_TOO_LARGE', 'Audio file must not exceed ' . (int)ceil($maximumBytes / 1048576) . ' MB.', [
            'max_bytes' => $maximumBytes,
        ], 422);
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
        api_response(false, 'BIRTHDAY_AUDIO_UNSUPPORTED', 'Only MP3, OGG and M4A audio is supported.', [], 422);
    }
    $data = @file_get_contents($tmp);
    if (!is_string($data) || strlen($data) !== $size) {
        api_response(false, 'BIRTHDAY_AUDIO_READ_FAILED', 'Audio file could not be read.', [], 503);
    }
    $extension = $allowed[$mime][0];
    $signatureOk = match ($extension) {
        'mp3' => str_starts_with($data, 'ID3') || (strlen($data) >= 2 && ord($data[0]) === 0xFF && (ord($data[1]) & 0xE0) === 0xE0),
        'ogg' => str_starts_with($data, 'OggS'),
        'm4a' => strlen($data) >= 12 && substr($data, 4, 4) === 'ftyp',
        default => false,
    };
    if (!$signatureOk) {
        api_response(false, 'BIRTHDAY_AUDIO_INVALID_SIGNATURE', 'Audio file signature is invalid.', [], 422);
    }
    $duration = match ($extension) {
        'mp3' => birthday_audio_mp3_duration($data),
        'ogg' => birthday_audio_ogg_duration($data),
        'm4a' => birthday_audio_mp4_duration($data),
        default => 0.0,
    };
    if ($duration <= 0) {
        api_response(false, 'BIRTHDAY_AUDIO_DURATION_INVALID', 'Audio duration could not be verified.', [], 422);
    }
    $maximum = birthday_audio_max_duration_seconds();
    if ($duration > $maximum + 0.1) {
        api_response(false, 'BIRTHDAY_AUDIO_TOO_LONG', 'Custom audio must be ' . $maximum . ' seconds or shorter.', [
            'max_duration_seconds' => $maximum,
            'duration_seconds' => round($duration, 2),
        ], 422);
    }
    return [
        'tmp' => $tmp,
        'size' => $size,
        'extension' => $extension,
        'mime' => $allowed[$mime][1],
        'sha256' => hash('sha256', $data),
        'duration_ms' => max(1, (int)round($duration * 1000)),
    ];
}

function birthday_custom_audio_store(
    array $validated,
    string $targetType,
    string $targetId,
    string $requestId = ''
): array {
    $targetType = strtoupper(trim($targetType));
    if (!in_array($targetType, ['DRAFT', 'UNIVERSE'], true)) {
        api_response(false, 'BIRTHDAY_AUDIO_TARGET_INVALID', 'Audio upload target is invalid.', [], 422);
    }
    $sourceSha256 = strtolower(trim((string)($validated['sha256'] ?? '')));
    $mediaId = birthday_media_deterministic_id('ZBA', $targetType, $targetId, 'AUDIO', $requestId);
    $existing = birthday_media_existing_upload($mediaId, 'AUDIO', $targetType, $targetId, $sourceSha256);
    if (is_array($existing)) {
        try {
            $existingPath = birthday_media_resolve((string)($existing['storage_key'] ?? ''));
            if (is_file($existingPath) && is_readable($existingPath)) {
                return $existing;
            }
        } catch (Throwable $error) {
        }
        fb_delete(birthday_path('MEDIA', $mediaId));
    }
    $now = birthday_now();
    $key = birthday_media_key('audio', $mediaId, (string)$validated['extension'], $now);
    try {
        $directory = birthday_media_directory('audio', $now);
        $target = $directory . DIRECTORY_SEPARATOR . basename($key);
        birthday_media_store_atomic(
            (string)$validated['tmp'],
            $target,
            (int)$validated['size'],
            $sourceSha256
        );
    } catch (Throwable $error) {
        api_response(false, 'BIRTHDAY_AUDIO_STORE_FAILED', 'Audio could not be stored safely. Please try again.', [], 503);
    }
    $row = [
        'id' => $mediaId,
        'kind' => 'AUDIO',
        'target_type' => $targetType,
        'target_id' => $targetId,
        'draft_id' => $targetType === 'DRAFT' ? $targetId : '',
        'universe_id' => $targetType === 'UNIVERSE' ? $targetId : '',
        'storage_key' => $key,
        'mime' => (string)$validated['mime'],
        'size_bytes' => (int)$validated['size'],
        'duration_ms' => (int)$validated['duration_ms'],
        'sha256' => $sourceSha256,
        'source_sha256' => $sourceSha256,
        'rights_confirmed' => true,
        'storage_driver' => 'PRIVATE_FILESYSTEM',
        'status' => $targetType === 'UNIVERSE' ? 'ACTIVE' : 'DRAFT',
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $targetType === 'DRAFT'
            ? $now + (int)birthday_settings()['draft_ttl_seconds']
            : 0,
    ];
    if (!fb_put(birthday_path('MEDIA', $mediaId), $row)) {
        @unlink($target);
        api_response(false, 'BIRTHDAY_AUDIO_RECORD_FAILED', 'Audio could not be stored.', [], 503);
    }
    return $row;
}

function birthday_audio_attach_to_draft(array $draft, array $media): array
{
    $oldId = trim((string)($draft['custom_audio_media_id'] ?? ''));
    $draft['custom_audio_media_id'] = (string)$media['id'];
    $draft['audio_mode'] = 'CUSTOM';
    $draft['music_id'] = '';
    $draft['updated_at'] = birthday_now();
    $updates = [birthday_path('DRAFTS', (string)$draft['id']) => $draft];
    if ($oldId !== '' && $oldId !== (string)$media['id']) {
        $updates[birthday_path('MEDIA', $oldId) . '/status'] = 'REPLACED';
        $updates[birthday_path('MEDIA', $oldId) . '/updated_at'] = birthday_now();
    }
    if (!fb_patch('', $updates)) {
        birthday_media_delete_files($media);
        fb_delete(birthday_path('MEDIA', (string)$media['id']));
        api_response(false, 'BIRTHDAY_AUDIO_ATTACH_FAILED', 'Audio could not be attached to the draft.', [], 503);
    }
    return $draft;
}

function birthday_audio_attach_to_universe(array $universe, array $media): array
{
    $oldId = trim((string)($universe['custom_audio_media_id'] ?? ''));
    $universe['custom_audio_media_id'] = (string)$media['id'];
    $universe['audio_mode'] = 'CUSTOM';
    $universe['music_id'] = '';
    $universe['updated_at'] = birthday_now();
    $updates = [birthday_path('UNIVERSES', (string)$universe['id']) => $universe];
    if ($oldId !== '' && $oldId !== (string)$media['id']) {
        $updates[birthday_path('MEDIA', $oldId) . '/status'] = 'REPLACED';
        $updates[birthday_path('MEDIA', $oldId) . '/updated_at'] = birthday_now();
    }
    if (!fb_patch('', $updates)) {
        birthday_media_delete_files($media);
        fb_delete(birthday_path('MEDIA', (string)$media['id']));
        api_response(false, 'BIRTHDAY_AUDIO_ATTACH_FAILED', 'Audio could not be attached to the Universe.', [], 503);
    }
    return $universe;
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
