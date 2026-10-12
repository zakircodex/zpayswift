<?php
declare(strict_types=1);

const SESSION_TTL_SECONDS = 3600;
$store = [];
$versions = [];
$failSnapshot = false;
$failSessionWrite = false;
$failTrustWrite = false;
$failCookieWrite = false;
$failCleanup = false;
$conflict = false;
$requestToken = '';
$assertions = 0;

function expect(bool $value, string $message): void {
    global $assertions;
    $assertions++;
    if (!$value) { throw new RuntimeException($message); }
}
function now_ts(): int { return 1800000000; }
function client_ip(): string { return '127.0.0.1'; }
function random_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function make_session_id(): string { return 'S' . random_token(8); }
function api_get_header(string $name): ?string {
    return strtolower($name) === 'authorization' ? 'Bearer ' . $GLOBALS['requestToken'] : null;
}
function api_response(bool $ok, string $code, string $message, array $data = [], int $status = 200): void {
    throw new RuntimeException($code, $status);
}
function fb_get(string $path, array $query = []): mixed {
    global $store;
    if (array_key_exists($path, $store)) { return $store[$path]; }
    $result = [];
    foreach ($store as $key => $value) {
        if (str_starts_with($key, $path . '/')) {
            $suffix = substr($key, strlen($path) + 1);
            if (!str_contains($suffix, '/') && $value !== null) { $result[$suffix] = $value; }
        }
    }
    return $result ?: null;
}
function fb_put(string $path, mixed $value): bool {
    global $store, $versions;
    if (($GLOBALS['failSessionWrite'] && str_starts_with($path, 'USER_SESSIONS/'))
        || ($GLOBALS['failCookieWrite'] && str_starts_with($path, 'AUTH_TRUSTED_DEVICES/'))) { return false; }
    $store[$path] = $value;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
    return true;
}
function fb_patch(string $path, array $value): bool {
    if (($GLOBALS['failTrustWrite'] && str_starts_with($path, 'AUTH_DEVICE_TRUST/'))
        || ($GLOBALS['failCleanup'] && (($value['status'] ?? '') === 'RESET_REVOKED'
            || ($value['status'] ?? '') === 'REVOKED'))) { return false; }
    return fb_put($path, array_replace((array)fb_get($path), $value));
}
function fb_delete(string $path): bool {
    unset($GLOBALS['store'][$path]);
    return true;
}
function fb_get_with_etag(string $path): array {
    return ['ok' => !$GLOBALS['failSnapshot'], 'value' => fb_get($path),
        'etag' => 'v' . (int)($GLOBALS['versions'][$path] ?? 0)];
}
function fb_put_if_match(string $path, mixed $value, string $etag): array {
    if ($GLOBALS['conflict']) {
        $GLOBALS['store'][$path]['wallet_balance'] = 123.45;
        $GLOBALS['versions'][$path] = (int)($GLOBALS['versions'][$path] ?? 0) + 1;
    }
    if ($etag !== 'v' . (int)($GLOBALS['versions'][$path] ?? 0)) { return ['ok' => false, 'status' => 412]; }
    return ['ok' => fb_put($path, $value), 'status' => 200];
}

require_once dirname(__DIR__) . '/api/lib/auth.php';
require_once dirname(__DIR__) . '/api/lib/auth_android.php';
require_once dirname(__DIR__) . '/api/lib/auth_profile_security.php';

function fixture(): array {
    global $store, $versions;
    foreach (['failSnapshot', 'failSessionWrite', 'failTrustWrite', 'failCookieWrite', 'failCleanup', 'conflict'] as $flag) {
        $GLOBALS[$flag] = false;
    }
    $user = ['uid' => 'U1', 'phone' => '60100000000', 'status' => 'ACTIVE', 'account_status' => 'ACTIVE',
        'role' => 'USER', 'password_hash' => password_hash('old-passphrase', PASSWORD_DEFAULT),
        'pin_hash' => password_hash('1212', PASSWORD_DEFAULT), 'auth_session_epoch' => 'old-epoch',
        'active_device_id' => 'current-device'];
    $session = ['uid' => 'U1', 'device_id' => 'current-device', 'device_name' => 'Test Phone',
        'status' => 'ACTIVE', 'expires_at' => now_ts() + 3600, 'auth_session_epoch' => 'old-epoch'];
    $store = ['USERS/U1' => $user, 'USER_SESSIONS/' . session_hash('old-token') => $session,
        'USER_SESSIONS/' . session_hash('other-token') => $session,
        'AUTH_DEVICE_TRUST/U1/current-device' => ['device_id' => 'current-device', 'trusted' => true],
        'AUTH_DEVICE_TRUST/U1/other-device' => ['device_id' => 'other-device', 'trusted' => true],
        'AUTH_TRUSTED_DEVICES/U1/old-selector' => ['status' => 'ACTIVE', 'auth_session_epoch' => 'old-epoch']];
    $versions = [];
    return ['user' => $user, 'session' => $session, 'session_hash' => session_hash('old-token')];
}
function authenticated(string $token): array {
    $GLOBALS['requestToken'] = $token;
    return auth_require_user(false);
}
function expectExpired(string $token): void {
    try { authenticated($token); } catch (RuntimeException $error) {
        expect($error->getCode() === 401, 'Old token must fail closed with 401.');
        return;
    }
    expect(false, 'An old session remained active.');
}

