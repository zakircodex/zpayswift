<?php
declare(strict_types=1);

define('USER_LOGIN_ACCOUNT_LOOKUP_MAX_ATTEMPTS', 30);
define('USER_LOGIN_PASSWORD_MAX_ATTEMPTS', 5);
define('USER_LOGIN_PIN_MAX_ATTEMPTS', 5);
define('USER_LOGIN_TRANSACTION_PIN_MAX_ATTEMPTS', 5);

$securityStore = [];
$securityVersions = [];
$securityAssertions = 0;

function security_hardening_expect(bool $condition, string $message): void
{
    global $securityAssertions;
    $securityAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function auth_normalize_country_code(string $country): string
{
    $country = strtoupper(trim($country));
    return in_array($country, ['BD', 'MY'], true) ? $country : '';
}

function security_secret_for_hash(): string
{
    return 'synthetic-user-login-security-test-key';
}

function now_ts(): int
{
    return 1800000000;
}

function security_hardening_etag(string $path): string
{
    global $securityVersions;
    return '"v' . (int)($securityVersions[$path] ?? 0) . '"';
}

function fb_get_with_etag(string $path): array
{
    global $securityStore;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => security_hardening_etag($path),
        'value' => $securityStore[$path] ?? null,
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $securityStore, $securityVersions;
    if (!hash_equals(security_hardening_etag($path), $etag)) {
        return ['ok' => false, 'status' => 412];
    }
    $securityStore[$path] = $data;
    $securityVersions[$path] = (int)($securityVersions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

function fb_delete_if_match(string $path, string $etag): array
{
    global $securityStore, $securityVersions;
    if (!hash_equals(security_hardening_etag($path), $etag)) {
        return ['ok' => false, 'status' => 412];
    }
    unset($securityStore[$path]);
    $securityVersions[$path] = (int)($securityVersions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

require_once dirname(__DIR__) . '/api/lib/auth.php';

$identity = auth_user_login_phone_identity('MY', '60 1234 56789');
$passwordPath = auth_user_login_limit_path('PASSWORD', $identity);
security_hardening_expect(!str_contains($passwordPath, '60123456789'), 'Limiter path must not expose a phone number.');
security_hardening_expect(auth_user_login_limit_policy('PASSWORD')['max_attempts'] === 5, 'Password limit must remain five attempts.');
security_hardening_expect(auth_user_login_limit_policy('ACCOUNT_LOOKUP')['max_attempts'] === 30, 'Lookup limit must remain bounded at thirty attempts.');

for ($i = 1; $i <= 5; $i++) {
    $passwordAttempt = auth_user_login_record_attempt('PASSWORD', $identity, now_ts() + $i);
}
security_hardening_expect(!empty($passwordAttempt['blocked']), 'The fifth password failure must lock the identity.');
$pinState = auth_user_login_limit_state('PIN', 'USER-1', now_ts() + 6);
security_hardening_expect(!empty($pinState['ok']) && empty($pinState['blocked']), 'Password and PIN scopes must remain isolated.');

auth_user_login_record_attempt('PIN', 'USER-1', now_ts() + 7);
$pinPrecheck = auth_user_login_limit_state('PIN', 'USER-1', now_ts() + 8);
$pinReset = auth_user_login_reset_attempts('PIN', 'USER-1', $pinPrecheck);
security_hardening_expect(!empty($pinReset['ok']) && !empty($pinReset['cleared']), 'A correct PIN must clear prior failures.');

for ($i = 1; $i <= 5; $i++) {
    $transactionAttempt = auth_user_login_record_attempt('TRANSACTION_PIN', 'USER-2', now_ts() + $i);
}
security_hardening_expect(!empty($transactionAttempt['blocked']), 'Transaction PIN attempts must be bounded independently.');

$storedJson = json_encode($securityStore, JSON_UNESCAPED_SLASHES);
security_hardening_expect(is_string($storedJson) && !str_contains($storedJson, '60123456789'), 'Limiter storage must contain hashes, not raw identities.');
$storedKeys = [];
$collectStoredKeys = static function (array $value) use (&$collectStoredKeys, &$storedKeys): void {
    foreach ($value as $key => $item) {
        $storedKeys[] = strtolower((string)$key);
        if (is_array($item)) {
            $collectStoredKeys($item);
        }
    }
};
$collectStoredKeys($securityStore);
foreach (['password', 'pin', 'otp', 'session_token', 'trusted_device_token'] as $secretField) {
    security_hardening_expect(!in_array($secretField, $storedKeys, true), "Limiter storage must not contain {$secretField} fields.");
}

$root = dirname(__DIR__);
$verifyPassword = (string)file_get_contents($root . '/api/auth/verify_password.php');
$verifyPin = (string)file_get_contents($root . '/api/auth/verify_pin.php');
$checkNumber = (string)file_get_contents($root . '/api/auth/check_number.php');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$topupJs = (string)file_get_contents($root . '/api/user/assets/pages/topup-page.js');
$bundleJs = (string)file_get_contents($root . '/api/user/assets/pages/bundle-page.js');
$profileJs = (string)file_get_contents($root . '/api/user/assets/pages/profile-page.js');
$changePassword = (string)file_get_contents($root . '/api/user/change_password.php');
$changePin = (string)file_get_contents($root . '/api/user/change_pin.php');
$authAndroid = (string)file_get_contents($root . '/api/lib/auth_android.php');
$security = (string)file_get_contents($root . '/api/lib/security.php');
$phoneCountry = (string)file_get_contents($root . '/api/lib/phone_country.php');
$config = (string)file_get_contents($root . '/api/config.example.php');

$passwordPrecheck = strpos($verifyPassword, "auth_user_login_limit_state('PASSWORD'");
$passwordLookup = strpos($verifyPassword, 'auth_app_lookup_user_result($body, false)');
$passwordVerify = strpos($verifyPassword, 'auth_app_password_ok($user, $password)');
$passwordGuard = strpos($verifyPassword, 'auth_app_guard_user_login($user)');
$passwordReset = strpos($verifyPassword, "auth_user_login_reset_attempts('PASSWORD'");
security_hardening_expect($passwordPrecheck !== false && $passwordLookup !== false && $passwordPrecheck < $passwordLookup, 'Password limiter must run before account lookup.');
security_hardening_expect(str_contains($verifyPassword, 'auth_app_lookup_user_result($body, false)') && strpos($verifyPassword, "fb_get('USER_WALLETS/' . \$uid)") > $passwordVerify, 'Wallet pricing lookup must wait until after password verification.');
security_hardening_expect($passwordVerify !== false && $passwordReset !== false && $passwordVerify < $passwordReset, 'Successful password verification must reset its limiter.');
security_hardening_expect(str_contains($verifyPassword, "auth_user_login_record_attempt('PASSWORD'"), 'Unknown and wrong password attempts must be recorded.');
security_hardening_expect(str_contains($verifyPassword, 'auth_app_dummy_password_verify($password)') && substr_count($verifyPassword, "api_response(false, 'WRONG_PASSWORD'") === 2, 'Unknown accounts must use the same timing and response as a wrong password.');
security_hardening_expect($passwordVerify !== false && $passwordGuard !== false && $passwordVerify < $passwordGuard, 'Account status must be checked only after the password is valid.');
security_hardening_expect($passwordReset !== false && $passwordGuard !== false && $passwordReset < $passwordGuard, 'A valid password must clear old failures before applying account-status policy.');

security_hardening_expect(str_contains($verifyPin, "auth_user_login_limit_state('PIN'") && str_contains($verifyPin, "auth_user_login_record_attempt('PIN'"), 'Login PIN must use the bounded limiter.');
security_hardening_expect(str_contains($verifyPin, "auth_user_login_limit_state('TRANSACTION_PIN'") && str_contains($verifyPin, "auth_user_login_record_attempt('TRANSACTION_PIN'"), 'API transaction PIN must use an independent limiter.');

$lookupLimit = strpos($checkNumber, "auth_user_login_limit_state('ACCOUNT_LOOKUP'");
$accountLookup = strpos($checkNumber, 'auth_app_lookup_user_result($body, false)');
security_hardening_expect($lookupLimit !== false && $accountLookup !== false && $lookupLimit < $accountLookup, 'Account lookup must be rate-limited before Firebase reads.');
security_hardening_expect(str_contains($checkNumber, 'auth_app_lookup_user_result($body, false)'), 'Ordinary phone checks must not read wallet pricing data.');
$normalAccountResponse = substr($checkNumber, (int)strpos($checkNumber, "api_response(true, 'ACCOUNT_FOUND'"));
security_hardening_expect(!str_contains($normalAccountResponse, "'kyc_status'") && !str_contains($normalAccountResponse, "'account_status'") && !str_contains($normalAccountResponse, "'name' =>") && !str_contains($normalAccountResponse, "'masked_name'"), 'Unauthenticated account lookup must not expose private account fields.');
security_hardening_expect(str_contains($checkNumber, "!empty(\$account['ok']) ? (string)\$account['uid'] : ''") && !str_contains($normalAccountResponse, "\$account['uid']"), 'Known and unknown phone lookups must share the same public response shape.');

$legacyStart = strpos($proxy, "case 'login':");
$legacyEnd = strpos($proxy, "case 'login_verify_otp':", $legacyStart === false ? 0 : $legacyStart);
$legacyBlock = $legacyStart === false || $legacyEnd === false ? '' : substr($proxy, $legacyStart, $legacyEnd - $legacyStart);
security_hardening_expect(str_contains($legacyBlock, 'LOGIN_FLOW_UPGRADE_REQUIRED') && !str_contains($legacyBlock, 'user_login_start.php'), 'Legacy user login must not bypass the PIN stage.');

security_hardening_expect(str_contains($proxy, 'function user_proxy_issue_transaction_pin_proof') && str_contains($proxy, 'function user_proxy_consume_transaction_pin_proof'), 'Web transaction PIN proof helpers are missing.');
security_hardening_expect(substr_count($proxy, 'user_proxy_consume_transaction_pin_proof($uid') === 2, 'Top-Up and Bundle previews must each consume one PIN proof.');
security_hardening_expect(str_contains($topupJs, "purpose: 'TOPUP', issue_proof: true") && str_contains($bundleJs, "purpose: 'BUNDLE', issue_proof: true"), 'Current Top-Up and Bundle pages must request purpose-bound proof.');
security_hardening_expect(str_contains($proxy, "unset(\$body['pin'], \$body['transaction_pin'], \$body['issue_proof'])"), 'PIN material must not be forwarded into financial preview payloads.');

foreach ([$changePassword, $changePin] as $credentialEndpoint) {
    security_hardening_expect(str_contains($credentialEndpoint, "'auth_session_epoch' => \$sessionEpoch") && str_contains($credentialEndpoint, 'auth_app_revoke_user_trust_records($uid, $now)'), 'Credential change must revoke sessions and trusted devices.');
}
security_hardening_expect(str_contains($proxy, 'function user_proxy_forward_credential_change') && str_contains($proxy, 'user_proxy_clear_trust_cookie()'), 'Web credential changes must clear the local session and trust cookie.');
security_hardening_expect(str_contains($authAndroid, 'function auth_app_revoke_user_trust_records'), 'Shared trusted-device revocation helper is missing.');
security_hardening_expect(str_contains($profileJs, "buttonLabel: 'Sign In'") && str_contains($profileJs, "window.location.replace('/user/')"), 'Credential-change UI must require a fresh sign-in.');

security_hardening_expect(str_contains($security, "function_exists('market_request_ip')") && !str_contains($security, 'HTTP_X_FORWARDED_FOR') && !str_contains($security, 'HTTP_X_REAL_IP'), 'Client IP must ignore unverified proxy headers.');
security_hardening_expect(str_contains($phoneCountry, "function_exists('market_trusted_forwarded_ip')") && !str_contains($phoneCountry, "\$body['client_ip']"), 'Auth metadata must accept only signed forwarded IP values.');

foreach (['USER_LOGIN_ACCOUNT_LOOKUP_MAX_ATTEMPTS', 'USER_LOGIN_PASSWORD_MAX_ATTEMPTS', 'USER_LOGIN_PIN_MAX_ATTEMPTS', 'USER_LOGIN_TRANSACTION_PIN_MAX_ATTEMPTS'] as $constant) {
    security_hardening_expect(str_contains($config, $constant), "Example config must document {$constant}.");
}

echo "User production security hardening tests passed ({$securityAssertions} assertions).\n";
