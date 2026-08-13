<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function user_registration_kyc_private_root(): string
{
    return rtrim(dirname(app_private_config_path()), '/\\') . '/storage/register_kyc';
}

function user_registration_kyc_token_root(string $registerToken): string
{
    return user_registration_kyc_private_root() . '/' . substr(hash('sha256', trim($registerToken)), 0, 32);
}

function user_registration_kyc_normalized_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function user_registration_kyc_valid_private_file(string $path, string $registerToken): bool
{
    $path = trim($path);
    if (
        $path === ''
        || trim($registerToken) === ''
        || str_contains($path, "\0")
        || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1
        || preg_match('/(^|[\/\\\\])\.\.([\/\\\\]|$)/', $path) === 1
    ) {
        return false;
    }

    $realRoot = realpath(user_registration_kyc_token_root($registerToken));
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false || !is_file($realPath)) {
        return false;
    }

    $root = rtrim(user_registration_kyc_normalized_path($realRoot), '/') . '/';
    $file = user_registration_kyc_normalized_path($realPath);
    if (!str_starts_with($file, $root)) {
        return false;
    }

    $size = (int)filesize($realPath);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        return false;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);
    return in_array($mime, ['image/jpeg', 'image/png'], true);
}

function user_registration_kyc_state(array $preAuth, string $registerToken): array
{
    $kyc = is_array($preAuth['KYC'] ?? null) ? (array)$preAuth['KYC'] : [];
    $documentPath = trim((string)($kyc['document_path_private'] ?? $preAuth['document_path_private'] ?? ''));
    $selfiePath = trim((string)($kyc['selfie_path_private'] ?? $preAuth['selfie_path_private'] ?? ''));
    $documentReady = user_registration_kyc_valid_private_file($documentPath, $registerToken);
    $selfieReady = user_registration_kyc_valid_private_file($selfiePath, $registerToken);

    return [
        'document_ready' => $documentReady,
        'selfie_ready' => $selfieReady,
        'kyc_ready' => $documentReady && $selfieReady,
        'document_path_private' => $documentReady ? $documentPath : '',
        'selfie_path_private' => $selfieReady ? $selfiePath : '',
        'kyc' => $kyc,
    ];
}

function user_registration_kyc_is_web_draft(array $preAuth): bool
{
    return !empty($preAuth['web_kyc_draft'])
        && strtoupper(trim((string)($preAuth['registration_source'] ?? ''))) === 'USER_WEB';
}

function user_registration_kyc_remove_private_file(string $path, string $registerToken): void
{
    if (user_registration_kyc_valid_private_file($path, $registerToken)) {
        @unlink($path);
    }
}
