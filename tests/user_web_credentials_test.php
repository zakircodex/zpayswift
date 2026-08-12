<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/user_web_credentials.php';

function credential_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['12345', '1234567', 'abcdef', '12ab56', ''] as $invalidPassword) {
    credential_test_expect(!user_web_password_valid($invalidPassword), "Password must reject '{$invalidPassword}'");
}
credential_test_expect(user_web_password_valid('123456'), 'Password must accept exactly six numeric digits');
$passwordHash = password_hash('123456', PASSWORD_DEFAULT);
credential_test_expect(password_verify('123456', $passwordHash), 'Login hashing must accept a newly registered six-digit password');

foreach (['123', '12345', '12345678', '12AB', 'abcd', ''] as $invalidPin) {
    credential_test_expect(!user_web_transaction_pin_valid($invalidPin), "PIN must reject '{$invalidPin}'");
}
credential_test_expect(user_web_transaction_pin_valid('1234'), 'PIN must accept exactly four numeric digits');
$pinHash = password_hash('1234', PASSWORD_DEFAULT);
credential_test_expect(password_verify('1234', $pinHash), 'PIN hashing must accept a newly registered four-digit transaction PIN');

echo "Web User credential validation tests passed\n";
