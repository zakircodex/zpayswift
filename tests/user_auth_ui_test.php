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
$checkNumberEndpoint = source($root . '/api/auth/check_number.php');
$verifyPinEndpoint = source($root . '/api/auth/verify_pin.php');
$authLibrary = source($root . '/api/lib/auth.php');

auth_ui_expect(str_contains($loginPage, 'Enter your phone number to continue.'), 'Login phone-first copy is missing');
auth_ui_expect(str_contains($loginPage, 'data-login-step="phone"') && str_contains($loginPage, 'data-login-step="password"') && str_contains($loginPage, 'data-login-step="pin"') && str_contains($loginPage, 'data-login-step="otp"'), 'Login staged markup is incomplete');
auth_ui_expect(str_contains($loginPage, 'id="loginLoadingModal"') && !str_contains($loginPage, 'id="loadingWrap"'), 'Login loader must remain page-local');
auth_ui_expect(str_contains($loginPage, 'href="/user/register"') && str_contains($loginPage, 'href="/user/forgot"'), 'Login Register/Forgot links are incorrect');
auth_ui_expect(str_contains($loginPage, 'id="loginCountryDisplay"') && str_contains($loginPage, 'id="loginPhoneCountry" type="hidden"') && !str_contains($loginPage, '<select id="loginPhoneCountry"'), 'Login country must be read-only');
auth_ui_expect(str_contains($loginPage, 'id="loginUseAnotherAccount"') && str_contains($loginPage, 'Use another account'), 'Trusted PIN screen must offer local account switching');
auth_ui_expect(str_contains($loginPage, 'placeholder="Phone number"') && !str_contains($loginPage, '01XXXXXXXXX or'), 'Login phone placeholder must match Android');
auth_ui_expect(str_contains($loginJs, "post('login_check_number'") && str_contains($loginJs, "post('login_verify_password'") && str_contains($loginJs, "post('login_verify_pin'") && str_contains($loginJs, "post('login_send_otp'"), 'Staged login proxy actions are missing');
auth_ui_expect(str_contains($loginJs, "post('login_verify_otp'") && str_contains($loginJs, "post('login_resend_otp'"), 'Login OTP actions are missing');
auth_ui_expect(str_contains($loginJs, "const stepOrder = ['phone', 'password', 'pin', 'otp']"), 'Login Back order must include the PIN step');
auth_ui_expect(str_contains($loginJs, 'expires_in_seconds') && str_contains($loginJs, 'formatCountdown'), 'Login OTP 300-second countdown support is missing');
auth_ui_expect(str_contains($loginJs, 'state.phoneInFlight') && str_contains($loginJs, 'state.passwordInFlight') && str_contains($loginJs, 'state.pinInFlight') && str_contains($loginJs, 'state.verifyInFlight'), 'Login duplicate submission guards are missing');
auth_ui_expect(str_contains($loginJs, 'trusted_login_available') && str_contains($loginJs, 'data.login_complete === true'), 'Trusted browser must route from phone to PIN and complete login after PIN');
auth_ui_expect(str_contains($loginJs, "post('login_trusted_account'") && str_contains($loginJs, 'bootstrapLogin()'), 'Login page must resolve a trusted account before showing the phone flow');
auth_ui_expect(str_contains($loginJs, 'ignore_trusted_device: state.ignoreTrustedLogin') && str_contains($loginJs, 'useAnotherAccount'), 'Use another account must bypass only the local trusted selection');
auth_ui_expect(str_contains($loginJs, 'window.visualViewport') && str_contains($loginJs, 'ensureLoginControlVisible') && str_contains($loginJs, 'actionForInput'), 'Login keyboard handling must keep inputs and action buttons visible');
auth_ui_expect(str_contains($loginJs, "window.addEventListener('pagehide'") && str_contains($loginJs, "window.addEventListener('pageshow'"), 'Login transition/BFCache cleanup is missing');
auth_ui_expect(!str_contains($loginJs, 'localStorage') && !str_contains($loginJs, 'console.log'), 'Login must not store/log credentials or auth tokens');
auth_ui_expect(str_contains($loginCss, '.user-login-page .login-card') && str_contains($loginCss, '.login-page-loading'), 'Login CSS is not page-scoped or loader-compatible');
auth_ui_expect(str_contains($loginCss, '--login-keyboard-inset') && str_contains($loginCss, '.user-login-page.login-keyboard-open'), 'Login keyboard viewport CSS is missing');
auth_ui_expect(str_contains($proxy, "case 'login_check_number':") && str_contains($proxy, "case 'login_verify_password':") && str_contains($proxy, "case 'login_verify_pin':") && str_contains($proxy, "case 'login_send_otp':"), 'Web proxy staged login routes are missing');
auth_ui_expect(str_contains($proxy, "case 'login_trusted_account':") && str_contains($proxy, "'preserve_trusted_device'"), 'Trusted page-load lookup and Web logout preservation are missing');
auth_ui_expect(str_contains($proxy, "'trusted_device_cookie' => \$trustedDeviceCookie") && str_contains($proxy, "'trusted_device_cookie' => user_proxy_get_trust_cookie()"), 'Trusted cookie must be injected server-side for account recognition and PIN verification');
auth_ui_expect(str_contains($checkNumberEndpoint, 'trusted_login_available') && str_contains($checkNumberEndpoint, 'TRUSTED_DEVICE_RECOGNIZED'), 'Account check must issue trusted-browser PIN pre-auth only after secure validation');
auth_ui_expect(str_contains($verifyPinEndpoint, 'trusted_browser_verified') && str_contains($verifyPinEndpoint, 'TRUSTED_DEVICE_INVALID'), 'PIN verification must revalidate the trusted browser');
auth_ui_expect(str_contains($authLibrary, 'function auth_trusted_browser_cookie_context') && str_contains($authLibrary, "'auth_session_epoch'"), 'Trusted browser validation must bind token, device and session epoch');

