<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function register_flow_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function register_flow_source(string $path): string
{
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }
    return $value;
}

$page = register_flow_source($root . '/api/user/register.php');
$js = register_flow_source($root . '/api/user/assets/register.js');
$css = register_flow_source($root . '/api/user/assets/register.css');
$proxy = register_flow_source($root . '/api/user/proxy.php');
$sendOtp = register_flow_source($root . '/api/auth/user_register_send_otp.php');
$confirm = register_flow_source($root . '/api/auth/user_register_confirm.php');
$androidRegister = register_flow_source($root . '/api/lib/register_android.php');
$credentials = register_flow_source($root . '/api/lib/user_web_credentials.php');
$identityUniqueness = register_flow_source($root . '/api/lib/user_registration_identity.php');

foreach (['phone', 'personal', 'identity', 'password', 'pin', 'review', 'otp'] as $step) {
    register_flow_expect(str_contains($page, 'data-register-step="' . $step . '"'), "Missing {$step} registration step");
}
register_flow_expect(str_contains($js, "const steps = ['phone', 'personal', 'identity', 'password', 'pin', 'review', 'otp']"), 'Registration Back/history order is incorrect');
register_flow_expect(str_contains($page, 'Country: Detecting...') && str_contains($page, 'id="regPhoneCountry" type="hidden"') && !str_contains($page, '<select id="regPhoneCountry"'), 'Phone country is not read-only');
register_flow_expect(str_contains($page, 'id="registerPhoneContinue" class="register-primary" type="button" disabled') && str_contains($js, "'Country: Unavailable'"), 'Country detection must fail closed before phone validation');
register_flow_expect(str_contains($page, 'data-register-identity="NID"') && str_contains($page, 'data-register-identity="PASSPORT"'), 'NID and Passport registration paths are incomplete');
register_flow_expect(!str_contains($page, 'id="reviewIdentityNumber"') && !str_contains($page, '>Password</span><strong') && !str_contains($page, '>PIN</span><strong'), 'Review leaks identity or credentials');
register_flow_expect(str_contains($page, 'href="/terms"') && str_contains($page, 'href="/privacy"'), 'Terms and Privacy routes are not preserved');

register_flow_expect(str_contains($proxy, "case 'register_precheck':") && str_contains($proxy, "\$stage === 'PHONE'") && str_contains($proxy, "\$stage === 'PERSONAL'"), 'Registration phone/personal precheck routes are incomplete');
register_flow_expect(str_contains($proxy, "\$stage === 'IDENTITY'") && str_contains($proxy, "\$stage === 'PASSWORD'") && str_contains($proxy, "\$stage === 'PIN'"), 'Identity, password and PIN prechecks are incomplete');
register_flow_expect(str_contains($proxy, 'reg_app_phone_uid') && str_contains($proxy, 'reg_app_email_uid'), 'Registration prechecks must use canonical direct indexes');
register_flow_expect(str_contains($proxy, 'user_web_registration_identity_lookup') && str_contains($proxy, 'NID_ALREADY_REGISTERED') && str_contains($proxy, 'PASSPORT_ALREADY_REGISTERED'), 'Identity precheck must reject existing NID and Passport indexes');
register_flow_expect(str_contains($sendOtp, 'user_web_registration_identity_lookup($identityHashes, $identityType)'), 'OTP preparation must recheck normalized identity variants');
register_flow_expect(str_contains($confirm, 'auth_index_claim') && str_contains($confirm, 'reg_app_document_index_paths($hash, $identityType)'), 'Final registration must retain CAS-backed unique index claims for every normalized identity variant');
register_flow_expect(strpos($confirm, 'auth_index_claim') < strpos($confirm, "fb_put('USERS/' . \$uid"), 'Identity and other unique indexes must be claimed before account creation');
register_flow_expect(str_contains($confirm, 'NID_ALREADY_REGISTERED') && str_contains($confirm, 'PASSPORT_ALREADY_REGISTERED'), 'Final registration must preserve type-specific identity conflicts');
register_flow_expect(str_contains($confirm, 'user_web_registration_identity_lookup($identityHashes, $identityType)'), 'Direct final registration must recheck existing and legacy identities');
register_flow_expect(str_contains($identityUniqueness, "'orderBy' => json_encode('identity_number_hash'") && str_contains($identityUniqueness, 'user_web_registration_identity_legacy_index_missing') && str_contains($identityUniqueness, "'source' => 'INDEX_ONLY'") && str_contains($identityUniqueness, 'IDENTITY_LOOKUP_FAILED'), 'Only a missing legacy Firebase query index may fall back to authoritative indexes');
register_flow_expect(str_contains($credentials, "preg_match('/^\\d{6}$/D', \$password)") && str_contains($credentials, "preg_match('/^\\d{4}$/D', \$pin)"), 'Canonical Web User credential lengths are incorrect');
register_flow_expect(str_contains($identityUniqueness, '$upper = strtoupper($trimmed)') && str_contains($identityUniqueness, "preg_replace('/[^A-Z0-9]+/', '', \$upper)"), 'Web identity punctuation normalization must preserve lowercase letters before hashing');
register_flow_expect(str_contains($proxy, 'user_web_password_valid($password)') && str_contains($proxy, 'user_web_transaction_pin_valid($pin)'), 'Registration precheck must use canonical Web User credential validators');
register_flow_expect(str_contains($sendOtp, 'user_web_password_valid($password)') && str_contains($sendOtp, 'user_web_transaction_pin_valid($pin)'), 'OTP preparation must revalidate canonical credential lengths');
register_flow_expect(str_contains($js, '/^\\d{6}$/.test(data.password)') && str_contains($js, '/^\\d{4}$/.test(data.pin)'), 'Register client validation must enforce exact credential lengths');

