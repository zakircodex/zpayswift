<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_public_brand(): string
{
    return 'Z Sky 24';
}

function znews_standalone_host(): string
{
    return 'zsky24.com';
}

function znews_request_host(): string
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return preg_replace('/:\d+$/', '', $host) ?? '';
}

function znews_normalize_target_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    return $host === 'www.' . znews_standalone_host() ? znews_standalone_host() : $host;
}

function znews_handoff_target_host(): string
{
    return znews_standalone_host();
}

function znews_handoff_path(string $code): string
{
    return 'ZNEWS_HANDOFF_GRANTS/' . hash('sha256', $code);
}

function znews_handoff_context(): array
{
    return [
        'ip_hash' => hash('sha256', client_ip()),
        'user_agent_hash' => hash('sha256', trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))),
    ];
}

function znews_handoff_encrypt_token(string $token): array
{
    $cipher = 'aes-256-gcm';
    $key = hash('sha256', 'zsky24-handoff|' . APP_KEY, true);
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($token, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, znews_standalone_host());
    if ($encrypted === false || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to protect handoff token.');
    }

    return [
        'token_ciphertext' => base64_encode($encrypted),
        'token_iv' => base64_encode($iv),
        'token_tag' => base64_encode($tag),
    ];
}

function znews_handoff_decrypt_token(array $grant): string
{
    $encrypted = base64_decode((string)($grant['token_ciphertext'] ?? ''), true);
    $iv = base64_decode((string)($grant['token_iv'] ?? ''), true);
    $tag = base64_decode((string)($grant['token_tag'] ?? ''), true);
    if ($encrypted === false || $iv === false || $tag === false) {
        return '';
    }

    $key = hash('sha256', 'zsky24-handoff|' . APP_KEY, true);
    $token = openssl_decrypt(
        $encrypted,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        znews_standalone_host()
    );
    return is_string($token) ? trim($token) : '';
}
