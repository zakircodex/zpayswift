<?php
declare(strict_types=1);

$assertions = 0;
$firebase = [];
$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zsky_birthday_media_' . bin2hex(random_bytes(6));
define('BIRTHDAY_UNIVERSE_STORAGE_DIR', $storageRoot);

function api_response(bool $ok, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . ': ' . $message, $status);
}

function birthday_media_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('Birthday media fallback test failed: ' . $message);
    }
}

function birthday_now(): int
{
    return 1790000000;
}

function birthday_settings(): array
{
    return ['draft_ttl_seconds' => 86400];
}

function birthday_path(string $node, string $id = ''): string
{
    $path = 'TEST_' . strtoupper($node);
    return $id === '' ? $path : $path . '/' . $id;
}

function fb_patch(string $path, array $data): bool
{
    global $firebase;
    if ($path !== '') {
        return false;
    }
    foreach ($data as $key => $value) {
        $firebase[(string)$key] = $value;
    }
    return true;
}

function fb_get(string $path, array $query = []): mixed
{
    global $firebase;
    return $firebase[$path] ?? null;
}

function fb_delete(string $path): bool
{
    global $firebase;
    unset($firebase[$path]);
    return true;
}

require_once dirname(__DIR__) . '/api/znews/lib/common.php';
require_once dirname(__DIR__) . '/api/znews/lib/birthday_media.php';

$content = random_bytes(128 * 1024);
$sha256 = hash('sha256', $content);
$media = [
    'id' => 'ZBM_TEST_FALLBACK',
    'mime' => 'image/webp',
    'size_bytes' => strlen($content),
    'sha256' => $sha256,
];
$blob = birthday_media_blob_encode('ZBM_TEST_FALLBACK', 'image/webp', $content, $sha256, 1790000000);
birthday_media_test_expect(birthday_media_blob_decode($blob, $media) === $content, 'A verified fallback photo must round-trip without data loss.');

$tampered = $blob;
$tampered['content_b64'][20] = $tampered['content_b64'][20] === 'A' ? 'B' : 'A';
birthday_media_test_expect(birthday_media_blob_decode($tampered, $media) === null, 'Tampered fallback photo data must be rejected.');

$wrongMime = $blob;
$wrongMime['mime'] = 'image/png';
birthday_media_test_expect(birthday_media_blob_decode($wrongMime, $media) === null, 'Fallback photo MIME must match its protected metadata.');

$oversized = str_repeat('a', znews_media_optimized_max_bytes() + 1);
try {
    birthday_media_blob_encode('ZBM_TOO_LARGE', 'image/webp', $oversized, hash('sha256', $oversized), 1790000000);
    birthday_media_test_expect(false, 'Oversized fallback photo data must be rejected.');
} catch (RuntimeException $error) {
    birthday_media_test_expect(true, 'Oversized fallback photo data was rejected.');
}

$sourceAsset = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zsky_birthday_source_' . bin2hex(random_bytes(6)) . '.png';
$fixture = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2tT8AAAAASUVORK5CYII=', true);
if (!is_string($fixture) || file_put_contents($sourceAsset, $fixture) === false) {
    throw new RuntimeException('Birthday media test fixture could not be created.');
}
$stored = birthday_photo_store(['tmp' => $sourceAsset, 'mime' => 'image/png'], 'DRAFT', 'ZBD_TEST_DRAFT');
$storedBlob = fb_get(birthday_path('MEDIA_BLOBS', (string)$stored['id']));
birthday_media_test_expect(is_array($storedBlob), 'Photo storage must write its protected fallback copy atomically.');
birthday_media_test_expect(birthday_media_blob_decode($storedBlob, $stored) !== null, 'Stored fallback copy must pass integrity verification.');
$storedPath = birthday_media_resolve((string)$stored['storage_key']);
birthday_media_test_expect(is_file($storedPath), 'Optimized filesystem photo must still be written as the primary copy.');
birthday_media_delete_files($stored);
birthday_media_test_expect(!is_file($storedPath), 'Photo cleanup must remove the filesystem copy.');
birthday_media_test_expect(fb_get(birthday_path('MEDIA_BLOBS', (string)$stored['id'])) === null, 'Photo cleanup must remove the fallback copy.');
@unlink($sourceAsset);

if (is_dir($storageRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($storageRoot);
}

echo "Z Sky 24 Birthday media fallback tests passed ({$assertions} assertions).\n";
