<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

define('SESSION_TTL_SECONDS', 60 * 60 * 24 * 7);

$authSlidingNow = 1792972800;
$authSlidingToken = 'active-session-token';
$authSlidingHash = hash('sha256', $authSlidingToken);
$authSlidingPatches = [];
$authSlidingAssertions = 0;
$authSlidingDb = [
    'USER_SESSIONS' => [
        $authSlidingHash => [
            'uid' => 'USER_ACTIVE',
            'status' => 'ACTIVE',
            'expires_at' => $authSlidingNow + 60,
            'auth_session_epoch' => 'epoch-active',
        ],
    ],
    'USERS' => [
        'USER_ACTIVE' => [
            'status' => 'ACTIVE',
            'account_status' => 'ACTIVE',
            'role' => 'USER',
            'auth_session_epoch' => 'epoch-active',
        ],
    ],
    'APP_CONFIG' => [
        'maintenance_mode' => false,
    ],
];

function auth_sliding_expect(bool $condition, string $message): void
{
    global $authSlidingAssertions;
    $authSlidingAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function auth_sliding_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function api_get_header(string $name): ?string
{
    global $authSlidingToken;
    return strcasecmp($name, 'X-SESSION-TOKEN') === 0 ? $authSlidingToken : null;
}

function now_ts(): int
{
    global $authSlidingNow;
    return $authSlidingNow;
}

function fb_get(string $path, array $query = [])
{
    global $authSlidingDb;
    $value = $authSlidingDb;
    foreach (auth_sliding_parts($path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function fb_patch(string $path, array $patch): bool
{
    global $authSlidingDb, $authSlidingPatches;
    $authSlidingPatches[] = ['path' => $path, 'patch' => $patch];
    $parts = auth_sliding_parts($path);
    $node =& $authSlidingDb;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = array_merge($node, $patch);
    return true;
}

function api_response(bool $ok, string $code, string $message, array $data = [], int $status = 200): void
{
    throw new RuntimeException($code . ':' . $status);
}

require_once dirname(__DIR__) . '/api/lib/auth.php';

$auth = auth_require_user(true);
auth_sliding_expect(($auth['user']['uid'] ?? '') === 'USER_ACTIVE', 'Active session did not resolve the user.');
auth_sliding_expect(count($authSlidingPatches) === 1, 'Active session touch should perform one patch.');
$touchPatch = (array)($authSlidingPatches[0]['patch'] ?? []);
auth_sliding_expect(($touchPatch['last_seen_at'] ?? 0) === $authSlidingNow, 'Session last_seen_at was not touched.');
auth_sliding_expect(($touchPatch['updated_at'] ?? 0) === $authSlidingNow, 'Session updated_at was not touched.');
auth_sliding_expect(
    ($touchPatch['expires_at'] ?? 0) === $authSlidingNow + SESSION_TTL_SECONDS,
    'Active session expiry was not renewed.'
);

$authSlidingPatches = [];
auth_require_user(false);
auth_sliding_expect($authSlidingPatches === [], 'Non-touch authentication unexpectedly mutated the session.');

$adminMobileExpiry = $authSlidingNow + 7200;
$authSlidingDb['USER_SESSIONS'][$authSlidingHash]['channel'] = 'ADMIN_MOBILE';
$authSlidingDb['USER_SESSIONS'][$authSlidingHash]['expires_at'] = $adminMobileExpiry;
$authSlidingPatches = [];
auth_require_user(true);
$adminMobilePatch = (array)($authSlidingPatches[0]['patch'] ?? []);
auth_sliding_expect(
    ($adminMobilePatch['last_seen_at'] ?? 0) === $authSlidingNow,
    'Admin mobile session was not touched.'
);
auth_sliding_expect(
    !isset($adminMobilePatch['expires_at']),
    'Admin mobile session incorrectly inherited the general user TTL.'
);
unset($authSlidingDb['USER_SESSIONS'][$authSlidingHash]['channel']);

$longExpiry = $authSlidingNow + (SESSION_TTL_SECONDS * 2);
$authSlidingDb['USER_SESSIONS'][$authSlidingHash]['expires_at'] = $longExpiry;
$authSlidingPatches = [];
auth_require_user(true);
auth_sliding_expect(
    ($authSlidingPatches[0]['patch']['expires_at'] ?? 0) === $longExpiry,
    'Touch shortened an existing longer session lifetime.'
);

$authSlidingDb['USER_SESSIONS'][$authSlidingHash]['expires_at'] = $authSlidingNow - 1;
$authSlidingDb['USER_SESSIONS'][$authSlidingHash]['status'] = 'ACTIVE';
$authSlidingPatches = [];
$expiredRejected = false;
try {
    auth_require_user(true);
} catch (RuntimeException $exception) {
    $expiredRejected = str_starts_with($exception->getMessage(), 'SESSION_EXPIRED:401');
}
auth_sliding_expect($expiredRejected, 'Expired session was not rejected.');
auth_sliding_expect(
    ($authSlidingPatches[0]['patch']['status'] ?? '') === 'EXPIRED',
    'Expired session was not marked expired.'
);
auth_sliding_expect(
    !isset($authSlidingPatches[0]['patch']['expires_at']),
    'Expired session was incorrectly renewed.'
);

fwrite(STDOUT, "PASS: {$authSlidingAssertions} sliding session assertions\n");
