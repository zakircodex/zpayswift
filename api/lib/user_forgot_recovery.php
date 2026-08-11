<?php
declare(strict_types=1);

function user_forgot_registered_identity_type(array $user): string
{
    $type = strtoupper(trim((string)(
        $user['identity_type']
        ?? $user['KYC']['type']
        ?? $user['KYC']['document_type']
        ?? ''
    )));

    if (in_array($type, ['NID', 'PASSPORT'], true)) {
        return $type;
    }

    $passportCandidates = [
        $user['passport_hash'] ?? '',
        $user['passport'] ?? '',
        $user['KYC']['passport_hash'] ?? '',
        $user['KYC']['passport'] ?? '',
    ];
    foreach ($passportCandidates as $candidate) {
        if (trim((string)$candidate) !== '') {
            return 'PASSPORT';
        }
    }

    $nidCandidates = [
        $user['nid_hash'] ?? '',
        $user['nid'] ?? '',
        $user['KYC']['nid_hash'] ?? '',
        $user['KYC']['nid'] ?? '',
    ];
    foreach ($nidCandidates as $candidate) {
        if (trim((string)$candidate) !== '') {
            return 'NID';
        }
    }

    return '';
}

function user_forgot_identity_is_configured(array $user): bool
{
    $candidates = [
        $user['identity_number_hash'] ?? '',
        $user['document_number_hash'] ?? '',
        $user['nid_or_passport_hash'] ?? '',
        $user['nid_hash'] ?? '',
        $user['passport_hash'] ?? '',
        $user['identity_number'] ?? '',
        $user['document_number'] ?? '',
        $user['nid_or_passport_number'] ?? '',
        $user['nid'] ?? '',
        $user['passport'] ?? '',
        $user['KYC']['identity_number_hash'] ?? '',
        $user['KYC']['document_number_hash'] ?? '',
        $user['KYC']['nid_or_passport_hash'] ?? '',
        $user['KYC']['identity_number'] ?? '',
        $user['KYC']['document_number'] ?? '',
        $user['KYC']['nid_or_passport_number'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if (trim((string)$candidate) !== '') {
            return true;
        }
    }

    return false;
}

function user_forgot_identity_attempt_state(array $row, int $now): array
{
    $limit = max(1, (int)($row['identity_attempt_limit'] ?? 5));
    $attempts = max(0, (int)($row['identity_failed_attempts'] ?? 0));
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    $retryAfter = max(0, (int)($row['identity_next_attempt_at'] ?? 0) - $now);

    return [
        'blocked' => $status === 'IDENTITY_BLOCKED' || $attempts >= $limit,
        'rate_limited' => $retryAfter > 0,
        'attempts' => $attempts,
        'attempts_remaining' => max(0, $limit - $attempts),
        'limit' => $limit,
        'retry_after_seconds' => $retryAfter,
    ];
}

function user_forgot_identity_failure_patch(array $row, int $now, int $cooldownSeconds = 2): array
{
    $state = user_forgot_identity_attempt_state($row, $now);
    $attempts = min((int)$state['limit'], (int)$state['attempts'] + 1);
    $blocked = $attempts >= (int)$state['limit'];

    return [
        'identity_failed_attempts' => $attempts,
        'identity_next_attempt_at' => $blocked ? 0 : $now + max(1, $cooldownSeconds),
        'status' => $blocked ? 'IDENTITY_BLOCKED' : 'PHONE_VERIFIED',
        'updated_at' => $now,
        'attempts_remaining' => max(0, (int)$state['limit'] - $attempts),
        'blocked' => $blocked,
    ];
}

function user_forgot_combined_validate_credentials(
    string $newPassword,
    string $confirmPassword,
    string $newPin,
    string $confirmPin
): array {
    if ($newPassword === '' || $confirmPassword === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'New password and confirm password are required.'];
    }

    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Password confirmation does not match.'];
    }

    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Password must be at least 6 characters.'];
    }

    if ($newPin === '' || $confirmPin === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'New PIN and confirm PIN are required.'];
    }

    if ($newPin !== $confirmPin) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'PIN confirmation does not match.'];
    }

    if (!preg_match('/^\d{4}$/', $newPin)) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'PIN must be exactly 4 digits.'];
    }

    return ['ok' => true, 'code' => 'VALID', 'message' => 'Credentials are valid.'];
}

function user_forgot_combined_build_update(string $newPassword, string $newPin, int $now): array
{
    return [
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'pin_hash' => password_hash($newPin, PASSWORD_DEFAULT),
        'password_updated_at' => $now,
        'pin_updated_at' => $now,
        'updated_at' => $now,
    ];
}

function user_forgot_combined_credentials_match(array $user, string $newPassword, string $newPin): bool
{
    $passwordHash = trim((string)($user['password_hash'] ?? ''));
    $pinHash = trim((string)($user['pin_hash'] ?? ''));

    return $passwordHash !== ''
        && $pinHash !== ''
        && password_verify($newPassword, $passwordHash)
        && password_verify($newPin, $pinHash);
}
