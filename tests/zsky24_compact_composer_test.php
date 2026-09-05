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
$richEditor = composer_source('znews/assets/znews-rich-editor.js');
$premium = composer_source('znews/assets/znews-premium.css');
$bootstrap = composer_source('znews/assets/znews-bootstrap.js');
$embeddedWorker = composer_source('znews/sw.js');
$standaloneWorker = composer_source('znews/sw-root.js');

composer_expect(str_contains($index, 'class="page-card card composer-card"'), 'Compact composer card is missing.');
composer_expect(str_contains($index, 'class="composer-topbar"'), 'Compact composer top bar is missing.');
composer_expect(str_contains($index, 'id="createComposerAvatar"'), 'Creator identity is missing from the composer.');
composer_expect(str_contains($index, 'id="createComposerName"'), 'Creator name is missing from the composer.');
composer_expect(str_contains($index, 'aria-label="Post audience: Public"'), 'Public audience context is missing.');
composer_expect(str_contains($index, 'id="postText" contenteditable="true"') && !str_contains($index, '<textarea id="postText"'), 'Create must use one native contenteditable surface without a textarea proxy.');
composer_expect(str_contains($index, 'class="composer-add-row"'), 'Compact media action row is missing.');
composer_expect(str_contains($index, 'id="createPostSubmit" type="submit" disabled'), 'Post action must start disabled.');
composer_expect(str_contains($index, 'id="createPostSubmitBottom" type="submit" disabled'), 'Mobile bottom Post action is missing.');
composer_expect(str_contains($index, '>Add photo</strong>'), 'Single-photo composer label is missing.');
composer_expect(!str_contains($index, 'Photos/videos'), 'Video wording must not appear in the photo-only composer.');
composer_expect(!str_contains($index, '<span class="eyebrow">Creator</span><h1>Create a post</h1>'), 'Legacy oversized heading remains.');
composer_expect(!str_contains($index, 'class="upload-box" for="postImage"'), 'Legacy oversized upload box remains.');

composer_expect(str_contains($app, 'function syncComposerState()'), 'Composer state synchronizer is missing.');
composer_expect(str_contains($index, 'id="postBoldButton"'), 'Composer middle-bold control is missing.');
composer_expect(str_contains($app, 'getEditorPayload') && str_contains($app, 'formattingRuns: parsedText.formattingRuns'), 'Composer does not send canonical plain text with safe formatting runs.');
composer_expect(str_contains($richEditor, 'toggleBold') && !str_contains($richEditor, 'textarea.value = `${value.slice(0, start)}**'), 'Composer still exposes markdown formatting markers.');
composer_expect(str_contains($richEditor, 'function isEditorElement(editor)') && str_contains($richEditor, "editor.addEventListener('compositionstart'") && str_contains($richEditor, "editor.addEventListener('compositionend'") && !str_contains($richEditor, 'ensureVisualSurface'), 'Selected formatting is not using the single composition-safe native editor.');
composer_expect(str_contains($richEditor, "boldMixed ? 'mixed'") && str_contains($richEditor, "colorLabel = selected.colorMixed ? 'Mixed'"), 'Caret/mixed formatting toolbar state is missing.');
composer_expect(str_contains($richEditor, 'beginProgress') && str_contains($richEditor, 'setButtonLoading'), 'Shared Z Sky progress/button feedback is missing.');
composer_expect(str_contains($premium, 'min-height:156px;max-height:300px'), 'Stable native editor height bounds are missing.');
composer_expect(str_contains($app, 'els.postTitle.value.trim()'), 'Headline-aware Post action is missing.');
composer_expect(str_contains($app, 'document.documentElement.dataset.znewsRoute = next'), 'Route-aware mobile composer mode is missing.');
composer_expect(str_contains($app, "remove.setAttribute('aria-label', 'Remove selected photo')"), 'Selected-photo removal is missing.');
composer_expect(str_contains($app, 'setAvatar(els.createComposerAvatar'), 'Authenticated profile photo is not connected to the composer.');
composer_expect(str_contains($app, 'syncComposerState();'), 'Create submit states are not restored safely after a request.');
composer_expect(str_contains($creator, 'syncEditor(ensureEditor())'), 'Edit submit states are not restored safely after a request.');

foreach ([
    '.composer-card{max-width:680px',
    '.rich-editor-editable{box-sizing:border-box;display:block',
    '.znews-top-progress{position:fixed',
    '.znews-button-loading::before',
    '.composer-add-row{display:grid',
    '.composer-image-remove{position:absolute',
    'html[data-znews-route="create"] .app-header{display:none}',
    '#createView .composer-card{width:100%;min-height:100dvh',
    '.composer-bottom-action{display:block',
] as $contract) {
    composer_expect(str_contains($premium, $contract), "Compact composer style is missing: {$contract}");
}

composer_expect(str_contains($index, 'znews-premium.css?v=17'), 'Composer stylesheet cachebuster is missing.');
composer_expect(str_contains($index, 'znews-bootstrap.js?v=36'), 'Reload-safe composer bootstrap cachebuster is missing.');
composer_expect(str_contains($index, 'znews.js?v=29'), 'Latest composer behavior is not loaded.');
composer_expect(str_contains($index, 'znews-rich-editor.js?v=5'), 'Single-surface rich editor module is not loaded.');
composer_expect(str_contains($bootstrap, 'znews-creator.js?v=14'), 'Latest creator behavior is not loaded.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    composer_expect(str_contains($worker, 'znews-premium.css?v=17'), 'Latest composer stylesheet is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews-bootstrap.js?v=36'), 'Latest reload-safe bootstrap is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews.js?v=29'), 'Latest app behavior is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews-rich-editor.js?v=5'), 'Single-surface rich editor is missing from a PWA shell.');
    composer_expect(str_contains($worker, 'znews-creator.js?v=14'), 'Latest creator behavior is missing from a PWA shell.');
    composer_expect(str_contains($worker, "SHELL_REVISION = 'adsterra-reader-v1'"), 'Adsterra cache revision is missing from a PWA shell.');
    composer_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'PWA shell must continue excluding API responses.');
    composer_expect(str_contains($worker, 'networkFirst(request'), 'PWA shell must refresh composer assets while online.');
}

fwrite(STDOUT, "Z Sky 24 compact composer audit passed.\n");
