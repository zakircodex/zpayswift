<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function composer_source(string $relative): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relative);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$relative}");
    }
    return $contents;
}

function composer_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$index = composer_source('znews/index.html');
$app = composer_source('znews/assets/znews.js');
$creator = composer_source('znews/assets/znews-creator.js');
$premium = composer_source('znews/assets/znews-premium.css');
$bootstrap = composer_source('znews/assets/znews-bootstrap.js');
$embeddedWorker = composer_source('znews/sw.js');
$standaloneWorker = composer_source('znews/sw-root.js');

composer_expect(str_contains($index, 'class="page-card card composer-card"'), 'Compact composer card is missing.');
composer_expect(str_contains($index, 'class="composer-topbar"'), 'Compact composer top bar is missing.');
composer_expect(str_contains($index, 'id="createComposerAvatar"'), 'Creator identity is missing from the composer.');
composer_expect(str_contains($index, 'id="createComposerName"'), 'Creator name is missing from the composer.');
composer_expect(str_contains($index, 'aria-label="Post audience: Public"'), 'Public audience context is missing.');
composer_expect(str_contains($index, 'rows="3"'), 'Composer text area must start compact.');
composer_expect(str_contains($index, 'class="composer-add-row"'), 'Compact media action row is missing.');
composer_expect(str_contains($index, 'id="createPostSubmit" type="submit" disabled'), 'Post action must start disabled.');
composer_expect(!str_contains($index, '<span class="eyebrow">Creator</span><h1>Create a post</h1>'), 'Legacy oversized heading remains.');
composer_expect(!str_contains($index, 'class="upload-box" for="postImage"'), 'Legacy oversized upload box remains.');

composer_expect(str_contains($app, 'function syncComposerState()'), 'Composer state synchronizer is missing.');
composer_expect(str_contains($app, 'Math.min(240, Math.max(96, els.postText.scrollHeight))'), 'Auto-growing composer bounds are missing.');
composer_expect(str_contains($app, "els.createPostSubmit.disabled = !els.postText.value.trim() && !els.postImage.files?.[0]"), 'Content-aware Post action is missing.');
composer_expect(str_contains($app, "remove.setAttribute('aria-label', 'Remove selected photo')"), 'Selected-photo removal is missing.');
composer_expect(str_contains($app, 'setAvatar(els.createComposerAvatar'), 'Authenticated profile photo is not connected to the composer.');
composer_expect(str_contains($creator, 'submit.disabled = !currentText && !currentFile'), 'Submit state is not restored safely after a request.');

foreach ([
    '.composer-card{max-width:680px',
    '.composer-form>textarea{width:100%;min-height:96px;max-height:240px',
    '.composer-add-row{min-height:64px',
    '.composer-image-remove{position:absolute',
] as $contract) {
    composer_expect(str_contains($premium, $contract), "Compact composer style is missing: {$contract}");
}

composer_expect(str_contains($index, 'znews-premium.css?v=5'), 'Composer stylesheet cachebuster is missing.');
composer_expect(str_contains($index, 'znews-bootstrap.js?v=8'), 'Composer bootstrap cachebuster is missing.');
composer_expect(str_contains($bootstrap, 'znews.js?v=5'), 'Latest composer behavior is not loaded.');
composer_expect(str_contains($bootstrap, 'znews-creator.js?v=3'), 'Latest creator behavior is not loaded.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    composer_expect(str_contains($worker, 'znews-premium.css?v=5'), 'Latest composer stylesheet is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews-bootstrap.js?v=8'), 'Latest bootstrap is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews.js?v=5'), 'Latest app behavior is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews-creator.js?v=3'), 'Latest creator behavior is missing from a PWA shell.');
    composer_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'PWA shell must continue excluding API responses.');
}

fwrite(STDOUT, "Z Sky 24 compact composer audit passed.\n");
