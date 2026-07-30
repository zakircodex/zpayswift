<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_media_max_bytes(): int
{
    $configured = defined('ZNEWS_MEDIA_MAX_BYTES') ? (int)constant('ZNEWS_MEDIA_MAX_BYTES') : 8 * 1024 * 1024;
    return max(1024 * 1024, min(20 * 1024 * 1024, $configured));
}

function znews_media_max_pixels(): int
{
    $configured = defined('ZNEWS_MEDIA_MAX_PIXELS') ? (int)constant('ZNEWS_MEDIA_MAX_PIXELS') : 40000000;
    return max(1000000, min(80000000, $configured));
}

function znews_media_allowed_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function znews_media_storage_root(): string
{
    if (defined('ZNEWS_MEDIA_STORAGE_DIR') && trim((string)constant('ZNEWS_MEDIA_STORAGE_DIR')) !== '') {
        return rtrim(trim((string)constant('ZNEWS_MEDIA_STORAGE_DIR')), DIRECTORY_SEPARATOR);
    }

    $privateConfig = function_exists('app_private_config_path') ? app_private_config_path() : '';
    $privateRoot = $privateConfig !== '' ? dirname($privateConfig) : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private';

    return $privateRoot
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'znews'
        . DIRECTORY_SEPARATOR . 'posts';
}

function znews_media_ensure_storage_dir(string $relativeDir): string
{
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    if ($relativeDir === '' || preg_match('#^(?:[0-9]{4})/(?:0[1-9]|1[0-2])$#', $relativeDir) !== 1) {
        throw new RuntimeException('Invalid Z Sky 24 media storage directory.');
    }

    $root = znews_media_storage_root();
    $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create Z Sky 24 media storage directory.');
    }

    return $target;
}

function znews_media_storage_key(string $mediaId, string $extension, ?int $now = null): string
{
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $extension = strtolower(trim($extension));
    if (!in_array($extension, array_values(znews_media_allowed_types()), true)) {
        api_response(false, 'ZNEWS_MEDIA_INVALID_EXTENSION', 'Invalid image extension.', [], 422);
    }

    $now = $now ?? znews_now();
    return date('Y/m', $now) . '/znews_' . strtolower($mediaId) . '.' . $extension;
}

function znews_media_resolve_path(string $storageKey): string
{
    $storageKey = trim(str_replace('\\', '/', $storageKey), '/');
    if (preg_match('#^[0-9]{4}/(?:0[1-9]|1[0-2])/znews_[a-z0-9]+\.(?:jpg|png|webp)$#', $storageKey) !== 1) {
        throw new RuntimeException('Invalid Z Sky 24 media storage key.');
    }

    $root = rtrim(znews_media_storage_root(), DIRECTORY_SEPARATOR);
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey);
    $rootReal = realpath($root);
    $dirReal = realpath(dirname($path));
    if ($rootReal !== false && $dirReal !== false && !str_starts_with($dirReal . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Invalid Z Sky 24 media storage path.');
    }

    return $path;
}

function znews_media_input_file(): array
{
    foreach (['image', 'media', 'file', 'post_image'] as $field) {
        if (!empty($_FILES[$field]) && is_array($_FILES[$field])) {
            return [$field, (array)$_FILES[$field]];
        }
    }

    return ['', []];
}

function znews_media_detect_mime(string $tmp): string
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($mime === '' && class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
    }

    return strtolower(trim($mime));
}