foreach (['password_hash' => 'new-passphrase', 'pin_hash' => '3434'] as $field => $plain) {
    $auth = fixture();
    authenticated('old-token');
    $result = auth_app_change_profile_credential($auth, $field, password_hash($plain, PASSWORD_DEFAULT));
    expect(!empty($result['ok']), 'Credential change must succeed.');
    $data = $result['data'];
    expect($data['session_preserved'] && !$data['reauth_required'], 'Current device must stay signed in.');
    expect($data['session_token'] !== 'old-token', 'Session token must rotate.');
    expect(password_verify($plain, $store['USERS/U1'][$field]), 'New credential must be saved.');
    $unchanged = $field === 'pin_hash' ? 'password_hash' : 'pin_hash';
    expect($store['USERS/U1'][$unchanged] === $auth['user'][$unchanged], 'Other credential must remain unchanged.');
    expect(authenticated($data['session_token'])['user']['uid'] === 'U1', 'Replacement must pass real auth guard.');
    expectExpired('old-token');
    expectExpired('other-token');
    expect($store['AUTH_DEVICE_TRUST/U1/current-device']['trusted'], 'Current device trust must remain usable.');
    expect(!$store['AUTH_DEVICE_TRUST/U1/other-device']['trusted'], 'Other device trust must be revoked.');
    expect($store['AUTH_TRUSTED_DEVICES/U1/old-selector']['status'] === 'REVOKED', 'Old cookies must be revoked.');
    $cookie = $data['trusted_device_cookie'];
    $cookieRow = $store['AUTH_TRUSTED_DEVICES/U1/' . $cookie['selector']];
    expect(hash('sha256', $cookie['token']) === $cookieRow['token_hash'], 'Cookie secret must be hashed server-side.');
    expect($cookieRow['auth_session_epoch'] === $store['USERS/U1']['auth_session_epoch'], 'New cookie must use new epoch.');
    expect(!str_contains(json_encode($data), $plain), 'Response must never contain a password/PIN.');
}

foreach (['failSnapshot', 'failSessionWrite', 'failTrustWrite', 'conflict'] as $failure) {
    $auth = fixture();
    $oldUser = $store['USERS/U1'];
    $GLOBALS[$failure] = true;
    $result = auth_app_change_profile_credential($auth, 'pin_hash', password_hash('3434', PASSWORD_DEFAULT));
    expect(empty($result['ok']), 'Write failure must not report success.');
    expect($store['USERS/U1']['pin_hash'] === $oldUser['pin_hash'], 'Failed mutation must not change PIN.');
    expect($store['USERS/U1']['auth_session_epoch'] === 'old-epoch', 'Failed mutation must preserve old epoch.');
    expect(count((array)fb_get('USER_SESSIONS')) === 2, 'Prepared session must be cleaned up on failure.');
    expect(authenticated('old-token')['user']['uid'] === 'U1', 'Failed mutation must keep original session usable.');
    if ($failure === 'conflict') {
        expect($store['USERS/U1']['wallet_balance'] === 123.45, 'CAS must not overwrite concurrent balance changes.');
    }
}

$auth = fixture();
$store['USERS/U1']['active_device_id'] = 'different-device';
expect(empty(auth_app_change_profile_credential($auth, 'pin_hash', 'unused')['ok']), 'Replaced devices cannot rotate credentials.');
$auth = fixture();
$store['USERS/U1']['pin_hash'] = password_hash('9898', PASSWORD_DEFAULT);
expect(empty(auth_app_change_profile_credential($auth, 'pin_hash', 'unused')['ok']), 'Stale credential validation cannot authorize a change.');

$auth = fixture();
$failCookieWrite = true;
$result = auth_app_change_profile_credential($auth, 'pin_hash', password_hash('3434', PASSWORD_DEFAULT));
expect(!empty($result['ok']) && $result['data']['trusted_device_cookie'] === null, 'Optional cookie failure must not lose current login.');
expect(authenticated($result['data']['session_token'])['user']['uid'] === 'U1', 'Session must survive optional cookie failure.');

$auth = fixture();
$failCleanup = true;
$result = auth_app_change_profile_credential($auth, 'pin_hash', password_hash('3434', PASSWORD_DEFAULT));
expect(!empty($result['ok']), 'Cleanup failure must not undo committed credential change.');
expectExpired('old-token');
expectExpired('other-token');
expect(authenticated($result['data']['session_token'])['user']['uid'] === 'U1', 'New epoch must remain valid.');

echo "Profile credential session tests passed ({$assertions} assertions).\n";
