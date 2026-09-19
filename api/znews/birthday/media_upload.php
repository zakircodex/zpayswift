<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';
require_once dirname(__DIR__) . '/lib/birthday_media.php';

api_require_method('POST');
birthday_same_origin();
[$field, $file] = znews_media_input_file();
if ($field === '' || !$file) {
    api_response(false, 'BIRTHDAY_PHOTO_REQUIRED', 'Choose an image to upload.', [], 422);
}
$validated = birthday_photo_validate($file);
$draftId = trim((string)($_POST['draft_id'] ?? ''));
$slug = strtolower(trim((string)($_POST['slug'] ?? '')));
if ($draftId !== '') {
    $draftToken = trim((string)($_POST['draft_token'] ?? api_get_header('X-Draft-Token') ?? ''));
    $draft = birthday_load_draft($draftId, $draftToken);
    $media = birthday_photo_store($validated, 'DRAFT', $draftId);
    $draft = birthday_photo_attach_to_draft($draft, $media);
    api_response(true, 'BIRTHDAY_PHOTO_UPLOADED', 'Photo uploaded securely.', [
        'media_id' => (string)$media['id'],
        'draft' => birthday_public_draft($draft),
    ], 201);
}
if ($slug !== '') {
    api_require_app_key();
    $auth = birthday_require_account();
    $universe = birthday_owned_universe($slug, $auth);
    $media = birthday_photo_store($validated, 'UNIVERSE', (string)$universe['id']);
    $universe = birthday_photo_attach_to_universe($universe, $media);
    api_response(true, 'BIRTHDAY_PHOTO_UPDATED', 'Photo updated securely.', [
        'media_id' => (string)$media['id'],
        'universe' => birthday_public_universe($universe),
    ], 201);
}
api_response(false, 'BIRTHDAY_PHOTO_TARGET_REQUIRED', 'Photo upload target is missing.', [], 422);
