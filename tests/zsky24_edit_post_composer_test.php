<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$creator = file_get_contents($root . '/znews/assets/znews-creator.js');
$premium = file_get_contents($root . '/znews/assets/znews-premium.css');
$bootstrap = file_get_contents($root . '/znews/assets/znews-bootstrap.js');
$index = file_get_contents($root . '/znews/index.html');
$embeddedWorker = file_get_contents($root . '/znews/sw.js');
$standaloneWorker = file_get_contents($root . '/znews/sw-root.js');

function edit_composer_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([
    'class="composer-form creator-edit-form"',
    'class="composer-topbar creator-edit-topbar"',
    'id="creatorEditAvatar"',
    'id="creatorEditName"',
    'id="creatorEditTitleCount"',
    'id="creatorEditTextCount"',
    'id="creatorEditPreview"',
    'id="creatorEditPhotoLabel"',
    'id="creatorEditSubmitTop"',
    'id="creatorEditSubmitBottom"',
] as $marker) {
    edit_composer_expect(str_contains($creator, $marker), "Edit composer marker is missing: {$marker}");
}

edit_composer_expect(str_contains($creator, 'renderEditPreview(dialog)'), 'Current/replacement photo preview is not wired.');
edit_composer_expect(str_contains($creator, 'data-format-bold'), 'Edit middle-bold control is missing.');
edit_composer_expect(str_contains($creator, 'richText.setEditorContent(') && str_contains($creator, 'post.formatting_runs'), 'Edit does not restore stored rich formatting without markers.');
edit_composer_expect(str_contains($creator, 'id="creatorEditText" contenteditable="true"') && !str_contains($creator, '<textarea id="creatorEditText"'), 'Edit must use one contenteditable surface without a textarea proxy.');
edit_composer_expect(str_contains($creator, 'bold_ranges: parsedText.boldRanges'), 'Edit does not submit validated bold ranges.');
edit_composer_expect(str_contains($creator, 'formatting_runs: parsedText.formattingRuns'), 'Edit does not submit validated formatting runs.');
edit_composer_expect(!str_contains($creator, "backdrop.className = 'composer-image-backdrop'"), 'Edit still creates a duplicate image backdrop.');
edit_composer_expect(str_contains($creator, 'URL.revokeObjectURL(currentImagePreviewUrl)')
    && str_contains($creator, 'URL.revokeObjectURL(replacementPreviewUrl)'), 'Edit photo object URLs are not released.');
edit_composer_expect(str_contains($creator, "['image/jpeg', 'image/png', 'image/webp']"), 'Edit photo MIME allowlist is missing.');
edit_composer_expect(str_contains($creator, 'replacement.size > 8 * 1024 * 1024'), 'Edit photo size limit is missing.');
edit_composer_expect(str_contains($creator, 'expected_updated_at: expectedUpdatedAt'), 'Optimistic edit version protection is missing.');
edit_composer_expect(str_contains($creator, "idempotency_key: idempotency('post-edit')"), 'Edit idempotency key is missing.');
edit_composer_expect(str_contains($creator, "editForm.setAttribute('aria-busy', 'true')"), 'Edit form busy state is missing.');
edit_composer_expect(str_contains($creator, "['SAVING…', 'Saving…']") && str_contains($creator, 'setEditMutationState'), 'Top/bottom Edit saving states are not synchronized.');
edit_composer_expect(str_contains($creator, "onStatus('uploading', 'Uploading photo…')"), 'Edit photo upload stage is not announced.');
edit_composer_expect(str_contains($creator, 'window.setTimeout(() =>') && str_contains($creator, 'Preparing editor'), 'Edit loader does not use a bounded delayed reveal.');
edit_composer_expect(str_contains($creator, 'creator-card-menu-actions') && str_contains($creator, 'role="menuitem"'), 'Post options does not use accessible full-width action rows.');
edit_composer_expect(!str_contains($creator, 'data-menu-close aria-label="Close post options">×'), 'Post options retains the floating close button.');
edit_composer_expect(str_contains($premium, '.creator-edit-topbar{position:fixed'), 'Mobile edit header is not fixed.');
edit_composer_expect(str_contains($premium, '.creator-edit-bottom-action{position:fixed'), 'Mobile Save action is not fixed.');
edit_composer_expect(str_contains($premium, 'object-fit:contain'), 'Edit photo preview may crop the selected photo.');
edit_composer_expect(!str_contains($creator, "document.createElement('style')"), 'Edit UI styling must not violate the production style-src policy.');
edit_composer_expect(str_contains($bootstrap, 'znews-creator.js?v=14'), 'Latest edit composer behavior is not activated.');
edit_composer_expect(str_contains($index, 'znews-bootstrap.js?v=33'), 'Reload-safe edit bootstrap is not activated.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    edit_composer_expect(str_contains($worker, 'znews-bootstrap.js?v=33'), 'A PWA shell is missing the reload-safe edit bootstrap.');
    edit_composer_expect(str_contains($worker, 'znews-creator.js?v=14'), 'A PWA shell is missing the edit composer.');
    edit_composer_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'A PWA shell may cache API responses.');
    edit_composer_expect(str_contains($worker, 'networkFirst(request'), 'A PWA shell may serve stale edit assets while online.');
}

echo "Z Sky 24 edit composer checks passed.\n";
