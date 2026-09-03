<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_media_optimized_target_bytes(): int
{
    return 500 * 1024;
}

function znews_media_optimized_max_bytes(): int
{
    return 700 * 1024;
}

function znews_media_optimized_max_edge(): int
{
    return 1600;
}

function znews_media_optimized_storage_key(string $mediaId, string $extension, ?int $now = null): string
{
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $extension = strtolower(trim($extension));
    if (!in_array($extension, array_values(znews_media_allowed_types()), true)) {
        throw new RuntimeException('Invalid optimized image extension.');
    }
    $now = $now ?? znews_now();
    return date('Y/m', $now) . '/znews_' . strtolower($mediaId) . '_web.' . $extension;
}

function znews_media_jpeg_orientation(string $path, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return 1;
    }
    try {
        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = is_array($exif)
            ? (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1)
            : 1;
        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    } catch (Throwable $e) {
        return 1;
    }
}

function znews_media_orient_image($source, int $orientation)
{
    if (!$source || $orientation === 1) {
        return $source;
    }

    $oriented = $source;
    if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
        $mode = in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
        imageflip($oriented, $mode);
    }
    $angle = match ($orientation) {
        3, 4 => 180,
        5, 6 => -90,
        7, 8 => 90,
        default => 0,
    };
    if ($angle !== 0) {
        $rotated = imagerotate($oriented, $angle, imagecolorallocatealpha($oriented, 0, 0, 0, 127));
        if ($rotated) {
            imagesavealpha($rotated, true);
            if ($oriented !== $source) {
                imagedestroy($oriented);
            }
            $oriented = $rotated;
        }
    }
    return $oriented;
}

function znews_media_resample($source, int $width, int $height)
{
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        return null;
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparent);
    if (!imagecopyresampled(
        $canvas,
        $source,
        0,
        0,
        0,
        0,
        $width,
        $height,
        imagesx($source),
        imagesy($source)
    )) {
        imagedestroy($canvas);
        return null;
    }
    return $canvas;
}

function znews_media_encode_candidate($image, string $mime, int $quality, string $path): bool
{
    return match ($mime) {
        'image/webp' => function_exists('imagewebp') && @imagewebp($image, $path, $quality),
        'image/jpeg' => function_exists('imagejpeg') && @imagejpeg($image, $path, $quality),
        'image/png' => function_exists('imagepng') && @imagepng($image, $path, 9),
        default => false,
    };
}