register_flow_expect(str_contains($js, "proxyPost('registration_location_check'") && str_contains($js, 'navigator.geolocation.getCurrentPosition'), 'GPS-authoritative market verification is missing');
register_flow_expect(str_contains($js, "requiresAdminReview: Boolean(data.requires_admin_review)\n      };\n      clearOtpState();"), 'Location re-verification must invalidate any stale registration OTP state');
register_flow_expect(!preg_match('/pricing_country\s*:/', $js), 'Client must not submit pricing_country');
register_flow_expect(str_contains($sendOtp, 'market_registration_decision($body, $phoneCountry)') && str_contains($sendOtp, "auth_country_currency(\$pricingCountry)"), 'Backend market and currency resolution is no longer authoritative');
register_flow_expect(str_contains($confirm, "'phone_country' => \$phoneCountry") && str_contains($confirm, "'pricing_country' => \$pricingCountry") && str_contains($confirm, "'currency' => \$currency"), 'Canonical country/currency fields are not finalized');
register_flow_expect(str_contains($confirm, "'identity_type' => \$identityType") && str_contains($confirm, "'identity_number_hash' => \$identityHash"), 'Canonical identity schema is not finalized');
register_flow_expect(str_contains($androidRegister, "'USER_INDEX/NID/'") && str_contains($androidRegister, "'USER_INDEX/PASSPORT/'"), 'Web identity checks are not aligned with Android index paths');

register_flow_expect(str_contains($js, "proxyPost('register_send_otp'") && strpos($js, "proxyPost('register_send_otp'") > strpos($js, 'verifyRegistrationLocation'), 'OTP must be sent from review, after location verification');
register_flow_expect(str_contains($page, 'Already have an account? <strong>Log in</strong>') && !str_contains($page, 'Forgot Password &amp; PIN'), 'Register bottom action must be Login only');
register_flow_expect(str_contains($page, 'id="sendRegisterOtpBtn" class="register-primary" type="button" disabled') && str_contains($js, 'updateCreateAvailability'), 'Create Account must remain gated by location and terms');
register_flow_expect(str_contains($js, 'expires_in_seconds') && str_contains($js, 'formatCountdown') && str_contains($js, "proxyPost('register_resend_otp'"), 'Registration OTP expiry/resend handling is incomplete');
register_flow_expect(str_contains($confirm, 'auth_otp_claim_verification') && str_contains($confirm, 'auth_otp_complete_verification'), 'OTP claim/finalization protection is missing');
register_flow_expect(str_contains($confirm, 'account_review_send_telegram') && str_contains($confirm, "\$accountStatus !== 'ACTIVE'"), 'ACTIVE/REVIEW and Telegram review compatibility is missing');

register_flow_expect(str_contains($css, 'width: min(100%, 430px)') && str_contains($css, 'height: 100dvh'), 'Registration card sizing no longer matches Auth UI');
register_flow_expect(str_contains($js, 'window.visualViewport') && str_contains($js, 'ensureControlVisible') && str_contains($css, '--register-keyboard-inset'), 'Registration keyboard safety is incomplete');
register_flow_expect(!str_contains($js, 'localStorage') && !str_contains($js, 'sessionStorage') && !str_contains($js, 'console.log'), 'Registration secrets must not be persisted or logged');

echo "User registration staged flow tests passed ({$assertions} assertions).\n";