function znews_media_validate_upload(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        api_response(false, 'ZNEWS_MEDIA_UPLOAD_FAILED', 'Image upload failed.', ['upload_error' => $error], 400);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        api_response(false, 'ZNEWS_MEDIA_UPLOAD_INVALID', 'Uploaded image could not be verified.', [], 400);
    }
    if ($size <= 0 || $size > znews_media_max_bytes()) {
        api_response(false, 'ZNEWS_MEDIA_TOO_LARGE', 'The selected image is too large.', [
            'max_bytes' => znews_media_max_bytes(),
        ], 422);
    }

    $originalName = strtolower(trim((string)($file['name'] ?? 'image')));
    if (preg_match('/\.(?:php|phtml|phar|cgi|pl|py|sh|asp|aspx|exe|com|bat|cmd)(?:\.|$)/i', $originalName) === 1) {
        api_response(false, 'ZNEWS_MEDIA_UNSUPPORTED', 'Choose a supported image file.', [], 422);
    }

    $mime = znews_media_detect_mime($tmp);
    $allowed = znews_media_allowed_types();
    if (!isset($allowed[$mime])) {
        api_response(false, 'ZNEWS_MEDIA_UNSUPPORTED', 'Only JPEG, PNG and WebP images are supported.', [], 422);
    }

    $info = @getimagesize($tmp);
    $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $detectedMime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
    if ($width < 80 || $height < 80 || $detectedMime !== $mime) {
        api_response(false, 'ZNEWS_MEDIA_INVALID_IMAGE', 'The selected file is not a valid image.', [], 422);
    }
    if ($width > 12000 || $height > 12000 || ($width * $height) > znews_media_max_pixels()) {
        api_response(false, 'ZNEWS_MEDIA_DIMENSIONS_TOO_LARGE', 'The image dimensions are too large.', [
            'max_pixels' => znews_media_max_pixels(),
        ], 422);
    }

    $sha256 = hash_file('sha256', $tmp);
    if (!is_string($sha256) || strlen($sha256) !== 64) {
        api_response(false, 'ZNEWS_MEDIA_HASH_FAILED', 'Image could not be verified.', [], 503);
    }

    return [
        'tmp' => $tmp,
        'size' => $size,
        'mime' => $mime,
        'extension' => $allowed[$mime],
        'width' => $width,
        'height' => $height,
        'sha256' => strtolower($sha256),
        'original_name' => substr($originalName, 0, 180),
    ];
}

function znews_media_gd_image(string $tmp, string $mime)
{
    if (!extension_loaded('gd')) {
        return null;
    }

    try {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmp) : null,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmp) : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null,
            default => null,
        };
    } catch (Throwable $e) {
        return null;
    }
}

function znews_media_dhash(string $tmp, string $mime): string
{
    $source = znews_media_gd_image($tmp, $mime);
    if (!$source) {
        return '';
    }

    $small = imagecreatetruecolor(9, 8);
    if (!$small) {
        imagedestroy($source);
        return '';
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $copied = imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, $sourceWidth, $sourceHeight);
    imagedestroy($source);
    if (!$copied) {
        imagedestroy($small);
        return '';
    }

    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $left = imagecolorat($small, $x, $y);
            $right = imagecolorat($small, $x + 1, $y);
            $leftGray = (((($left >> 16) & 0xFF) * 299) + ((($left >> 8) & 0xFF) * 587) + (($left & 0xFF) * 114));
            $rightGray = (((($right >> 16) & 0xFF) * 299) + ((($right >> 8) & 0xFF) * 587) + (($right & 0xFF) * 114));
            $bits .= $leftGray > $rightGray ? '1' : '0';
        }
    }
    imagedestroy($small);

    $hex = '';
    for ($i = 0; $i < 64; $i += 4) {
        $hex .= dechex(bindec(substr($bits, $i, 4)));
    }

    return strtolower($hex);
}

function znews_media_hamming_hex(string $left, string $right): int
{
    $left = strtolower(trim($left));
    $right = strtolower(trim($right));
    if (strlen($left) !== 16 || strlen($right) !== 16 || !ctype_xdigit($left) || !ctype_xdigit($right)) {
        return 65;
    }

    static $bits = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
    $distance = 0;
    for ($i = 0; $i < 16; $i++) {
        $distance += $bits[hexdec($left[$i]) ^ hexdec($right[$i])];
    }

    return $distance;
}