function znews_media_optimize_file(string $sourcePath, string $mime): array
{
    $info = @getimagesize($sourcePath);
    $sourceWidth = is_array($info) ? max(0, (int)($info[0] ?? 0)) : 0;
    $sourceHeight = is_array($info) ? max(0, (int)($info[1] ?? 0)) : 0;
    $sourceBytes = is_file($sourcePath) ? max(0, (int)filesize($sourcePath)) : 0;
    if ($sourceWidth <= 0 || $sourceHeight <= 0 || $sourceBytes <= 0) {
        return ['ok' => false, 'code' => 'ZNEWS_MEDIA_OPTIMIZATION_INVALID_SOURCE'];
    }

    $source = znews_media_gd_image($sourcePath, $mime);
    if (!$source) {
        if ($sourceBytes > znews_media_optimized_max_bytes()
            || max($sourceWidth, $sourceHeight) > znews_media_optimized_max_edge()) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_OPTIMIZATION_UNAVAILABLE'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'zsky_opt_');
        if (!is_string($tmp) || !copy($sourcePath, $tmp)) {
            return ['ok' => false, 'code' => 'ZNEWS_MEDIA_OPTIMIZATION_WRITE_FAILED'];
        }
        return [
            'ok' => true,
            'tmp' => $tmp,
            'mime' => $mime,
            'extension' => znews_media_allowed_types()[$mime],
            'size_bytes' => $sourceBytes,
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'sha256' => hash_file('sha256', $tmp),
        ];
    }

    $oriented = znews_media_orient_image($source, znews_media_jpeg_orientation($sourcePath, $mime));
    $orientedWidth = imagesx($oriented);
    $orientedHeight = imagesy($oriented);
    $outputMime = function_exists('imagewebp') ? 'image/webp' : ($mime === 'image/webp' ? 'image/jpeg' : $mime);
    $extension = znews_media_allowed_types()[$outputMime];
    $edges = [1600, 1440, 1280, 1120, 960, 800];
    $qualities = $outputMime === 'image/png' ? [100] : [82, 76, 70, 64, 58];
    $best = null;
    $targetReached = false;

    foreach ($edges as $edge) {
        $scale = min(1.0, $edge / max($orientedWidth, $orientedHeight));
        $width = max(1, (int)round($orientedWidth * $scale));
        $height = max(1, (int)round($orientedHeight * $scale));
        $canvas = znews_media_resample($oriented, $width, $height);
        if (!$canvas) {
            continue;
        }
        foreach ($qualities as $quality) {
            $tmp = tempnam(sys_get_temp_dir(), 'zsky_opt_');
            if (!is_string($tmp) || !znews_media_encode_candidate($canvas, $outputMime, $quality, $tmp)) {
                if (is_string($tmp)) {
                    @unlink($tmp);
                }
                continue;
            }
            $bytes = max(0, (int)filesize($tmp));
            if ($bytes > 0 && ($best === null || $bytes < (int)$best['size_bytes'])) {
                if (is_array($best)) {
                    @unlink((string)$best['tmp']);
                }
                $best = [
                    'ok' => true,
                    'tmp' => $tmp,
                    'mime' => $outputMime,
                    'extension' => $extension,
                    'size_bytes' => $bytes,
                    'width' => $width,
                    'height' => $height,
                    'sha256' => hash_file('sha256', $tmp),
                ];
            } else {
                @unlink($tmp);
            }
            if ($bytes > 0 && $bytes <= znews_media_optimized_target_bytes()) {
                $targetReached = true;
                break;
            }
        }
        imagedestroy($canvas);
        if ($targetReached) {
            break;
        }
    }

    if ($oriented !== $source) {
        imagedestroy($oriented);
    }
    imagedestroy($source);
    if (!is_array($best) || (int)$best['size_bytes'] > znews_media_optimized_max_bytes()) {
        if (is_array($best)) {
            @unlink((string)$best['tmp']);
        }
        return ['ok' => false, 'code' => 'ZNEWS_MEDIA_OPTIMIZATION_TOO_LARGE'];
    }
    return $best;
}

function znews_media_store_optimized(string $mediaId, array $optimized, ?int $now = null): array
{
    if (empty($optimized['ok']) || !is_file((string)($optimized['tmp'] ?? ''))) {
        throw new RuntimeException('Optimized image is unavailable.');
    }
    $storageKey = znews_media_optimized_storage_key($mediaId, (string)$optimized['extension'], $now);
    $targetDir = znews_media_ensure_storage_dir(dirname($storageKey));
    $target = $targetDir . DIRECTORY_SEPARATOR . basename($storageKey);
    if (!@rename((string)$optimized['tmp'], $target)) {
        if (!copy((string)$optimized['tmp'], $target)) {
            throw new RuntimeException('Optimized image could not be stored.');
        }
        @unlink((string)$optimized['tmp']);
    }
    @chmod($target, 0640);
    return [
        'optimized_storage_key' => $storageKey,
        'optimized_mime' => (string)$optimized['mime'],
        'optimized_extension' => (string)$optimized['extension'],
        'optimized_size_bytes' => max(0, (int)$optimized['size_bytes']),
        'optimized_width' => max(0, (int)$optimized['width']),
        'optimized_height' => max(0, (int)$optimized['height']),
        'optimized_sha256' => strtolower((string)$optimized['sha256']),
        'optimized_at' => $now ?? znews_now(),
        'optimization_version' => 'WEB_DERIVATIVE_V1',
    ];
}