foreach (['phone', 'personal', 'identity', 'password', 'pin', 'review', 'otp'] as $step) {
    auth_ui_expect(str_contains($registerPage, 'data-register-step="' . $step . '"'), "Register {$step} step is missing");
}
auth_ui_expect(str_contains($registerPage, 'id="regIdentityNumber"') && str_contains($registerPage, 'id="verifyLocationBtn"') && str_contains($registerPage, 'id="sendRegisterOtpBtn"'), 'Register identity/location/review controls are missing');
auth_ui_expect(str_contains($registerPage, 'Already have an account? <strong>Log in</strong>') && !str_contains($registerPage, 'Forgot Password &amp; PIN'), 'Register must expose one Login action and no Forgot action');
auth_ui_expect(str_contains($registerPage, 'id="regPhoneCountry" type="hidden"') && !str_contains($registerPage, '<select id="regPhoneCountry"'), 'Register phone country must remain detected and read-only');
auth_ui_expect(str_contains($registerPage, 'data-register-identity="NID"') && str_contains($registerPage, 'data-register-identity="PASSPORT"'), 'Register canonical identity selector is missing');
auth_ui_expect(str_contains($registerPage, 'id="reviewIdentity"') && !str_contains($registerPage, 'id="reviewIdentityNumber"'), 'Register review must show identity type without exposing the full identity number');
auth_ui_expect(str_contains($registerPage, 'id="registerLoadingModal"') && !str_contains($registerPage, 'id="loadingWrap"'), 'Register loader must be page-local');
auth_ui_expect(str_contains($registerJs, 'identity_number:') && str_contains($registerJs, 'gps_lat:') && str_contains($registerJs, 'terms_accepted:'), 'Register canonical fields are not preserved');
auth_ui_expect(!preg_match('/pricing_country\s*:/', $registerJs), 'Register must not submit user-controlled pricing_country');
auth_ui_expect(str_contains($registerJs, "proxyPost('register_precheck'") && str_contains($registerJs, "proxyPost('registration_location_check'") && str_contains($registerJs, "proxyPost('register_send_otp'") && str_contains($registerJs, "proxyPost('register_confirm'"), 'Register canonical staged actions are missing');
auth_ui_expect(str_contains($registerJs, 'navigator.geolocation.getCurrentPosition'), 'Registration must require browser GPS location');
auth_ui_expect(str_contains($registerJs, 'window.visualViewport') && str_contains($registerJs, 'ensureControlVisible') && str_contains($registerCss, '--register-keyboard-inset'), 'Register keyboard viewport handling is missing');
auth_ui_expect(!str_contains($registerJs, 'localStorage') && !str_contains($registerJs, 'console.log'), 'Register must not store/log password, PIN or OTP');
auth_ui_expect(str_contains($registerCss, '.user-register-page .register-card') && !str_contains($registerCss, "\n.card{"), 'Register CSS must remain page scoped');