function znews_media_id(string $uid, string $idempotencyKey): string
{
    return 'ZNM' . strtoupper(substr(hash('sha256', $uid . '|' . $idempotencyKey), 0, 29));
}

function znews_media_path(string $mediaId): string
{
    return 'ZNEWS_MEDIA/' . znews_firebase_key($mediaId, 'media_id');
}

function znews_media_owner_path(string $uid, string $mediaId): string
{
    return 'ZNEWS_USER_MEDIA/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_firebase_key($mediaId, 'media_id');
}

function znews_media_idempotency_path(string $uid, string $idempotencyKey): string
{
    return 'ZNEWS_MEDIA_IDEMPOTENCY/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . hash('sha256', trim($idempotencyKey));
}

function znews_media_sha_path(string $sha256): string
{
    $sha256 = strtolower(trim($sha256));
    if (strlen($sha256) !== 64 || !ctype_xdigit($sha256)) {
        api_response(false, 'ZNEWS_MEDIA_INVALID_HASH', 'Invalid image hash.', [], 422);
    }
    return 'ZNEWS_MEDIA_HASHES/SHA256/' . $sha256;
}

function znews_media_phash_bucket_path(string $dhash): string
{
    $dhash = strtolower(trim($dhash));
    if (strlen($dhash) !== 16 || !ctype_xdigit($dhash)) {
        api_response(false, 'ZNEWS_MEDIA_INVALID_FINGERPRINT', 'Invalid image fingerprint.', [], 422);
    }
    return 'ZNEWS_MEDIA_HASHES/DHASH_BUCKETS/' . substr($dhash, 0, 4);
}

