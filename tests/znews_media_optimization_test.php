<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zsky-media-test-' . bin2hex(random_bytes(5));
define('ZNEWS_MEDIA_STORAGE_DIR', $storageRoot);

$assertions = 0;
$mediaRows = [];
$patchPaths = [];

function media_opt_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_now(): int
{
    return 1788451200;
}

function znews_firebase_key($value, string $field = 'id', int $maxLength = 160): string
{
    $key = trim((string)$value);
    if ($key === '' || strlen($key) > $maxLength || preg_match('/[.#$\[\]\/]/', $key) === 1) {
        throw new RuntimeException('Invalid fixture key: ' . $field);
    }
    return $key;
}

function api_response(bool $success, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . ':' . $status . ':' . $message);
}

function fb_get(string $path, array $query = []): mixed
{
    if ($path !== 'ZNEWS_MEDIA') {
        throw new RuntimeException('Unexpected Firebase read: ' . $path);
    }
    media_opt_expect(($query['orderBy'] ?? '') === json_encode('$key'), 'Backfill must paginate by key.');
    media_opt_expect((int)($query['limitToFirst'] ?? 0) <= 26, 'Backfill batch is not bounded.');
    return $GLOBALS['mediaRows'];
}

function fb_patch(string $path, array $data): bool
{
    $GLOBALS['patchPaths'][] = $path;
    media_opt_expect(str_starts_with($path, 'ZNEWS_MEDIA/'), 'Backfill attempted to patch a non-media root.');
    $mediaId = substr($path, strlen('ZNEWS_MEDIA/'));
    $GLOBALS['mediaRows'][$mediaId] = array_merge($GLOBALS['mediaRows'][$mediaId] ?? [], $data);
    return true;
}

function media_opt_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        media_opt_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function media_opt_fixture(string $path, int $width, int $height, string $format, int $targetBytes = 0): void
{
    $image = imagecreatetruecolor($width, $height);
    media_opt_expect($image !== false, 'Could not create image fixture.');
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 20, 90, 160, $format === 'png' || $format === 'webp' ? 50 : 0);
    imagefill($image, 0, 0, $transparent);
    imagealphablending($image, true);
    for ($y = 0; $y < $height; $y += 48) {
        for ($x = 0; $x < $width; $x += 48) {
            $color = imagecolorallocatealpha(
                $image,
                ($x * 7 + $y * 3) % 256,
                ($x * 5 + $y * 11) % 256,
                ($x * 13 + $y * 2) % 256,
                ($format === 'png' || $format === 'webp') ? (($x + $y) % 70) : 0
            );
            imagefilledrectangle($image, $x, $y, min($width - 1, $x + 47), min($height - 1, $y + 47), $color);
        }
    }
    $written = match ($format) {
        'jpeg' => imagejpeg($image, $path, 92),
        'png' => imagepng($image, $path, 3),
        'webp' => function_exists('imagewebp') && imagewebp($image, $path, 90),
        default => false,
    };
    imagedestroy($image);
    media_opt_expect($written && is_file($path), 'Could not encode image fixture: ' . $format);
    clearstatcache(true, $path);
    $size = (int)filesize($path);
    if ($targetBytes > $size) {
        file_put_contents($path, str_repeat("\0", $targetBytes - $size), FILE_APPEND);
    }
    clearstatcache(true, $path);
}

require_once $root . '/api/znews/lib/media.php';
require_once $root . '/api/znews/lib/media_derivative_backfill.php';

media_opt_expect(extension_loaded('gd'), 'GD is required for media optimization tests.');

$fixtureDir = $storageRoot . DIRECTORY_SEPARATOR . 'fixtures';
mkdir($fixtureDir, 0750, true);
$samples = [];
foreach ([1, 3, 5, 8] as $megabytes) {
    $source = $fixtureDir . DIRECTORY_SEPARATOR . "sample-{$megabytes}mb.jpg";
    media_opt_fixture($source, 2400, 1600, 'jpeg', $megabytes * 1024 * 1024);
    $result = znews_media_optimize_file($source, 'image/jpeg');
    media_opt_expect(!empty($result['ok']), "{$megabytes} MB JPEG optimization failed.");
    media_opt_expect((int)$result['size_bytes'] <= 700 * 1024, "{$megabytes} MB JPEG exceeds 700 KB.");
    media_opt_expect(max((int)$result['width'], (int)$result['height']) <= 1600, 'Optimized image exceeds 1600px.');
    media_opt_expect(abs(((int)$result['width'] / (int)$result['height']) - 1.5) < 0.02, 'Landscape aspect ratio changed.');
    $samples[$megabytes . 'MB'] = [
        'original_bytes' => (int)filesize($source),
        'final_bytes' => (int)$result['size_bytes'],
        'width' => (int)$result['width'],
        'height' => (int)$result['height'],
        'compression_percent' => round((1 - ((int)$result['size_bytes'] / (int)filesize($source))) * 100, 1),
    ];
    @unlink((string)$result['tmp']);
}

