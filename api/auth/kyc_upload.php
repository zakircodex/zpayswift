<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$uid = (string)$auth['user']['uid'];
$user = (array)$auth['user'];
$role = auth_status_value($user['role'] ?? '');

if (!auth_app_allowed_role($role)) {
    api_response(false, 'FORBIDDEN', 'User app access required.', [], 403);
}

function kyc_upload_one(string $uid, string $field): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $file = $_FILES[$field];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_response(false, 'UPLOAD_FAILED', 'KYC upload failed.', ['field' => $field], 400);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        api_response(false, 'UPLOAD_INVALID', 'Invalid uploaded file.', ['field' => $field], 400);
    }

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        api_response(false, 'UPLOAD_SIZE_INVALID', 'File size must be within 5 MB.', ['field' => $field], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        api_response(false, 'UPLOAD_TYPE_INVALID', 'Only JPG, PNG, WEBP or PDF files are allowed.', ['field' => $field], 422);
    }

    $baseDir = dirname(__DIR__) . '/storage/kyc/' . $uid;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
        api_response(false, 'SERVER_ERROR', 'Failed to prepare KYC storage.', [], 500);
    }

    $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $target = $baseDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $target)) {
        api_response(false, 'UPLOAD_FAILED', 'Failed to save uploaded file.', ['field' => $field], 500);
    }

    @chmod($target, 0640);

    return [
        'field' => $field,
        'path_private' => 'api/storage/kyc/' . $uid . '/' . $filename,
        'mime' => $mime,
        'size' => $size,
        'uploaded_at' => now_ts(),
    ];
}

$document = kyc_upload_one($uid, 'document');
$selfie = kyc_upload_one($uid, 'selfie');

if (!$document && !$selfie) {
    api_response(false, 'VALIDATION_ERROR', 'document or selfie file is required.', [], 422);
}

$kyc = is_array($user['KYC'] ?? null) ? (array)$user['KYC'] : [];
if ($document) {
    $kyc['document_path_private'] = $document['path_private'];
    $kyc['document_mime'] = $document['mime'];
    $kyc['document_size'] = $document['size'];
    $kyc['document_uploaded_at'] = $document['uploaded_at'];
}
if ($selfie) {
    $kyc['selfie_path_private'] = $selfie['path_private'];
    $kyc['selfie_mime'] = $selfie['mime'];
    $kyc['selfie_size'] = $selfie['size'];
    $kyc['selfie_uploaded_at'] = $selfie['uploaded_at'];
}

$kyc['status'] = (string)($kyc['status'] ?? 'PENDING_REVIEW');
$kyc['updated_at'] = now_ts();

if (!fb_patch('USERS/' . $uid, [
    'KYC' => $kyc,
    'kyc_status' => $kyc['status'],
    'updated_at' => now_ts(),
])) {
    api_response(false, 'SERVER_ERROR', 'Failed to update KYC metadata.', [], 500);
}

api_response(true, 'KYC_UPLOAD_SAVED', 'KYC upload saved for review.', [
    'kyc_status' => $kyc['status'],
    'document_uploaded' => (bool)$document,
    'selfie_uploaded' => (bool)$selfie,
]);