function znews_media_safe_payload(array $row): array
{
    $mediaId = trim((string)($row['media_id'] ?? ''));
    $base = function_exists('app_api_base_path') ? app_api_base_path() : '/api';

    return [
        'media_id' => $mediaId,
        'status' => strtoupper(trim((string)($row['status'] ?? 'STAGED'))),
        'mime' => trim((string)($row['mime'] ?? '')),
        'size_bytes' => max(0, (int)($row['size_bytes'] ?? 0)),
        'width' => max(0, (int)($row['width'] ?? 0)),
        'height' => max(0, (int)($row['height'] ?? 0)),
        'duplicate_status' => strtoupper(trim((string)($row['duplicate_status'] ?? 'CLEAR'))),
        'near_duplicate_count' => max(0, (int)($row['near_duplicate_count'] ?? 0)),
        'copyright_status' => strtoupper(trim((string)($row['copyright_status'] ?? 'PENDING'))),
        'content_url' => $mediaId !== '' ? $base . '/znews/media/content.php?media_id=' . rawurlencode($mediaId) : '',
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
}

function znews_media_claim_idempotency(
    string $uid,
    string $mediaId,
    string $idempotencyKey,
    string $payloadHash
): array {
    $path = znews_media_idempotency_path($uid, $idempotencyKey);
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_REQUEST_READ_FAILED', 'message' => 'Image request could not be verified.', 'http_status' => 503];
        }

        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $existingHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($existingHash === '' || !hash_equals($existingHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT', 'message' => 'This idempotency key was already used for another image.', 'http_status' => 409];
            }
            $status = strtoupper(trim((string)($existing['status'] ?? '')));
            if ($status === 'COMPLETED') {
                $media = fb_get(znews_media_path((string)($existing['media_id'] ?? $mediaId)));
                if (is_array($media)) {
                    return ['ok' => true, 'idempotent_replay' => true, 'path' => $path, 'media' => $media];
                }
                return ['ok' => false, 'code' => 'ZNEWS_MEDIA_RECONCILIATION_REQUIRED', 'message' => 'Image request requires reconciliation.', 'http_status' => 503];
            }
            if ($status === 'PROCESSING' && (int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_MEDIA_UPLOAD_IN_PROGRESS', 'message' => 'This image is already being processed.', 'http_status' => 409];
            }
            if (!in_array($status, ['FAILED', 'PROCESSING'], true)) {
                return ['ok' => false, 'code' => 'ZNEWS_MEDIA_REQUEST_INVALID_STATE', 'message' => 'Image request is in an invalid state.', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_REQUEST_INVALID_RECORD', 'message' => 'Image request could not be verified.', 'http_status' => 409];
        }

        $claim = [
            'uid' => $uid,
            'media_id' => $mediaId,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + 90,
            'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_REQUEST_CLAIM_FAILED', 'message' => 'Image request could not be started.', 'http_status' => 503];
        }

        return ['ok' => true, 'idempotent_replay' => false, 'path' => $path, 'claim' => $claim];
    }

    return ['ok' => false, 'code' => 'ZNEWS_MEDIA_REQUEST_BUSY', 'message' => 'Image request is busy. Please try again.', 'http_status' => 409];
}

function znews_media_claim_sha(string $uid, string $mediaId, string $sha256): array
{
    $path = znews_media_sha_path($sha256);
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_DUPLICATE_CHECK_FAILED', 'message' => 'Image duplicate check is unavailable.', 'http_status' => 503];
        }

        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $existingMediaId = trim((string)($existing['media_id'] ?? ''));
            if ($existingMediaId === $mediaId) {
                return ['ok' => true, 'path' => $path, 'claim' => $existing];
            }

            $status = strtoupper(trim((string)($existing['status'] ?? '')));
            $lease = (int)($existing['lease_expires_at'] ?? 0);
            if ($status === 'COMPLETED' || ($status === 'PROCESSING' && $lease > $now)) {
                return ['ok' => false, 'code' => 'ZNEWS_MEDIA_DUPLICATE', 'message' => 'This image has already been uploaded.', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_DUPLICATE_CHECK_INVALID', 'message' => 'Image duplicate check is unavailable.', 'http_status' => 503];
        }

        $claim = [
            'media_id' => $mediaId,
            'owner_uid' => $uid,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + 90,
            'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_DUPLICATE_CLAIM_FAILED', 'message' => 'Image duplicate check could not be completed.', 'http_status' => 503];
        }

        return ['ok' => true, 'path' => $path, 'claim' => $claim];
    }

    return ['ok' => false, 'code' => 'ZNEWS_MEDIA_DUPLICATE_CHECK_BUSY', 'message' => 'Image duplicate check is busy.', 'http_status' => 409];
}

function znews_media_near_duplicates(string $dhash, int $threshold = 8, int $maximumCandidates = 200): array
{
    if ($dhash === '') {
        return [];
    }

    $rows = fb_get(znews_media_phash_bucket_path($dhash));
    if (!is_array($rows)) {
        return [];
    }

    $matches = [];
    $checked = 0;
    foreach ($rows as $mediaId => $row) {
        if (++$checked > $maximumCandidates || !is_array($row)) {
            break;
        }
        $candidate = strtolower(trim((string)($row['dhash'] ?? '')));
        $distance = znews_media_hamming_hex($dhash, $candidate);
        if ($distance <= $threshold) {
            $matches[] = [
                'media_id' => (string)$mediaId,
                'distance' => $distance,
            ];
        }
    }

    usort($matches, static fn(array $a, array $b): int => ((int)$a['distance']) <=> ((int)$b['distance']));
    return array_slice($matches, 0, 10);
}

function znews_media_mark_request_failed(array $requestClaim, array $shaClaim, string $code): void
{
    $now = znews_now();
    $requestPath = trim((string)($requestClaim['path'] ?? ''));
    if ($requestPath !== '') {
        @fb_patch($requestPath, [
            'status' => 'FAILED',
            'failure_code' => $code,
            'failed_at' => $now,
            'updated_at' => $now,
            'lease_expires_at' => 0,
        ]);
    }

    $shaPath = trim((string)($shaClaim['path'] ?? ''));
    if ($shaPath !== '') {
        @fb_patch($shaPath, [
            'status' => 'FAILED',
            'failure_code' => $code,
            'failed_at' => $now,
            'updated_at' => $now,
            'lease_expires_at' => 0,
        ]);
    }
}

