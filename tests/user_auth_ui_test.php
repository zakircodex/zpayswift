<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function auth_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function source(string $path): string
{
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }
    return $value;
}

$loginPage = source($root . '/api/user/index.php');
$loginJs = source($root . '/api/user/assets/pages/login-page.js');
$loginCss = source($root . '/api/user/assets/pages/login-page.css');
$registerPage = source($root . '/api/user/register.php');
$registerJs = source($root . '/api/user/assets/register.js');
$registerCss = source($root . '/api/user/assets/register.css');
$forgotPage = source($root . '/api/user/forgot.php');
$forgotJs = source($root . '/api/user/assets/forgot.js');
$forgotCss = source($root . '/api/user/assets/forgot.css');
$proxy = source($root . '/api/user/proxy.php');
$verifyPinEndpoint = source($root . '/api/auth/verify_pin.php');

auth_ui_expect(str_contains($loginPage, 'Enter your phone number to continue.'), 'Login phone-first copy is missing');
auth_ui_expect(str_contains($loginPage, 'data-login-step="phone"') && str_contains($loginPage, 'data-login-step="password"') && str_contains($loginPage, 'data-login-step="pin"') && str_contains($loginPage, 'data-login-step="otp"'), 'Login staged markup is incomplete');
auth_ui_expect(str_contains($loginPage, 'id="loginLoadingModal"') && !str_contains($loginPage, 'id="loadingWrap"'), 'Login loader must remain page-local');
auth_ui_expect(str_contains($loginPage, 'href="/user/register"') && str_contains($loginPage, 'href="/user/forgot"'), 'Login Register/Forgot links are incorrect');
auth_ui_expect(str_contains($loginPage, 'id="loginCountryDisplay"') && str_contains($loginPage, 'id="loginPhoneCountry" type="hidden"') && !str_contains($loginPage, '<select id="loginPhoneCountry"'), 'Login country must be read-only');
auth_ui_expect(str_contains($loginPage, 'placeholder="Phone number"') && !str_contains($loginPage, '01XXXXXXXXX or'), 'Login phone placeholder must match Android');
auth_ui_expect(str_contains($loginJs, "post('login_check_number'") && str_contains($loginJs, "post('login_verify_password'") && str_contains($loginJs, "post('login_verify_pin'") && str_contains($loginJs, "post('login_send_otp'"), 'Staged login proxy actions are missing');
auth_ui_expect(str_contains($loginJs, "post('login_verify_otp'") && str_contains($loginJs, "post('login_resend_otp'"), 'Login OTP actions are missing');
auth_ui_expect(str_contains($loginJs, "const stepOrder = ['phone', 'password', 'pin', 'otp']"), 'Login Back order must include the PIN step');
auth_ui_expect(str_contains($loginJs, 'expires_in_seconds') && str_contains($loginJs, 'formatCountdown'), 'Login OTP 300-second countdown support is missing');
auth_ui_expect(str_contains($loginJs, 'state.phoneInFlight') && str_contains($loginJs, 'state.passwordInFlight') && str_contains($loginJs, 'state.pinInFlight') && str_contains($loginJs, 'state.verifyInFlight'), 'Login duplicate submission guards are missing');
auth_ui_expect(str_contains($loginJs, "window.addEventListener('pagehide'") && str_contains($loginJs, "window.addEventListener('pageshow'"), 'Login transition/BFCache cleanup is missing');
auth_ui_expect(!str_contains($loginJs, 'localStorage') && !str_contains($loginJs, 'console.log'), 'Login must not store/log credentials or auth tokens');
auth_ui_expect(str_contains($loginCss, '.user-login-page .login-card') && str_contains($loginCss, '.login-page-loading'), 'Login CSS is not page-scoped or loader-compatible');
auth_ui_expect(str_contains($proxy, "case 'login_check_number':") && str_contains($proxy, "case 'login_verify_password':") && str_contains($proxy, "case 'login_verify_pin':") && str_contains($proxy, "case 'login_send_otp':"), 'Web proxy staged login routes are missing');
auth_ui_expect(str_contains($proxy, "'force_otp' => true"), 'Web PIN verification must require OTP');
auth_ui_expect(str_contains($verifyPinEndpoint, "auth_app_bool(\$body['force_otp'] ?? false)") && str_contains($verifyPinEndpoint, '!$forceOtp && auth_app_trusted_login_allowed'), 'Optional force-OTP handling must preserve Android trusted-device behaviour');

