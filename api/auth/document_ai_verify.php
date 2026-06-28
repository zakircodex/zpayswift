<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

function document_ai_ini_bytes(string|false $value): int
{
    if (!is_string($value) || trim($value) === '') {
        return 0;
    }

    $value = trim($value);
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;

    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

function document_ai_upload_error_response(int $error): void
{
    $uploadMax = document_ai_ini_bytes(ini_get('upload_max_filesize'));
    $postMax = document_ai_ini_bytes(ini_get('post_max_size'));
    $data = [
        'upload_error' => $error,
        'upload_max_bytes' => $uploadMax,
        'post_max_bytes' => $postMax,
    ];

    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        api_response(false, 'UPLOAD_SIZE_INVALID', 'Document image is too large. Please retake or crop a clearer smaller image.', $data, 413);
    }

    if ($error === UPLOAD_ERR_PARTIAL) {
        api_response(false, 'UPLOAD_PARTIAL', 'Document image upload was interrupted. Please try again.', $data, 400);
    }

    api_response(false, 'UPLOAD_FAILED', 'Document image upload failed.', $data, 400);
}

function document_ai_config_value(string $constant, string $envName, string $default = ''): string
{
    if (defined($constant)) {
        $constantValue = trim((string)constant($constant));
        if ($constantValue !== '') {
            return $constantValue;
        }
    }

    $value = getenv($envName);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    return $default;
}

function document_ai_temp_dir(): string
{
    $configured = document_ai_config_value('DOCUMENT_AI_TEMP_DIR', 'DOCUMENT_AI_TEMP_DIR');
    if ($configured !== '') {
        return rtrim($configured, "/\\");
    }

    return rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'zpayswift-document-ai';
}

function document_ai_cleanup(?string $path): void
{
    if ($path && is_file($path)) {
        @unlink($path);
    }
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = document_ai_ini_bytes(ini_get('post_max_size'));
if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax && empty($_POST) && empty($_FILES)) {
    api_response(false, 'UPLOAD_SIZE_INVALID', 'Document image is too large. Please retake or crop a clearer smaller image.', [
        'content_length_bytes' => $contentLength,
        'post_max_bytes' => $postMax,
    ], 413);
}

$documentType = strtoupper(trim((string)($_POST['document_type'] ?? '')));
if (!in_array($documentType, ['NID', 'PASSPORT'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'document_type must be NID or PASSPORT.', [], 422);
}

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    api_response(false, 'VALIDATION_ERROR', 'image file is required.', [], 422);
}

$file = $_FILES['image'];
$error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    document_ai_upload_error_response($error);
}

$tmpName = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    api_response(false, 'UPLOAD_INVALID', 'Invalid uploaded image.', [], 400);
}

if ($size <= 0 || $size > 8 * 1024 * 1024) {
    api_response(false, 'UPLOAD_SIZE_INVALID', 'Image size must be within 8 MB.', [], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmpName);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

if (!isset($allowed[$mime])) {
    api_response(false, 'UPLOAD_TYPE_INVALID', 'Only JPG/JPEG/PNG images are allowed.', [], 422);
}

$handle = fopen($tmpName, 'rb');
$signature = $handle ? (string)fread($handle, 8) : '';
if (is_resource($handle)) {
    fclose($handle);
}
if (!str_starts_with($signature, "\xFF\xD8\xFF") && $signature !== "\x89PNG\r\n\x1A\n") {
    api_response(false, 'UPLOAD_TYPE_INVALID', 'Only valid JPG/JPEG/PNG images are allowed.', [], 422);
}

$tempDir = document_ai_temp_dir();
if (!is_dir($tempDir) && !mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    api_response(false, 'SERVER_ERROR', 'Failed to prepare document verification storage.', [], 500);
}

$tempPath = $tempDir . DIRECTORY_SEPARATOR . 'doc_ai_' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
if (!move_uploaded_file($tmpName, $tempPath)) {
    api_response(false, 'UPLOAD_FAILED', 'Failed to save uploaded image.', [], 500);
}
@chmod($tempPath, 0600);

try {
    $aiUrl = document_ai_config_value('DOCUMENT_AI_URL', 'DOCUMENT_AI_URL', 'http://127.0.0.1:8010/v1/document/verify');
    $aiKey = document_ai_config_value('DOCUMENT_AI_KEY', 'DOCUMENT_AI_KEY');

    if ($aiKey === '') {
        api_response(false, 'DOCUMENT_AI_NOT_CONFIGURED', 'Document verification service is not configured.', [], 500);
    }

    if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
        api_response(false, 'DOCUMENT_AI_UNAVAILABLE', 'Document verification service is temporarily unavailable.', [], 503);
    }

    $ch = curl_init($aiUrl);
    if ($ch === false) {
        api_response(false, 'DOCUMENT_AI_UNAVAILABLE', 'Document verification service is temporarily unavailable.', [], 503);
    }

    $postFields = [
        'document_type' => $documentType,
        'image' => curl_file_create($tempPath, $mime, 'document.' . $allowed[$mime]),
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-AI-KEY: ' . $aiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '' || $httpCode < 200 || $httpCode >= 500) {
        api_response(false, 'DOCUMENT_AI_UNAVAILABLE', 'Document verification service is temporarily unavailable.', [], 503);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        api_response(false, 'DOCUMENT_AI_INVALID_RESPONSE', 'Document verification service returned an invalid response.', [], 502);
    }

    $ok = (bool)($decoded['success'] ?? $decoded['ok'] ?? false);
    $code = strtoupper(trim((string)($decoded['code'] ?? ($ok ? 'DOCUMENT_PARSED' : 'DOCUMENT_AI_ERROR'))));
    $message = trim((string)($decoded['message'] ?? ($ok ? 'Document parsed successfully.' : 'Document verification failed.')));
    $data = is_array($decoded['data'] ?? null) ? (array)$decoded['data'] : [];

    api_response($ok, $code, $message, $data, $ok ? 200 : ($httpCode >= 400 ? min($httpCode, 499) : 422));
} finally {
    document_ai_cleanup($tempPath ?? null);
}
