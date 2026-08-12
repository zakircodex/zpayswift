<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function forgot_identity_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function forgot_identity_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }
    return $source;
}

require_once $root . '/api/lib/auth_android.php';
require_once $root . '/api/lib/user_forgot_recovery.php';

$passportUser = ['identity_type' => 'PASSPORT', 'identity_number_hash' => str_repeat('a', 64)];
$nidUser = ['KYC' => ['type' => 'NID', 'identity_number_hash' => str_repeat('b', 64)]];
$legacyPassportUser = ['passport_hash' => str_repeat('c', 64)];
$ambiguousLegacyUser = ['passport_hash' => str_repeat('d', 64), 'nid_hash' => str_repeat('e', 64)];
$conflictingCanonicalUser = ['identity_type' => 'NID', 'KYC' => ['type' => 'PASSPORT'], 'identity_number_hash' => str_repeat('f', 64)];
forgot_identity_expect(user_forgot_registered_identity_type($passportUser) === 'PASSPORT', 'registered Passport type must be resolved server-side');
forgot_identity_expect(user_forgot_registered_identity_type($nidUser) === 'NID', 'registered NID type must be resolved server-side');
forgot_identity_expect(user_forgot_registered_identity_type($legacyPassportUser) === 'PASSPORT', 'a Passport-specific legacy field may resolve the legacy registered type');
forgot_identity_expect(user_forgot_registered_identity_type($ambiguousLegacyUser) === '', 'ambiguous legacy NID and Passport data must not be guessed');
forgot_identity_expect(user_forgot_registered_identity_type(['identity_number_hash' => str_repeat('0', 64)]) === '', 'a shared legacy identity hash must not be guessed as NID or Passport');
forgot_identity_expect(user_forgot_registered_identity_type($conflictingCanonicalUser) === 'NID', 'top-level canonical identity_type must take precedence over conflicting legacy data');
forgot_identity_expect(user_forgot_identity_is_configured($passportUser), 'configured identity hash must be detected without exposing it');
forgot_identity_expect(!user_forgot_identity_is_configured(['identity_type' => 'NID']), 'identity type alone must not make recovery eligible');

$nidNumber = '1234567890';
$passportNumber = 'A1234567';
$typedUser = [
    'identity_type' => 'NID',
    'nid_hash' => auth_app_identity_hash($nidNumber),
    'passport_hash' => auth_app_identity_hash($passportNumber),
];
forgot_identity_expect(!empty(user_forgot_identity_match_state($typedUser, $nidNumber, 'NID')['match']), 'NID recovery must match the NID field');
forgot_identity_expect(empty(user_forgot_identity_match_state($typedUser, $passportNumber, 'NID')['match']), 'NID recovery must not match a Passport field');
forgot_identity_expect(!empty(user_forgot_identity_match_state($typedUser, $passportNumber, 'PASSPORT')['match']), 'Passport recovery must match the Passport field');
forgot_identity_expect(empty(user_forgot_identity_match_state($typedUser, $nidNumber, 'PASSPORT')['match']), 'Passport recovery must not match an NID field');

$row = [
    'status' => 'PHONE_VERIFIED',
    'identity_attempt_limit' => 5,
    'identity_failed_attempts' => 0,
    'identity_next_attempt_at' => 0,
];
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $patch = user_forgot_identity_failure_patch($row, 100 + ($attempt * 3));
    $row = array_merge($row, $patch);
    forgot_identity_expect((int)$row['identity_failed_attempts'] === $attempt, "identity attempt {$attempt} must be recorded");
}
$blocked = user_forgot_identity_attempt_state($row, 200);
forgot_identity_expect(!empty($blocked['blocked']) && (int)$blocked['attempts_remaining'] === 0, 'fifth identity failure must block the recovery session');

$cooldownRow = [
    'status' => 'PHONE_VERIFIED',
    'identity_attempt_limit' => 5,
    'identity_failed_attempts' => 1,
    'identity_next_attempt_at' => 105,
];
$cooldown = user_forgot_identity_attempt_state($cooldownRow, 102);
forgot_identity_expect(!empty($cooldown['rate_limited']) && (int)$cooldown['retry_after_seconds'] === 3, 'rapid identity attempts must be rate limited');