function znews_media_create(array $auth, array $validated, string $idempotencyKey): array
{
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $mediaId = znews_media_id($uid, $idempotencyKey);
    $payloadHash = hash('sha256', implode('|', [
        $uid,
        $validated['sha256'],
        $validated['mime'],
        (string)$validated['size'],
        (string)$validated['width'],
        (string)$validated['height'],
    ]));

    $requestClaim = znews_media_claim_idempotency($uid, $mediaId, $idempotencyKey, $payloadHash);
    if (empty($requestClaim['ok'])) {
        return $requestClaim;
    }
    if (!empty($requestClaim['idempotent_replay']) && is_array($requestClaim['media'] ?? null)) {
        return ['ok' => true, 'idempotent_replay' => true, 'media' => znews_media_safe_payload((array)$requestClaim['media'])];
    }

    $shaClaim = znews_media_claim_sha($uid, $mediaId, (string)$validated['sha256']);
    if (empty($shaClaim['ok'])) {
        znews_media_mark_request_failed($requestClaim, [], (string)($shaClaim['code'] ?? 'ZNEWS_MEDIA_DUPLICATE_CHECK_FAILED'));
        return $shaClaim;
    }

    $dhash = znews_media_dhash((string)$validated['tmp'], (string)$validated['mime']);
    $nearDuplicates = znews_media_near_duplicates($dhash);
    $now = znews_now();
    $storageKey = znews_media_storage_key($mediaId, (string)$validated['extension'], $now);

    try {
        $targetDir = znews_media_ensure_storage_dir(dirname($storageKey));
        $target = $targetDir . DIRECTORY_SEPARATOR . basename($storageKey);
    } catch (Throwable $e) {
        znews_media_mark_request_failed($requestClaim, $shaClaim, 'ZNEWS_MEDIA_STORAGE_UNAVAILABLE');
        return ['ok' => false, 'code' => 'ZNEWS_MEDIA_STORAGE_UNAVAILABLE', 'message' => 'Image storage is unavailable.', 'http_status' => 503];
    }

    if (!move_uploaded_file((string)$validated['tmp'], $target)) {
        znews_media_mark_request_failed($requestClaim, $shaClaim, 'ZNEWS_MEDIA_STORE_FAILED');
        return ['ok' => false, 'code' => 'ZNEWS_MEDIA_STORE_FAILED', 'message' => 'Image could not be stored.', 'http_status' => 503];
    }
    @chmod($target, 0640);

    $row = [
        'schema_version' => 1,
        'media_id' => $mediaId,
        'owner_uid' => $uid,
        'status' => 'STAGED',
        'mime' => (string)$validated['mime'],
        'extension' => (string)$validated['extension'],
        'size_bytes' => (int)$validated['size'],
        'width' => (int)$validated['width'],
        'height' => (int)$validated['height'],
        'sha256' => (string)$validated['sha256'],
        'dhash' => $dhash,
        'fingerprint_method' => $dhash !== '' ? 'DHASH_64_GD' : 'UNAVAILABLE',
        'duplicate_status' => $nearDuplicates ? 'NEAR_MATCH_REVIEW' : 'CLEAR',
        'near_duplicate_count' => count($nearDuplicates),
        'near_duplicate_matches' => $nearDuplicates,
        'copyright_status' => 'PENDING',
        'moderation_status' => $nearDuplicates ? 'REVIEW_REQUIRED' : 'PENDING',
        'storage_key' => $storageKey,
        'attached_post_id' => '',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => 0,
        'source' => 'ZPAY_API',
    ];

    $requestRow = (array)($requestClaim['claim'] ?? []);
    $requestRow['status'] = 'COMPLETED';
    $requestRow['completed_at'] = $now;
    $requestRow['updated_at'] = $now;
    $requestRow['lease_expires_at'] = 0;

    $shaRow = (array)($shaClaim['claim'] ?? []);
    $shaRow['status'] = 'COMPLETED';
    $shaRow['completed_at'] = $now;
    $shaRow['updated_at'] = $now;
    $shaRow['lease_expires_at'] = 0;

    $updates = [
        znews_media_path($mediaId) => $row,
        znews_media_owner_path($uid, $mediaId) => [
            'media_id' => $mediaId,
            'status' => 'STAGED',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        (string)$requestClaim['path'] => $requestRow,
        (string)$shaClaim['path'] => $shaRow,
    ];
    if ($dhash !== '') {
        $updates[znews_media_phash_bucket_path($dhash) . '/' . $mediaId] = [
            'media_id' => $mediaId,
            'dhash' => $dhash,
            'owner_uid_hash' => hash('sha256', $uid),
            'created_at' => $now,
        ];
    }

    if (!fb_patch('', $updates)) {
        @unlink($target);
        znews_media_mark_request_failed($requestClaim, $shaClaim, 'ZNEWS_MEDIA_RECORD_FAILED');
        return ['ok' => false, 'code' => 'ZNEWS_MEDIA_RECORD_FAILED', 'message' => 'Image record could not be saved.', 'http_status' => 503];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_MEDIA_UPLOADED', $mediaId, 'Z Sky 24 image uploaded', [
            'uid' => $uid,
            'media_id' => $mediaId,
            'mime' => $row['mime'],
            'size_bytes' => $row['size_bytes'],
            'duplicate_status' => $row['duplicate_status'],
        ]);
    }

    return ['ok' => true, 'idempotent_replay' => false, 'media' => znews_media_safe_payload($row)];
}

