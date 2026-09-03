<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$creator = file_get_contents($root . '/znews/assets/znews-creator.js');
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
edit_composer_expect(str_contains($creator, 'URL.revokeObjectURL(currentImagePreviewUrl)')
    && str_contains($creator, 'URL.revokeObjectURL(replacementPreviewUrl)'), 'Edit photo object URLs are not released.');
edit_composer_expect(str_contains($creator, "['image/jpeg', 'image/png', 'image/webp']"), 'Edit photo MIME allowlist is missing.');
edit_composer_expect(str_contains($creator, 'replacement.size > 8 * 1024 * 1024'), 'Edit photo size limit is missing.');
edit_composer_expect(str_contains($creator, 'expected_updated_at: expectedUpdatedAt'), 'Optimistic edit version protection is missing.');
edit_composer_expect(str_contains($creator, "idempotency_key: idempotency('post-edit')"), 'Edit idempotency key is missing.');
edit_composer_expect(str_contains($creator, "editForm.setAttribute('aria-busy', 'true')"), 'Edit form busy state is missing.');
edit_composer_expect(str_contains($creator, '.creator-edit-topbar{position:fixed'), 'Mobile edit header is not fixed.');
edit_composer_expect(str_contains($creator, '.creator-edit-bottom-action{position:fixed'), 'Mobile Save action is not fixed.');
edit_composer_expect(str_contains($creator, 'object-fit:contain'), 'Edit photo preview may crop the selected photo.');
edit_composer_expect(str_contains($bootstrap, 'znews-creator.js?v=8'), 'Latest edit composer behavior is not activated.');
edit_composer_expect(str_contains($index, 'znews-bootstrap.js?v=24'), 'Reload-safe edit bootstrap is not activated.');

foreach ([$embeddedWorker, $standaloneWorker] as $worker) {
    edit_composer_expect(str_contains($worker, 'znews-bootstrap.js?v=24'), 'A PWA shell is missing the reload-safe edit bootstrap.');
    edit_composer_expect(str_contains($worker, 'znews-creator.js?v=8'), 'A PWA shell is missing the edit composer.');
    edit_composer_expect(str_contains($worker, "url.pathname.startsWith('/api/')"), 'A PWA shell may cache API responses.');
    edit_composer_expect(str_contains($worker, 'networkFirst(request'), 'A PWA shell may serve stale edit assets while online.');
}

echo "Z Sky 24 edit composer checks passed.\n";
