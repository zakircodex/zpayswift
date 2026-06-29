<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';

api_require_method('POST');
api_require_app_key();

function reg_kyc_private_storage_dir(string $registerToken): string
{
    $privateRoot = dirname(app_private_config_path());
    $tokenDir = substr(hash('sha256', $registerToken), 0, 32);

    return rtrim($privateRoot, '/\\') . '/storage/register_kyc/' . $tokenDir;
}

function reg_kyc_file_from_request(string $uploadType): array
{
    $uploadType = strtoupper(trim($uploadType));
    $fieldCandidates = $uploadType === 'SELFIE'
        ? ['selfie_photo', 'selfie', 'file']
        : ['document_photo', 'document', 'file'];

    foreach ($fieldCandidates as $field) {
        if (!empty($_FILES[$field]) && is_array($_FILES[$field])) {
            return [$field, $_FILES[$field]];
        }
    }

    return ['', []];
}

function reg_kyc_upload_type(array $body): string
{
    $type = strtoupper(trim((string)(
        $body['upload_type']
        ?? $body['kyc_type']
        ?? $body['file_type']
        ?? ''
    )));

    if (in_array($type, ['DOCUMENT', 'SELFIE'], true)) {
        return $type;
    }

    if (!empty($_FILES['selfie_photo']) || !empty($_FILES['selfie'])) {
        return 'SELFIE';
    }

    if (!empty($_FILES['document_photo']) || !empty($_FILES['document'])) {
        return 'DOCUMENT';
    }

    return '';
}

function reg_kyc_save_upload(string $registerToken, string $uploadType, array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        api_response(false, 'UPLOAD_FAILED', 'KYC upload failed.', ['upload_type' => $uploadType], 400);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        api_response(false, 'UPLOAD_INVALID', 'Invalid uploaded file.', ['upload_type' => $uploadType], 400);
    }

    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        api_response(false, 'UPLOAD_SIZE_INVALID', 'File size must be within 8 MB.', ['upload_type' => $uploadType], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!isset($allowed[$mime])) {
        api_response(false, 'UPLOAD_TYPE_INVALID', 'Only JPG, JPEG or PNG files are allowed.', ['upload_type' => $uploadType], 422);
    }

    $baseDir = reg_kyc_private_storage_dir($registerToken);
    if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
        api_response(false, 'SERVER_ERROR', 'Failed to prepare private KYC storage.', [], 500);
    }

    $prefix = strtolower($uploadType);
    $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $target = $baseDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $target)) {
        api_response(false, 'UPLOAD_FAILED', 'Failed to save uploaded file.', ['upload_type' => $uploadType], 500);
    }

    @chmod($target, 0640);

    return [
        'path_private' => $target,
        'mime' => $mime,
        'size' => $size,
        'uploaded_at' => reg_app_now(),
    ];
}

$body = $_POST ?: [];
$registerToken = reg_app_find_preauth_token($body);
$preAuth = reg_app_get_preauth($registerToken);
reg_app_require_otp_verified($preAuth);

$status = strtoupper(trim((string)($preAuth['status'] ?? '')));
if ($status === 'COMPLETED' || !empty($preAuth['completed_at'])) {
    api_response(false, 'REGISTER_ALREADY_COMPLETED', 'Registration already completed.', [], 409);
}

$uploadType = reg_kyc_upload_type($body);
if (!in_array($uploadType, ['DOCUMENT', 'SELFIE'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'upload_type DOCUMENT or SELFIE is required.', [], 422);
}

[$fileField, $file] = reg_kyc_file_from_request($uploadType);
if ($fileField === '' || !$file) {
    api_response(false, 'VALIDATION_ERROR', $uploadType === 'SELFIE'
        ? 'Selfie photo is required.'
        : 'Document photo is required.', [], 422);
}

$now = reg_app_now();
$saved = reg_kyc_save_upload($registerToken, $uploadType, $file);
$documentType = reg_app_document_type($body + $preAuth);
$patch = [
    'status' => $uploadType === 'DOCUMENT' ? 'DOCUMENT_UPLOADED' : 'SELFIE_UPLOADED',
    'updated_at' => $now,
    'expires_at' => $now + 3600,
];

$kyc = is_array($preAuth['KYC'] ?? null) ? (array)$preAuth['KYC'] : [];
if ($documentType !== '') {
    $kyc['type'] = $documentType;
    $patch['document_type'] = $documentType;
    $patch['identity_type'] = $documentType;
}

if ($uploadType === 'DOCUMENT') {
    $kyc['document_path_private'] = $saved['path_private'];
    $kyc['document_mime'] = $saved['mime'];
    $kyc['document_size'] = $saved['size'];
    $kyc['document_uploaded_at'] = $saved['uploaded_at'];
    $patch['document_path_private'] = $saved['path_private'];
    $patch['document_upload_ref'] = 'DOCUMENT';
} else {
    $kyc['selfie_path_private'] = $saved['path_private'];
    $kyc['selfie_mime'] = $saved['mime'];
    $kyc['selfie_size'] = $saved['size'];
    $kyc['selfie_uploaded_at'] = $saved['uploaded_at'];
    $patch['selfie_path_private'] = $saved['path_private'];
    $patch['selfie_upload_ref'] = 'SELFIE';
}

$kyc['status'] = (string)($kyc['status'] ?? 'PENDING_REVIEW');
$kyc['updated_at'] = $now;
$patch['KYC'] = $kyc;

if (!fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, $patch)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save KYC upload state.', [], 500);
}

$documentReady = !empty($kyc['document_path_private']);
$selfieReady = !empty($kyc['selfie_path_private']);

api_response(true, 'KYC_UPLOAD_SAVED', 'KYC upload saved for registration.', [
    'register_token' => $registerToken,
    'upload_type' => $uploadType,
    'document_uploaded' => $documentReady,
    'selfie_uploaded' => $selfieReady,
    'kyc_ready' => $documentReady && $selfieReady,
    'upload_ref' => $uploadType,
]);