function znews_media_owned_record(array $auth, string $mediaId): array
{
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $row = fb_get(znews_media_path($mediaId));
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }
    $ownerUid = trim((string)($row['owner_uid'] ?? ''));
    if ($ownerUid === '' || !hash_equals($ownerUid, $uid)) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }
    if ((int)($row['deleted_at'] ?? 0) > 0 || strtoupper(trim((string)($row['status'] ?? ''))) === 'DELETED') {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }

    return $row;
}

function znews_media_public_record(string $mediaId): array
{
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $row = fb_get(znews_media_path($mediaId));
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }

    $postId = trim((string)($row['attached_post_id'] ?? ''));
    if ($postId === '' || strtoupper(trim((string)($row['status'] ?? ''))) !== 'ATTACHED') {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }
    $post = fb_get(znews_path_post($postId));
    if (!is_array($post)
        || strtoupper(trim((string)($post['status'] ?? ''))) !== 'ACTIVE'
        || strtoupper(trim((string)($post['visibility'] ?? ''))) !== 'PUBLIC'
        || (int)($post['deleted_at'] ?? 0) > 0
        || trim((string)($post['image_media_id'] ?? '')) !== $mediaId) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }

    return $row;
}

function znews_media_stream(array $row, bool $publicCache): void
{
    $mime = strtolower(trim((string)($row['mime'] ?? '')));
    if (!isset(znews_media_allowed_types()[$mime])) {
        api_response(false, 'ZNEWS_MEDIA_UNSUPPORTED', 'Image type is not supported.', [], 415);
    }

    try {
        $path = znews_media_resolve_path((string)($row['storage_key'] ?? ''));
    } catch (Throwable $e) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }
    if (!is_file($path) || !is_readable($path)) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }

    $size = filesize($path);
    header('Content-Type: ' . $mime);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    header('Content-Disposition: inline; filename="znews-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($row['media_id'] ?? 'image')) . '.' . (string)($row['extension'] ?? 'jpg') . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header($publicCache ? 'Cache-Control: public, max-age=3600, immutable' : 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    readfile($path);
    exit;
}