foreach (['details', 'contact', 'security', 'location', 'otp'] as $step) {
    auth_ui_expect(str_contains($registerPage, 'data-register-step="' . $step . '"'), "Register {$step} step is missing");
}
auth_ui_expect(str_contains($registerPage, 'id="regIdentityNumber"') && str_contains($registerPage, 'id="verifyLocationBtn"'), 'Register identity/location controls are missing');
auth_ui_expect(str_contains($registerPage, 'id="registerLoadingModal"') && !str_contains($registerPage, 'id="loadingWrap"'), 'Register loader must be page-local');
auth_ui_expect(str_contains($registerJs, 'identity_number:') && str_contains($registerJs, 'gps_lat:') && str_contains($registerJs, 'terms_accepted:'), 'Register canonical fields are not preserved');
auth_ui_expect(!preg_match('/pricing_country\s*:/', $registerJs), 'Register must not submit user-controlled pricing_country');
auth_ui_expect(str_contains($registerJs, "proxyPost('registration_location_check'") && str_contains($registerJs, "proxyPost('register_send_otp'") && str_contains($registerJs, "proxyPost('register_confirm'"), 'Register canonical actions are missing');
auth_ui_expect(str_contains($registerJs, 'navigator.geolocation.getCurrentPosition'), 'Registration must require browser GPS location');
auth_ui_expect(!str_contains($registerJs, 'localStorage') && !str_contains($registerJs, 'console.log'), 'Register must not store/log password, PIN or OTP');
auth_ui_expect(str_contains($registerCss, '.user-register-page .register-card') && !str_contains($registerCss, "\n.card{"), 'Register CSS must remain page scoped');

foreach (['phone', 'identity', 'otp', 'credential'] as $step) {
    auth_ui_expect(str_contains($forgotPage, 'data-forgot-step="' . $step . '"'), "Forgot {$step} step is missing");
}
auth_ui_expect(str_contains($forgotPage, 'id="forgotIdentityNumber"'), 'Forgot registered identity input is missing');
auth_ui_expect(str_contains($forgotPage, 'id="forgotLoadingModal"') && !str_contains($forgotPage, 'id="loadingWrap"'), 'Forgot loader must be page-local');
auth_ui_expect(str_contains($forgotJs, "proxyPost('forgot_send_otp'") && str_contains($forgotJs, "proxyPost('forgot_resend_otp'") && str_contains($forgotJs, "proxyPost('forgot_verify_otp'"), 'Forgot canonical proxy actions are missing');
auth_ui_expect(str_contains($forgotJs, 'identity_number: el(\'forgotIdentityNumber\')'), 'Forgot reset must send the registered identity number');
auth_ui_expect(str_contains($proxy, "'identity_number' => trim((string)("), 'User proxy must forward the required forgot identity field');
auth_ui_expect(str_contains($forgotJs, 'expires_in_seconds') && str_contains($forgotJs, 'formatCountdown'), 'Forgot OTP countdown support is missing');
auth_ui_expect(str_contains($forgotJs, "code.includes('OTP_')") && str_contains($forgotJs, "code.includes('IDENTITY_')"), 'Forgot canonical OTP/identity error recovery is missing');
auth_ui_expect(!str_contains($forgotJs, 'localStorage') && !str_contains($forgotJs, 'console.log'), 'Forgot must not store/log reset secrets');
auth_ui_expect(str_contains($forgotCss, '.user-forgot-page .forgot-card') && !str_contains($forgotCss, "\n.card{"), 'Forgot CSS must remain page scoped');

auth_ui_expect(str_contains($loginCss, 'height: 100dvh') && str_contains($registerCss, 'height: 100dvh') && str_contains($forgotCss, 'height: 100dvh'), 'Auth pages must use stable mobile viewport sizing');
auth_ui_expect(str_contains($loginJs, "window.addEventListener('popstate'") && str_contains($registerJs, "window.addEventListener('popstate'") && str_contains($forgotJs, "window.addEventListener('popstate'"), 'Auth step Back/history handling is incomplete');
auth_ui_expect(str_contains($loginCss, '@media (prefers-reduced-motion: reduce)') && str_contains($registerCss, '@media (prefers-reduced-motion: reduce)') && str_contains($forgotCss, '@media (prefers-reduced-motion: reduce)'), 'Auth reduced-motion support is incomplete');

echo "User auth UI tests passed ({$assertions} assertions).\n";
