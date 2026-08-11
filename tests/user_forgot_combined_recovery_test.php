<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function forgot_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function forgot_test_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }
    return $source;
}

require_once $root . '/api/lib/user_forgot_recovery.php';

$valid = user_forgot_combined_validate_credentials('secret99', 'secret99', '2468', '2468');
forgot_test_expect(!empty($valid['ok']), 'valid password and four-digit PIN should pass');
forgot_test_expect(empty(user_forgot_combined_validate_credentials('short', 'short', '2468', '2468')['ok']), 'short password must fail');
forgot_test_expect(empty(user_forgot_combined_validate_credentials('secret99', 'different', '2468', '2468')['ok']), 'password mismatch must fail');
forgot_test_expect(empty(user_forgot_combined_validate_credentials('secret99', 'secret99', '24680', '24680')['ok']), 'non-four-digit PIN must fail');
forgot_test_expect(empty(user_forgot_combined_validate_credentials('secret99', 'secret99', '2468', '1357')['ok']), 'PIN mismatch must fail');

$oldUser = [
    'password_hash' => password_hash('old-secret', PASSWORD_DEFAULT),
    'pin_hash' => password_hash('1111', PASSWORD_DEFAULT),
];
$update = user_forgot_combined_build_update('new-secret', '2468', 123456);
$updatedUser = array_merge($oldUser, $update);
forgot_test_expect(isset($update['password_hash'], $update['pin_hash']), 'one credential update must contain both hashes');
forgot_test_expect(!password_verify('old-secret', $updatedUser['password_hash']), 'old password must stop matching');
forgot_test_expect(!password_verify('1111', $updatedUser['pin_hash']), 'old PIN must stop matching');
forgot_test_expect(user_forgot_combined_credentials_match($updatedUser, 'new-secret', '2468'), 'new password and PIN must both match');

$send = forgot_test_source($root . '/api/auth/user_forgot_send_otp.php');
$resend = forgot_test_source($root . '/api/auth/user_forgot_resend_otp.php');
$verify = forgot_test_source($root . '/api/auth/user_forgot_verify_otp.php');
$reset = forgot_test_source($root . '/api/auth/user_forgot_reset.php');
$proxy = forgot_test_source($root . '/api/user/proxy.php');

forgot_test_expect(str_contains($send, "'PASSWORD_PIN'") && str_contains($send, "'USER_FORGOT_PASSWORD_PIN'"), 'send endpoint must support the combined recovery purpose');
forgot_test_expect(str_contains($send, "['PASSWORD', 'PIN', 'PASSWORD_PIN']") && str_contains($verify, "if (\$resetType === 'PIN')") && str_contains($verify, "\$update['password_hash']"), 'legacy Password/PIN send and verify branches must remain present');
forgot_test_expect(str_contains($resend, "'status' => 'CANCELLED'") && str_contains($resend, '$newOtpRequestId'), 'resend must rotate and cancel the old OTP');
forgot_test_expect(str_contains($verify, "'OTP_ALREADY_USED'") && str_contains($verify, "'status' => 'OTP_VERIFIED'"), 'OTP verify must reject used codes and authorize reset separately');
forgot_test_expect(str_contains($verify, "'reset_authorization_token' => \$preAuthToken"), 'OTP verify must return a reset authorization token');
forgot_test_expect(str_contains($reset, 'fb_get_with_etag') && str_contains($reset, 'fb_put_if_match'), 'combined reset must claim the reset token with CAS');
forgot_test_expect(str_contains($reset, "\$claim['status'] = 'RESETTING'") && str_contains($reset, "if (\$status === 'COMPLETED')"), 'combined reset must block replay and record in-progress state');
forgot_test_expect(substr_count($reset, "fb_patch('USERS/' . \$uid, \$update)") === 1, 'password and PIN must use one atomic user-row patch');
forgot_test_expect(str_contains($reset, 'auth_app_revoke_user_sessions_and_trust($uid)'), 'successful reset must invalidate sessions and trusted devices');
forgot_test_expect(str_contains($reset, "'RESET_TOKEN_USED'") && str_contains($reset, "'DEVICE_MISMATCH'"), 'reset token replay and device mismatch must be rejected');
forgot_test_expect(str_contains($proxy, "case 'forgot_reset_credentials':") && str_contains($proxy, "'device_id' => 'USER_WEB'"), 'Web proxy must expose only the scoped combined reset action');

echo "User forgot combined recovery tests passed ({$assertions} assertions).\n";
