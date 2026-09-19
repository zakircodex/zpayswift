<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';
require_once dirname(__DIR__) . '/lib/birthday_media.php';

api_require_method('POST');
birthday_same_origin();

$file = is_array($_FILES['audio'] ?? null) ? (array)$_FILES['audio'] : [];
if ($file === []) {
    api_response(false, 'BIRTHDAY_AUDIO_REQUIRED', 'Choose an audio file to upload.', [], 422);
}
if (!znews_bool($_POST['rights_confirmed'] ?? false, false)) {
    api_response(false, 'BIRTHDAY_AUDIO_RIGHTS_REQUIRED', 'Confirm that you have permission to use this audio.', [], 422);
}
$requestId = birthday_media_upload_request_id($_POST['upload_request_id'] ?? '');
$draftId = trim((string)($_POST['draft_id'] ?? ''));
$slug = strtolower(trim((string)($_POST['slug'] ?? '')));

if ($draftId !== '') {
    $draftToken = trim((string)($_POST['draft_token'] ?? api_get_header('X-Draft-Token') ?? ''));
    $draft = birthday_load_draft($draftId, $draftToken);
    $validated = birthday_custom_audio_validate($file);
    $media = birthday_custom_audio_store($validated, 'DRAFT', $draftId, $requestId);
    $draft = birthday_audio_attach_to_draft($draft, $media);
    api_response(true, 'BIRTHDAY_AUDIO_UPLOADED', 'Custom audio uploaded securely.', [
        'media_id' => (string)$media['id'],
        'draft' => birthday_public_draft($draft),
    ], 201);
}

if ($slug !== '') {
    api_require_app_key();
    $auth = birthday_require_account();
    $universe = birthday_owned_universe($slug, $auth);
    $validated = birthday_custom_audio_validate($file);
    $media = birthday_custom_audio_store($validated, 'UNIVERSE', (string)$universe['id'], $requestId);
    $universe = birthday_audio_attach_to_universe($universe, $media);
    api_response(true, 'BIRTHDAY_AUDIO_UPDATED', 'Custom audio updated securely.', [
        'media_id' => (string)$media['id'],
        'universe' => birthday_public_universe($universe),
    ], 201);
}

api_response(false, 'BIRTHDAY_AUDIO_TARGET_REQUIRED', 'Audio upload target is missing.', [], 422);