foreach ([
    ['portrait', 900, 1500, 'png', 'image/png'],
    ['square', 1200, 1200, 'webp', 'image/webp'],
] as [$name, $width, $height, $format, $mime]) {
    if ($format === 'webp' && !function_exists('imagewebp')) {
        continue;
    }
    $source = $fixtureDir . DIRECTORY_SEPARATOR . $name . '.' . $format;
    media_opt_fixture($source, $width, $height, $format);
    $result = znews_media_optimize_file($source, $mime);
    media_opt_expect(!empty($result['ok']), strtoupper($format) . ' optimization failed.');
    media_opt_expect((int)$result['size_bytes'] <= 700 * 1024, strtoupper($format) . ' exceeds 700 KB.');
    media_opt_expect(abs(((int)$result['width'] / (int)$result['height']) - ($width / $height)) < 0.02, ucfirst($name) . ' aspect ratio changed.');
    @unlink((string)$result['tmp']);
}

$sourceKey = '2026/09/znews_zmtest001.jpg';
$sourceDir = znews_media_ensure_storage_dir('2026/09');
$sourcePath = $sourceDir . DIRECTORY_SEPARATOR . 'znews_zmtest001.jpg';
copy($fixtureDir . DIRECTORY_SEPARATOR . 'sample-3mb.jpg', $sourcePath);
$originalHash = hash_file('sha256', $sourcePath);
$mediaRows = [
    'ZMTEST001' => [
        'media_id' => 'ZMTEST001',
        'status' => 'ATTACHED',
        'storage_key' => $sourceKey,
        'mime' => 'image/jpeg',
        'size_bytes' => (int)filesize($sourcePath),
        'width' => 2400,
        'height' => 1600,
        'sha256' => $originalHash,
        'deleted_at' => 0,
    ],
];

$dryRun = znews_media_derivative_backfill_run(['dry_run' => true, 'limit' => 10]);
media_opt_expect(!empty($dryRun['ok']) && (int)$dryRun['would_update'] === 1, 'Dry-run did not report one derivative update.');
media_opt_expect($patchPaths === [], 'Dry-run performed a Firebase mutation.');
$apply = znews_media_derivative_backfill_run(['dry_run' => false, 'limit' => 10]);
media_opt_expect(!empty($apply['ok']) && (int)$apply['would_update'] === 1, 'Backfill apply did not update one derivative.');
media_opt_expect($patchPaths === ['ZNEWS_MEDIA/ZMTEST001'], 'Backfill mutated an unexpected Firebase path.');
media_opt_expect(hash_file('sha256', $sourcePath) === $originalHash, 'Backfill changed the canonical original.');
media_opt_expect(znews_media_derivative_is_current($mediaRows['ZMTEST001']), 'Derivative metadata/file is not current after apply.');
$secondDryRun = znews_media_derivative_backfill_run(['dry_run' => true, 'limit' => 10]);
media_opt_expect((int)$secondDryRun['would_update'] === 0 && (int)$secondDryRun['unchanged'] === 1, 'Backfill is not idempotent.');

$mediaSource = file_get_contents($root . '/api/znews/lib/media.php');
$optimizerSource = file_get_contents($root . '/api/znews/lib/media_optimizer.php');
$toolSource = file_get_contents($root . '/api/tools/backfill_znews_media_derivatives.php');
media_opt_expect(str_contains((string)$mediaSource, "header('Content-Length: '"), 'Protected media lacks Content-Length.');
media_opt_expect(str_contains((string)$mediaSource, "header('ETag: '"), 'Protected media lacks ETag.');
media_opt_expect(str_contains((string)$mediaSource, 'HTTP_IF_NONE_MATCH'), 'Protected media lacks conditional 304 handling.');
media_opt_expect(str_contains((string)$mediaSource, 'Cache-Control: private, max-age=86400'), 'Protected media lacks browser-private caching.');
media_opt_expect(str_contains((string)$optimizerSource, 'znews_media_jpeg_orientation'), 'Server EXIF orientation handling is missing.');
media_opt_expect(str_contains((string)$toolSource, "PHP_SAPI !== 'cli'"), 'Derivative backfill is not CLI-only.');

echo 'PASS: ' . $assertions . ' media optimization assertions; samples=' . json_encode($samples, JSON_UNESCAPED_SLASHES) . ".\n";
media_opt_remove_tree($storageRoot);

