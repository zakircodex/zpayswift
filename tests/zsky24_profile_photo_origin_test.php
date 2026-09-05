<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$read = static function (string $relative) use ($root): string {
    $value = file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
};
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$config = $read('znews/assets/znews-config.js');
$expect(str_contains($config, "const zpayOrigin = 'https://zpayswift.com'"), 'Trusted Z-Pay profile-photo origin is missing.');
$expect(str_contains($config, "raw.startsWith('/uploads/profile/photos/')"), 'Legacy relative profile-photo detection is missing.');
$expect(str_contains($config, 'resolveProfilePhotoUrl'), 'Shared profile-photo resolver is missing.');
$index = $read('znews/index.html');
$app = $read('znews/assets/znews.js');
$bootstrap = $read('znews/assets/znews-bootstrap.js');
$rootRewrite = $read('.htaccess');
$expect(str_contains($index, 'znews-config.js?v=9'), 'Profile-photo config cachebuster is missing.');
$expect(str_contains($index, 'znews-bootstrap.js?v=34'), 'Reload-safe bootstrap cachebuster is missing.');
$expect(str_contains($bootstrap, "updateViaCache: 'none'"), 'Early service-worker update may reuse a stale HTTP cache.');
$expect(str_contains($bootstrap, 'await registration.update()'), 'Service-worker update is not requested during bootstrap.');
$expect(str_contains($app, "updateViaCache: 'none'"), 'Late service-worker fallback may reuse a stale HTTP cache.');
$expect(str_contains($rootRewrite, 'no-cache, no-store, must-revalidate'), 'Service-worker no-cache response policy is missing.');

foreach ([
    'znews/assets/znews.js',
    'znews/assets/znews-reader.js',
    'znews/assets/znews-profile.js',
    'znews/assets/znews-instant-comments.js',
] as $relative) {
    $source = $read($relative);
    $expect(
        str_contains($source, 'config.resolveProfilePhotoUrl(raw)'),
        "{$relative} does not use the trusted profile-photo origin resolver."
    );
}

$expect(
    !str_contains($config, "base = raw.startsWith('/uploads/profile/photos/') ? window.location.origin"),
    'Profile photos may still resolve against the standalone host.'
);

if ($failures) {
    fwrite(STDERR, "Z Sky 24 profile-photo origin contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Z Sky 24 profile-photo origin contract passed.\n";
