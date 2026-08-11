<?php
declare(strict_types=1);

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