foreach (['phone', 'identity', 'otp', 'credential'] as $step) {
    auth_ui_expect(str_contains($forgotPage, 'data-forgot-step="' . $step . '"'), "Forgot {$step} step is missing");
}
auth_ui_expect(str_contains($forgotPage, 'id="forgotIdentityNumber"') && str_contains($forgotPage, 'id="forgotIdentityTypeLabel"'), 'Web Forgot identity verification UI is missing');
auth_ui_expect(str_contains($forgotPage, 'Registered Identity: <span id="forgotIdentityTypeLabel"') && !str_contains($forgotPage, 'forgotIdentityTypeSelect'), 'Forgot must display the server-resolved identity type without a selector');
auth_ui_expect(!str_contains($forgotPage, 'identity_number_last4') && !str_contains($forgotPage, 'stored identity number'), 'Forgot UI must not expose registered identity values');
auth_ui_expect(str_contains($forgotPage, 'id="forgotCountryDisplay"') && str_contains($forgotPage, 'id="forgotPhoneCountry" type="hidden"') && !str_contains($forgotPage, '<select id="forgotPhoneCountry"'), 'Forgot country must be detected and read-only');
auth_ui_expect(str_contains($forgotPage, 'Country: Detecting...'), 'Forgot country summary must use the Login-style one-line pattern');
auth_ui_expect(str_contains($forgotPage, 'id="newPassword"') && str_contains($forgotPage, 'id="newPin"') && str_contains($forgotPage, 'Update Password &amp; PIN'), 'Forgot combined password and PIN form is incomplete');
auth_ui_expect(str_contains($forgotPage, 'id="forgotLoadingModal"') && !str_contains($forgotPage, 'id="loadingWrap"'), 'Forgot loader must be page-local');
auth_ui_expect(str_contains($forgotJs, "proxyPost('forgot_start'") && str_contains($forgotJs, "proxyPost('forgot_verify_identity'") && str_contains($forgotJs, "proxyPost('forgot_send_otp'") && str_contains($forgotJs, "proxyPost('forgot_resend_otp'") && str_contains($forgotJs, "proxyPost('forgot_verify_otp'") && str_contains($forgotJs, "proxyPost('forgot_reset_credentials'"), 'Forgot staged identity and combined reset proxy actions are missing');
auth_ui_expect(str_contains($forgotJs, "reset_type: 'PASSWORD_PIN'") && str_contains($forgotJs, 'reset_authorization_token:'), 'Forgot combined reset authorization contract is missing');
auth_ui_expect(str_contains($proxy, "case 'forgot_reset_credentials':") && str_contains($proxy, "'auth/user_forgot_reset.php'"), 'User proxy combined credential-reset route is missing');
auth_ui_expect(str_contains($forgotJs, 'expires_in_seconds') && str_contains($forgotJs, 'formatCountdown'), 'Forgot OTP countdown support is missing');
auth_ui_expect(str_contains($forgotJs, "code.includes('ATTEMPTS_EXCEEDED')") && str_contains($forgotJs, "code.includes('FORGOT_SESSION')") && str_contains($forgotJs, "code.includes('RESET_TOKEN')"), 'Forgot identity/session/reset-token error recovery is missing');
auth_ui_expect(str_contains($forgotJs, 'window.visualViewport') && str_contains($forgotJs, 'ensureControlVisible') && str_contains($forgotCss, '--forgot-keyboard-inset'), 'Forgot keyboard viewport handling is missing');
auth_ui_expect(!str_contains($forgotJs, 'localStorage') && !str_contains($forgotJs, 'console.log'), 'Forgot must not store/log reset secrets');
auth_ui_expect(str_contains($forgotCss, '.user-forgot-page .forgot-card') && !str_contains($forgotCss, "\n.card{"), 'Forgot CSS must remain page scoped');

auth_ui_expect(str_contains($loginCss, 'height: 100dvh') && str_contains($registerCss, 'height: 100dvh') && str_contains($forgotCss, 'height: 100dvh'), 'Auth pages must use stable mobile viewport sizing');
auth_ui_expect(str_contains($loginJs, "window.addEventListener('popstate'") && str_contains($registerJs, "window.addEventListener('popstate'") && str_contains($forgotJs, "window.addEventListener('popstate'"), 'Auth step Back/history handling is incomplete');
auth_ui_expect(str_contains($loginCss, '@media (prefers-reduced-motion: reduce)') && str_contains($registerCss, '@media (prefers-reduced-motion: reduce)') && str_contains($forgotCss, '@media (prefers-reduced-motion: reduce)'), 'Auth reduced-motion support is incomplete');

echo "User auth UI tests passed ({$assertions} assertions).\n";
