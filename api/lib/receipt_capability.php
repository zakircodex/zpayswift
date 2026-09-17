<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function receipt_capability_ttl_seconds(): int
{
    $ttl = defined('RECEIPT_TOKEN_TTL_SECONDS')
        ? (int)constant('RECEIPT_TOKEN_TTL_SECONDS')
        : 30 * 24 * 60 * 60;

    return max(60 * 60, min(365 * 24 * 60 * 60, $ttl));
}

function receipt_capability_metadata(int $issuedAt): array
{
    $issuedAt = max(1, $issuedAt);

    return [
        'receipt_token_version' => 2,
        'issued_at' => $issuedAt,
        'expires_at' => $issuedAt + receipt_capability_ttl_seconds(),
        'status' => 'ACTIVE',
    ];
}

function receipt_capability_access(array $tokenRow, ?int $now = null): array
{
    $hasVersion = array_key_exists('receipt_token_version', $tokenRow);
    $version = (int)($tokenRow['receipt_token_version'] ?? 1);
    if (!in_array($version, [1, 2], true)) {
        return ['ok' => false, 'legacy' => false, 'version' => $version, 'code' => 'RECEIPT_TOKEN_INVALID'];
    }

    $legacy = !$hasVersion || $version === 1;
    $status = strtoupper(trim((string)($tokenRow['status'] ?? '')));
    if ((!$legacy && $status !== 'ACTIVE') || ($legacy && $status !== '' && $status !== 'ACTIVE')) {
        return [
            'ok' => false,
            'legacy' => $legacy,
            'version' => $version,
            'code' => in_array($status, ['REVOKED', 'DISABLED'], true)
                ? 'RECEIPT_TOKEN_REVOKED'
                : 'RECEIPT_TOKEN_INVALID',
        ];
    }

    $issuedAt = !$legacy
        ? (int)($tokenRow['issued_at'] ?? 0)
        : (int)(
            ($tokenRow['issued_at'] ?? 0)
            ?: ($tokenRow['receipt_created_at'] ?? 0)
            ?: ($tokenRow['created_at'] ?? 0)
        );
    $expiresAt = (int)($tokenRow['expires_at'] ?? 0);
    if ($issuedAt <= 0) {
        return [
            'ok' => false,
            'legacy' => $legacy,
            'version' => $version,
            'code' => 'RECEIPT_TOKEN_INVALID',
        ];
    }
    if ($expiresAt <= 0 && $legacy) {
        $expiresAt = $issuedAt + receipt_capability_ttl_seconds();
    }
    if ($expiresAt <= $issuedAt) {
        return [
            'ok' => false,
            'legacy' => $legacy,
            'version' => $version,
            'code' => 'RECEIPT_TOKEN_INVALID',
        ];
    }

    if (($now ?? time()) >= $expiresAt) {
        return [
            'ok' => false,
            'legacy' => $legacy,
            'version' => $version,
            'code' => 'RECEIPT_TOKEN_EXPIRED',
        ];
    }

    return [
        'ok' => true,
        'legacy' => $legacy,
        'version' => $version,
        'code' => 'RECEIPT_TOKEN_VALID',
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
    ];
}
