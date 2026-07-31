<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cssPath = $root . '/znews/assets/znews-reader.css';
$swPath = $root . '/znews/sw.js';

$css = file_get_contents($cssPath);
$sw = file_get_contents($swPath);

if (!is_string($css) || !is_string($sw)) {
    fwrite(STDERR, "FAIL: Could not read Z News reader assets.\n");
    exit(1);
}

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(str_contains($css, '@media(max-width:780px){'), 'Mobile reader media rule is missing.');
$expect(str_contains($css, '.modal.post-modal{position:fixed'), 'Mobile override must match the higher-specificity global modal selector.');
$expect(str_contains($css, 'width:var(--znews-reader-vv-width,100%)'), 'Reader must use the measured visual viewport width.');
$expect(str_contains($css, 'left:var(--znews-reader-vv-left,0px)'), 'Reader must preserve the visual viewport horizontal offset.');
$expect(str_contains($css, 'max-width:none;max-inline-size:none'), 'Browser dialog maximum width must be reset.');
$expect(!str_contains($css, '@media(max-width:780px){\n  .post-modal{position:fixed'), 'Low-specificity mobile selector can reintroduce the right-side gap.');
$expect(str_contains($sw, "const SHELL_REVISION = 'profile-photo-origin-1'"), 'Service worker revision must refresh the profile-photo fix.');
$expect(str_contains($sw, "const CACHE_NAME = 'zsky24-embedded-shell-v3'"), 'Embedded shell cache namespace is incorrect.');

if ($failures) {
    fwrite(STDERR, "Z News reader full-width regression failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: Z News mobile reader fills the measured viewport width.\n";
