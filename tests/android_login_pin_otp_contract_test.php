<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function android_login_contract_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $source;
}

function android_login_contract_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$verifyPin = android_login_contract_source($root . '/api/auth/verify_pin.php');
$sendOtp = android_login_contract_source($root . '/api/auth/login_send_otp.php');
$verifyOtp = android_login_contract_source($root . '/api/auth/user_login_verify_otp.php');
$biometricLogin = android_login_contract_source($root . '/api/auth/biometric_login.php');
$auth = android_login_contract_source($root . '/api/lib/auth.php');
$authAndroid = android_login_contract_source($root . '/api/lib/auth_android.php');
$authSms = android_login_contract_source($root . '/api/lib/auth_sms.php');
$sms360 = android_login_contract_source($root . '/api/lib/sms_smss360.php');
$bulkSms = android_login_contract_source($root . '/api/lib/sms_bulksmsbd.php');
$rewrite = android_login_contract_source($root . '/.htaccess');

android_login_contract_expect(
    str_contains($verifyPin, "api_response(false, 'WRONG_PIN'")
        && str_contains($verifyPin, "api_response(true, 'PIN_VERIFIED'"),
    'PIN verification structured response codes changed'
);
android_login_contract_expect(
    str_contains($sendOtp, "api_response(true, 'OTP_SENT'")
        && str_contains($sendOtp, "api_response(true, 'OTP_ALREADY_SENT'"),
    'OTP send/retry success contract changed'
);
android_login_contract_expect(
    str_contains($sendOtp, "api_response(false, 'SMS_FAILED', 'Failed to send OTP SMS.'"),
    'SMS failures must remain safe structured JSON'
);
android_login_contract_expect(
    str_contains($sendOtp, '$expiresAt = $now + 300;')
        && str_contains($sendOtp, "'expires_in_seconds' => 300"),
    'Login OTP validity must remain 300 seconds'
);
android_login_contract_expect(
    str_contains($sendOtp, "in_array(\$existingStatus, ['SENT', 'RESENT'], true)")
        && str_contains($sendOtp, "'otp_request_id' => \$existingOtpRequestId"),
    'Existing valid login OTP must remain retry-safe'
);
android_login_contract_expect(
    str_contains($authSms, "if (\$country === 'MY')")
        && str_contains($authSms, 'auth_send_my_sms360')
        && str_contains($authSms, "if (\$country === 'BD')")
        && str_contains($authSms, 'auth_send_bd_sms'),
    'Country-specific SMS provider routing changed'
);
android_login_contract_expect(
    str_contains($sms360, 'CURLOPT_CONNECTTIMEOUT => 15')
        && str_contains($sms360, 'CURLOPT_TIMEOUT => 30')
        && str_contains($bulkSms, 'CURLOPT_CONNECTTIMEOUT => 15')
        && str_contains($bulkSms, 'CURLOPT_TIMEOUT => 30'),
    'SMS providers must keep bounded connection and total transfer timeouts'
);
android_login_contract_expect(
    str_contains($sms360, "str_starts_with(\$message, 'RM0 ')")
        && str_contains($sms360, 'otp_my_message_is_approved'),
    'Malaysia approved OTP template contract changed'
);
android_login_contract_expect(
    !preg_match('/RewriteRule\s+\^api\/auth\/(?:verify_pin|login_send_otp)/i', $rewrite),
    'Canonical Android auth endpoints must not be redirected by a route rule'
);
android_login_contract_expect(
    str_contains($verifyPin, 'auth_issue_trusted_device_cookie(')
        && str_contains($verifyPin, "'trusted_device_cookie'"),
    'trusted PIN login must issue a fresh canonical trusted-device cookie'
);
android_login_contract_expect(
    str_contains($verifyPin, "'TRUSTED_DEVICE_CREDENTIAL_FAILED'")
        && str_contains($verifyPin, "@fb_delete('USER_SESSIONS/' . \$sessionHash)"),
    'trusted PIN login must fail closed and remove the unreturned session when cookie persistence fails'
);
android_login_contract_expect(
    str_contains($verifyOtp, 'auth_issue_trusted_device_cookie(')
        && !str_contains($verifyOtp, 'function user_verify_create_trusted_device('),
    'OTP and trusted PIN login must share canonical cookie issuance'
);
android_login_contract_expect(
    !str_contains($biometricLogin, 'function biometric_login_has_valid_trusted_cookie(')
        && str_contains($biometricLogin, 'auth_trusted_browser_cookie_context('),
    'biometric login must use canonical device, epoch and revocation validation'
);
android_login_contract_expect(
    str_contains($auth, 'function auth_issue_trusted_device_cookie(')
        && str_contains($auth, "'token_hash' => hash('sha256', \$rawToken)")
        && str_contains($authAndroid, "'auth_session_epoch' => \$authSessionEpoch"),
    'canonical cookie issuance must hash the token and bind the issued session epoch'
);

echo "Android PIN-to-OTP backend contract tests passed ({$assertions} assertions).\n";
