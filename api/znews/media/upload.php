<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/media.php';

api_require_method('POST');
api_require_app_key();
$auth = znews_require_creator(true);

[$field, $file] = znews_media_input_file();
if ($field === '' || !$file) {
    api_response(false, 'ZNEWS_MEDIA_REQUIRED', 'An image is required.', [], 422);
}

$idempotencyKey = znews_idempotency_key(
    $_POST['idempotency_key']
    ?? $_POST['client_request_id']
    ?? api_get_header('X-Idempotency-Key')
    ?? ''
);
$validated = znews_media_validate_upload($file);
$result = znews_media_create($auth, $validated, $idempotencyKey);
if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'ZNEWS_MEDIA_UPLOAD_FAILED'),
        (string)($result['message'] ?? 'Image upload failed.'),
        is_array($result['data'] ?? null) ? (array)$result['data'] : [],
        (int)($result['http_status'] ?? 500)
    );
}

$replay = !empty($result['idempotent_replay']);
api_response(
    true,
    $replay ? 'ZNEWS_MEDIA_ALREADY_UPLOADED' : 'ZNEWS_MEDIA_UPLOADED',
    $replay ? 'Image was already uploaded.' : 'Image uploaded securely.',
    [
        'media' => is_array($result['media'] ?? null) ? (array)$result['media'] : [],
        'idempotent_replay' => $replay,
    ],
    $replay ? 200 : 201
);
