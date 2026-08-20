<?php
declare(strict_types=1);

define('ADMIN_LOGIN_MAX_FAILED_ATTEMPTS', 5);
define('ADMIN_LOGIN_ATTEMPT_WINDOW_SECONDS', 900);
define('ADMIN_LOGIN_LOCK_SECONDS', 900);

$throttleStore = [];
$throttleVersions = [];
$throttleFailNextRead = false;
$throttleInjectConcurrentFailure = false;
$throttleAssertions = 0;

function throttle_expect(bool $condition, string $message): void
{
    global $throttleAssertions;
    $throttleAssertions++;
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
    return 'synthetic-admin-throttle-test-key';
}

function now_ts(): int
{
    return 1700000000;
}

function throttle_etag(string $path): string
{
    global $throttleVersions;
    return '"v' . (int)($throttleVersions[$path] ?? 0) . '"';
}

function fb_get_with_etag(string $path): array
{
    global $throttleStore, $throttleFailNextRead;
    if ($throttleFailNextRead) {
        $throttleFailNextRead = false;
        return ['ok' => false, 'status' => 503, 'etag' => null, 'value' => null];
    }

    return [
        'ok' => true,
        'status' => 200,
        'etag' => throttle_etag($path),
        'value' => $throttleStore[$path] ?? null,
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $throttleStore, $throttleVersions, $throttleInjectConcurrentFailure;

    if ($throttleInjectConcurrentFailure) {
        $throttleInjectConcurrentFailure = false;
        $current = is_array($throttleStore[$path] ?? null) ? $throttleStore[$path] : [];
        $current['failed_attempts'] = (int)($current['failed_attempts'] ?? 0) + 1;
        $current['revision'] = (int)($current['revision'] ?? 0) + 1;
        if ($current['failed_attempts'] >= ADMIN_LOGIN_MAX_FAILED_ATTEMPTS) {
            $current['locked_until'] = (int)($current['last_failed_at'] ?? now_ts()) + ADMIN_LOGIN_LOCK_SECONDS;
        }
        $throttleStore[$path] = $current;
        $throttleVersions[$path] = (int)($throttleVersions[$path] ?? 0) + 1;
    }

    if (!hash_equals(throttle_etag($path), $etag)) {
        return ['ok' => false, 'status' => 412];
    }

    $throttleStore[$path] = $data;
    $throttleVersions[$path] = (int)($throttleVersions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

function fb_delete_if_match(string $path, string $etag): array
{
    global $throttleStore, $throttleVersions;
    if (!hash_equals(throttle_etag($path), $etag)) {
        return ['ok' => false, 'status' => 412];
    }

    unset($throttleStore[$path]);
    $throttleVersions[$path] = (int)($throttleVersions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

require_once dirname(__DIR__) . '/api/lib/auth.php';

$base = 1700000000;
$phone = '60123456789';
$path = auth_admin_login_attempt_path('MY', $phone);

throttle_expect(auth_admin_login_max_failed_attempts() === 5, 'Admin password threshold must be five failures.');
throttle_expect(auth_admin_login_attempt_window_seconds() === 900, 'Admin password window must be 15 minutes.');
throttle_expect(auth_admin_login_lock_seconds() === 900, 'Admin password lock must be 15 minutes.');
throttle_expect(!str_contains($path, $phone), 'Limiter path must not expose the normalized phone.');

$initial = auth_admin_login_attempt_state('MY', $phone, $base);
throttle_expect(!empty($initial['ok']) && empty($initial['blocked']), 'A fresh identity must be allowed.');

$first = auth_admin_login_record_failed_password('MY', $phone, $base);
throttle_expect(!empty($first['ok']) && empty($first['blocked']), 'One wrong password must remain INVALID_CREDENTIALS eligible.');
throttle_expect((int)($first['failed_attempts'] ?? 0) === 1, 'First wrong password must increment the limiter.');

for ($i = 2; $i <= 4; $i++) {
    $result = auth_admin_login_record_failed_password('MY', $phone, $base + $i);
    throttle_expect(!empty($result['ok']) && empty($result['blocked']), "Failure {$i} must remain below threshold.");
}

$threshold = auth_admin_login_record_failed_password('MY', $phone, $base + 5);
throttle_expect(!empty($threshold['blocked']), 'The fifth wrong password must lock the identity.');
throttle_expect((int)($threshold['retry_after_seconds'] ?? 0) > 0, 'Locked response must include a positive retry delay.');

$blocked = auth_admin_login_attempt_state('MY', $phone, $base + 6);
throttle_expect(!empty($blocked['blocked']), 'Pre-check must reject a currently locked identity.');

$afterExpiry = auth_admin_login_attempt_state('MY', $phone, $base + 906);
throttle_expect(!empty($afterExpiry['ok']) && empty($afterExpiry['blocked']), 'Lock must expire automatically by timestamp.');
throttle_expect((int)$afterExpiry['failed_attempts'] === 0, 'Expired limiter state must restart at zero.');
throttle_expect(password_verify('654321', password_hash('654321', PASSWORD_DEFAULT)), 'A correct Admin password remains verifiable after expiry.');

$resetAfterExpiry = auth_admin_login_reset_failed_passwords('MY', $phone, $afterExpiry);
throttle_expect(!empty($resetAfterExpiry['ok']), 'Correct password must clear expired failed-attempt state.');

$freshFailure = auth_admin_login_record_failed_password('MY', $phone, $base + 907);
throttle_expect((int)($freshFailure['failed_attempts'] ?? 0) === 1, 'A wrong password after success must start a fresh sequence.');

$resetIdentity = '60115550000';
auth_admin_login_record_failed_password('MY', $resetIdentity, $base);
auth_admin_login_record_failed_password('MY', $resetIdentity, $base + 1);
$resetPrecheck = auth_admin_login_attempt_state('MY', $resetIdentity, $base + 2);
$reset = auth_admin_login_reset_failed_passwords('MY', $resetIdentity, $resetPrecheck);
throttle_expect(!empty($reset['ok']) && !empty($reset['cleared']), 'Correct password must remove prior failure state.');
$afterResetFailure = auth_admin_login_record_failed_password('MY', $resetIdentity, $base + 3);
throttle_expect((int)($afterResetFailure['failed_attempts'] ?? 0) === 1, 'A post-reset failure must be isolated.');

$racePhone = '60117770000';
for ($i = 0; $i < 4; $i++) {
    auth_admin_login_record_failed_password('MY', $racePhone, $base + $i);
}
$throttleInjectConcurrentFailure = true;
$race = auth_admin_login_record_failed_password('MY', $racePhone, $base + 4);
$racePath = auth_admin_login_attempt_path('MY', $racePhone);
throttle_expect(!empty($race['blocked']), 'A concurrent fifth failure must not bypass the lock.');
throttle_expect((int)($throttleStore[$racePath]['failed_attempts'] ?? 0) === 5, 'CAS conflict must preserve the winning failure count.');

$unknownPhone = '60119999999';
for ($i = 0; $i < 5; $i++) {
    $unknown = auth_admin_login_record_failed_password('MY', $unknownPhone, $base + $i);
}
throttle_expect(!empty($unknown['blocked']), 'An unknown normalized login identity must also be abuse-bounded.');

$storedJson = json_encode($throttleStore, JSON_UNESCAPED_SLASHES);
throttle_expect(is_string($storedJson) && !str_contains($storedJson, $unknownPhone), 'Limiter rows must not store raw login identities.');
$storedKeys = [];
$collectKeys = static function (array $value) use (&$collectKeys, &$storedKeys): void {
    foreach ($value as $key => $item) {
        $storedKeys[] = strtolower((string)$key);
        if (is_array($item)) {
            $collectKeys($item);
        }
    }
};
$collectKeys($throttleStore);
foreach (['password', 'password_hash', 'otp', 'session_token', 'trusted_device_token'] as $forbidden) {
    throttle_expect(!in_array($forbidden, $storedKeys, true), "Limiter rows must not store {$forbidden} fields.");
}
throttle_expect(!str_contains((string)$storedJson, '654321'), 'Limiter rows must not contain raw password values.');

$throttleFailNextRead = true;
$storageFailure = auth_admin_login_attempt_state('MY', '60118880000', $base);
throttle_expect(empty($storageFailure['ok']), 'Limiter storage failure must fail closed.');

$endpoint = (string)file_get_contents(dirname(__DIR__) . '/api/auth/admin_login_start.php');
$config = (string)file_get_contents(dirname(__DIR__) . '/api/config.example.php');
$precheckPos = strpos($endpoint, '$passwordAttemptState = auth_admin_login_attempt_state');
$lookupPos = strpos($endpoint, '$uid = admin_login_find_uid_by_phone');
$verifyPos = strpos($endpoint, 'password_verify($password, $passwordHash)');
$resetPos = strpos($endpoint, 'auth_admin_login_reset_failed_passwords');
$trustedPos = strpos($endpoint, 'if (admin_login_has_valid_trusted_device');
$otpRatePos = strpos($endpoint, "auth_otp_send_rate_state('ADMIN_LOGIN'");

throttle_expect($precheckPos !== false && $lookupPos !== false && $precheckPos < $lookupPos, 'Limiter pre-check must run before account lookup.');
throttle_expect($verifyPos !== false && $precheckPos < $verifyPos, 'Blocked requests must be rejected before password_verify.');
throttle_expect($resetPos !== false && $verifyPos < $resetPos, 'Successful password verification must reset the limiter.');
throttle_expect($trustedPos !== false && $resetPos < $trustedPos, 'Trusted-device login must continue only after successful limiter reset.');
throttle_expect($otpRatePos !== false && $trustedPos < $otpRatePos, 'Untrusted Admin login must still reach the existing OTP limiter.');
throttle_expect(substr_count($endpoint, "auth_otp_send_rate_state('ADMIN_LOGIN'") === 1, 'Existing Admin OTP send limiter must remain unchanged.');
throttle_expect(str_contains($endpoint, "if (\$status !== 'ACTIVE')") && str_contains($endpoint, "if (\$role !== 'ADMIN')"), 'Inactive and non-Admin roles must remain denied.');
throttle_expect(str_contains($endpoint, "'ADMIN_LOGIN_RATE_LIMITED'") && str_contains($endpoint, "'retry_after_seconds'"), 'Threshold response must use stable 429 metadata.');
throttle_expect(
    preg_match("/'ADMIN_LOGIN_RATE_LIMITED'.*?\],\s*429\);/s", $endpoint) === 1,
    'Threshold response must return HTTP 429.'
);
throttle_expect(!str_contains($endpoint, 'getMessage()'), 'Admin login must not expose raw internal limiter errors.');
foreach (['ADMIN_LOGIN_MAX_FAILED_ATTEMPTS', 'ADMIN_LOGIN_ATTEMPT_WINDOW_SECONDS', 'ADMIN_LOGIN_LOCK_SECONDS'] as $constant) {
    throttle_expect(str_contains($config, $constant), "Example config must document {$constant}.");
}

echo "Admin login password throttle tests passed ({$throttleAssertions} assertions).\n";