$start = forgot_identity_source($root . '/api/auth/user_forgot_start.php');
$identity = forgot_identity_source($root . '/api/auth/user_forgot_verify_identity.php');
$send = forgot_identity_source($root . '/api/auth/user_forgot_send_otp.php');
$resend = forgot_identity_source($root . '/api/auth/user_forgot_resend_otp.php');
$verify = forgot_identity_source($root . '/api/auth/user_forgot_verify_otp.php');
$reset = forgot_identity_source($root . '/api/auth/user_forgot_reset.php');
$proxy = forgot_identity_source($root . '/api/user/proxy.php');
$js = forgot_identity_source($root . '/api/user/assets/forgot.js');

forgot_identity_expect(str_contains($start, "'status' => 'PHONE_VERIFIED'") && !str_contains($start, 'auth_send_otp_sms_by_country'), 'phone verification must create state without sending OTP');
forgot_identity_expect(str_contains($start, 'user_forgot_registered_identity_type') && !str_contains($start, 'identity_number_last4'), 'phone response must expose only registered identity type');
forgot_identity_expect(str_contains($identity, 'user_forgot_identity_match_state($user, $identityNumber, $identityType)'), 'identity must use the canonical type-scoped server matcher');
forgot_identity_expect(str_contains($identity, 'user_forgot_registered_identity_type($user)') && str_contains($identity, 'hash_equals($identityType, $registeredIdentityType)'), 'identity verification must keep the recovery token bound to the live canonical account type');
forgot_identity_expect(str_contains($identity, 'fb_get_with_etag') && str_contains($identity, 'fb_put_if_match'), 'identity attempts and success must use CAS');
forgot_identity_expect(str_contains($identity, "'IDENTITY_ATTEMPTS_EXCEEDED'") && str_contains($identity, "'attempts_remaining'"), 'identity endpoint must enforce a finite attempt budget');
forgot_identity_expect(!str_contains($identity, 'identity_number_hash') && !str_contains($identity, 'identity_number_last4'), 'recovery state must not persist submitted identity values or derivatives');
forgot_identity_expect(str_contains($send, 'user_forgot_send_combined_from_identity') && str_contains($send, "empty(\$preAuthRow['identity_verified'])"), 'combined OTP send must require identity-verified state');
forgot_identity_expect(str_contains($send, "\$claim['status'] = 'OTP_SENDING'") && str_contains($send, 'fb_put_if_match'), 'combined OTP send must claim once before SMS');
forgot_identity_expect(str_contains($resend, "empty(\$preAuthRow['identity_verified'])"), 'OTP resend must remain identity gated');
forgot_identity_expect(str_contains($verify, "empty(\$preAuthRow['identity_verified'])") && str_contains($verify, "'reset_allowed' => true"), 'OTP verify must enforce prior identity and grant reset authorization');
forgot_identity_expect(str_contains($reset, "empty(\$preAuthRow['identity_verified'])") && str_contains($reset, "empty(\$preAuthRow['reset_allowed'])"), 'credential reset must require identity and OTP authorization');
forgot_identity_expect(str_contains($proxy, "case 'forgot_start':") && str_contains($proxy, "case 'forgot_verify_identity':"), 'Web proxy must expose scoped start and identity actions');
forgot_identity_expect(str_contains($js, "proxyPost('forgot_start'") && str_contains($js, "proxyPost('forgot_verify_identity'") && str_contains($js, "proxyPost('forgot_send_otp'"), 'Web must start, verify identity, then send OTP');
forgot_identity_expect(strpos($js, "proxyPost('forgot_verify_identity'") < strpos($js, "proxyPost('forgot_send_otp'"), 'identity request must precede OTP send in the Web flow');
forgot_identity_expect(str_contains($js, "identityType === 'PASSPORT'") && !str_contains($js, 'forgotIdentityTypeSelect'), 'Web must render the server identity type without a user-selectable document type');
forgot_identity_expect(!str_contains($js, 'localStorage') && !str_contains($js, 'console.log'), 'recovery secrets must not be stored or logged by Web');

echo "User forgot identity gate tests passed ({$assertions} assertions).\n";
