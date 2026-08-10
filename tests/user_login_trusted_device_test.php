<?php
declare(strict_types=1);

const SESSION_TTL_SECONDS = 3600;

$testNow = 1700000000;
$store = [];
$readPaths = [];
$writePaths = [];

function trusted_test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function trusted_test_get(string $path)
{
    global $store;
    $node = $store;
    foreach (trusted_test_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function trusted_test_set(string $path, $value): void
{
    global $store;
    $parts = trusted_test_parts($path);
    $node =& $store;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
}

function fb_get(string $path, array $query = [])
{
    global $readPaths;
    $readPaths[] = $path;
    return trusted_test_get($path);
}

function fb_patch(string $path, array $data): bool
{
    global $writePaths;
    $writePaths[] = $path;
    $current = trusted_test_get($path);
    trusted_test_set($path, array_merge(is_array($current) ? $current : [], $data));
    return true;
}

function fb_put(string $path, $data): bool
{
    trusted_test_set($path, $data);
    return true;
}

function fb_delete(string $path): bool
{
    trusted_test_set($path, null);
    return true;
}

function now_ts(): int
{
    global $testNow;
    return $testNow;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function make_session_id(): string
{
    return 'TEST_SESSION';
}

function client_ip(): string
{
    return '127.0.0.1';
}

function api_get_header(string $name): ?string
{
    return null;
}

require_once dirname(__DIR__) . '/api/lib/auth.php';

function trusted_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$uid = 'U-TRUSTED';
$deviceId = 'USER_WEB';
$selector = 'ABCDEF0123456789';
$token = str_repeat('a', 48);
$cookie = $selector . ':' . $token;
$epoch = 'SE_CURRENT';

trusted_test_set('USERS/' . $uid, [
    'uid' => $uid,
    'status' => 'ACTIVE',
    'role' => 'USER',
    'active_device_id' => $deviceId,
    'auth_session_epoch' => $epoch,
]);
trusted_test_set('AUTH_DEVICE_TRUST/' . $uid . '/' . $deviceId, [
    'uid' => $uid,
    'device_id' => $deviceId,
    'trusted' => true,
    'otp_verified' => true,
    'manual_logout' => false,
    'revoked' => false,
    'status' => 'ACTIVE',
]);
trusted_test_set('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, [
    'uid' => $uid,
    'device_id' => $deviceId,
    'token_hash' => hash('sha256', $token),
    'auth_session_epoch' => $epoch,
    'trusted' => true,
    'otp_verified' => true,
    'manual_logout' => false,
    'revoked' => false,
    'status' => 'ACTIVE',
    'expires_at' => now_ts() + 3600,
]);

$readPaths = [];
$valid = auth_trusted_browser_cookie_context($uid, $cookie, $deviceId);
trusted_expect(!empty($valid['ok']), 'valid HttpOnly selector/token must allow trusted PIN login');
trusted_expect(($valid['selector_hash'] ?? '') === hash('sha256', $selector), 'pre-auth must bind the trusted selector');
trusted_expect(!in_array('AUTH_TRUSTED_DEVICES', $readPaths, true), 'trusted login must not scan the global trusted-device node');
trusted_expect(in_array('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, $readPaths, true), 'trusted login must use a direct selector path');

$wrongToken = auth_trusted_browser_cookie_context($uid, $selector . ':' . str_repeat('b', 48), $deviceId, [], false);
trusted_expect(empty($wrongToken['ok']), 'wrong browser token must be rejected');

trusted_test_set('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector . '/auth_session_epoch', 'SE_OLD');
$wrongEpoch = auth_trusted_browser_cookie_context($uid, $cookie, $deviceId, [], false);
trusted_expect(empty($wrongEpoch['ok']) && ($wrongEpoch['code'] ?? '') === 'TRUSTED_DEVICE_EXPIRED', 'session epoch change must invalidate trusted login');

trusted_test_set('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector . '/auth_session_epoch', $epoch);
trusted_test_set('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector . '/expires_at', now_ts() - 1);
$expired = auth_trusted_browser_cookie_context($uid, $cookie, $deviceId, [], false);
trusted_expect(empty($expired['ok']), 'expired trusted token must be rejected');

trusted_test_set('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector . '/expires_at', now_ts() + 3600);
auth_mark_manual_logout($uid, $deviceId);
$loggedOut = auth_trusted_browser_cookie_context($uid, $cookie, $deviceId, [], false);
trusted_expect(empty($loggedOut['ok']), 'manual logout must revoke trusted PIN login');

echo "Trusted Web login tests passed.\n";
