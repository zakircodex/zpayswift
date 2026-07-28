<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function znews_media_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function znews_media_read(string $path): string
{
    $source = file_get_contents($path);
    znews_media_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$files = [
    'api/znews/lib/media.php',
    'api/znews/media/upload.php',
    'api/znews/media/content.php',
    'api/znews/public/media.php',
    'api/admin/znews/media.php',
];

foreach ($files as $relative) {
    $source = znews_media_read($root . '/' . $relative);
    znews_media_expect(str_contains($source, 'declare(strict_types=1);'), "{$relative} missing strict types");
    znews_media_expect(
        !str_contains($source, 'lib/wallet.php')
        && !str_contains($source, 'USER_WALLETS/')
        && !str_contains($source, 'WALLET_LEDGER/'),
        "{$relative} touches wallet business logic"
    );
}

$media = znews_media_read($root . '/api/znews/lib/media.php');
znews_media_expect(str_contains($media, "'image/jpeg' => 'jpg'"), 'JPEG validation missing');
znews_media_expect(str_contains($media, "'image/png' => 'png'"), 'PNG validation missing');
znews_media_expect(str_contains($media, "'image/webp' => 'webp'"), 'WebP validation missing');
znews_media_expect(str_contains($media, "hash_file('sha256'"), 'SHA-256 hashing missing');
znews_media_expect(str_contains($media, 'znews_media_dhash'), 'perceptual hashing missing');
znews_media_expect(str_contains($media, 'znews_media_hamming_hex'), 'near-duplicate distance check missing');
znews_media_expect(str_contains($media, 'ZNEWS_MEDIA_HASHES/SHA256/'), 'exact duplicate index missing');
znews_media_expect(str_contains($media, 'ZNEWS_MEDIA_HASHES/DHASH_BUCKETS/'), 'perceptual duplicate index missing');
znews_media_expect(str_contains($media, 'fb_put_if_match'), 'concurrent duplicate claim missing');
znews_media_expect(str_contains($media, 'ZNEWS_MEDIA_IDEMPOTENCY/'), 'media idempotency missing');
znews_media_expect(str_contains($media, "'status' => 'STAGED'"), 'uploaded media is not staged');
znews_media_expect(str_contains($media, "'copyright_status' => 'PENDING'"), 'copyright review state missing');
znews_media_expect(str_contains($media, 'app_private_config_path'), 'media is not stored under private application storage');
znews_media_expect(str_contains($media, 'move_uploaded_file'), 'secure upload move missing');
znews_media_expect(str_contains($media, 'is_uploaded_file'), 'PHP upload verification missing');
znews_media_expect(str_contains($media, 'Content-Security-Policy'), 'media response CSP missing');
znews_media_expect(str_contains($media, "!== 'ATTACHED'"), 'public media is not restricted to attached records');
znews_media_expect(str_contains($media, "!== 'ACTIVE'"), 'public media is not restricted to active posts');
znews_media_expect(str_contains($media, "!== 'PUBLIC'"), 'public media is not restricted to public posts');
znews_media_expect(str_contains($media, 'hash_equals($ownerUid, $uid)'), 'media ownership check is not timing-safe');

$upload = znews_media_read($root . '/api/znews/media/upload.php');
znews_media_expect(str_contains($upload, "api_require_method('POST')"), 'upload is not POST-only');
znews_media_expect(str_contains($upload, 'api_require_app_key();'), 'upload lacks app key protection');
znews_media_expect(str_contains($upload, 'znews_require_creator(true)'), 'upload lacks creator authentication');
znews_media_expect(str_contains($upload, 'znews_idempotency_key'), 'upload lacks idempotency requirement');

$privateContent = znews_media_read($root . '/api/znews/media/content.php');
znews_media_expect(str_contains($privateContent, 'api_require_app_key();'), 'private media lacks app key protection');
znews_media_expect(str_contains($privateContent, 'znews_media_owned_record'), 'private media lacks ownership check');

$publicContent = znews_media_read($root . '/api/znews/public/media.php');
znews_media_expect(!str_contains($publicContent, 'api_require_app_key();'), 'public approved media incorrectly requires app key');
znews_media_expect(str_contains($publicContent, 'znews_media_public_record'), 'public media eligibility check missing');
znews_media_expect(
    str_contains($publicContent, "\$_SERVER['SCRIPT_FILENAME'] = __FILE__ . '.entrypoint';"),
    'public media endpoint does not neutralize the media.php basename collision'
);
znews_media_expect(
    str_contains($publicContent, "\$_SERVER['SCRIPT_FILENAME'] = \$znewsOriginalScriptFilename;"),
    'public media endpoint does not restore SCRIPT_FILENAME'
);

$adminContent = znews_media_read($root . '/api/admin/znews/media.php');
znews_media_expect(str_contains($adminContent, 'auth_require_admin_session(true)'), 'admin media lacks admin authentication');
znews_media_expect(
    str_contains($adminContent, "\$_SERVER['SCRIPT_FILENAME'] = __FILE__ . '.entrypoint';"),
    'admin media endpoint does not neutralize the media.php basename collision'
);
znews_media_expect(
    str_contains($adminContent, "\$_SERVER['SCRIPT_FILENAME'] = \$znewsOriginalScriptFilename;"),
    'admin media endpoint does not restore SCRIPT_FILENAME'
);

echo "Z News media backend tests passed ({$assertions} assertions).\n";
