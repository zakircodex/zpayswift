<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

const SESSION_TTL_SECONDS = 3600;

$testNow = 1700000000;
$store = [];
$versions = [];
$getPaths = [];
$failUserPatch = false;
$ownerQueryRows = [];

function test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function test_get(string $path)
{
    global $store;
    $node = $store;
    foreach (test_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function test_set(string $path, $value): void
{
    global $store, $versions;
    $parts = test_parts($path);
    $node =& $store;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $versions[$path] = ($versions[$path] ?? 0) + 1;
}

function fb_get(string $path, array $query = [])
{
    global $getPaths;
    $getPaths[] = $path;
    return test_get($path);
}

function fb_put(string $path, $data): bool
{
    test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    global $failUserPatch;
    if ($failUserPatch && str_starts_with($path, 'USERS/')) {
        return false;
    }
    $current = test_get($path);
    test_set($path, array_merge(is_array($current) ? $current : [], $data));
    return true;
}

function fb_delete(string $path): bool
{
    test_set($path, null);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $versions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => 'v' . (string)($versions[$path] ?? 0),
        'value' => test_get($path),
    ];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $versions;
    if ($etag !== 'v' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function fb_request(
    string $method,
    string $path,
    mixed $data = null,
    array $query = [],
    array $headers = [],
    bool $includeHeaders = false
): array {
    global $ownerQueryRows;
    $field = json_decode((string)($query['orderBy'] ?? '""'), true);
    return [
        'ok' => strtoupper($method) === 'GET' && $path === 'USERS',
        'status' => 200,
        'json' => is_string($field) ? ($ownerQueryRows[$field] ?? null) : null,
    ];
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
    return 'TEST_SESSION_' . bin2hex(random_bytes(4));
}

function client_ip(): string
{
    return '127.0.0.1';
}

require_once dirname(__DIR__) . '/api/lib/auth.php';
require_once dirname(__DIR__) . '/api/lib/users_admin.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function seed_otp(string $id, string $purpose, string $uid, string $code, int $expiresAt): void
{
    test_set('AUTH_OTP_REQUESTS/' . $id, [
        'otp_request_id' => $id,
        'purpose' => $purpose,
        'uid' => $uid,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'status' => 'SENT',
        'used' => false,
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => $expiresAt,
        'created_at' => now_ts(),
        'updated_at' => now_ts(),
    ]);
}

seed_otp('OTP_OK', 'USER_LOGIN', 'U1', '012345', now_ts() + 300);
$claim = auth_otp_claim_verification('OTP_OK', 'USER_LOGIN', 'U1', '012345');
assert_true(!empty($claim['ok']), 'correct OTP must be atomically claimed');
assert_true((test_get('AUTH_OTP_REQUESTS/OTP_OK')['status'] ?? '') === 'VERIFYING', 'claimed OTP must be VERIFYING');

$duplicate = auth_otp_claim_verification('OTP_OK', 'USER_LOGIN', 'U1', '012345');
assert_true(($duplicate['code'] ?? '') === 'OTP_VERIFY_IN_PROGRESS', 'active duplicate verify must be blocked');
assert_true(auth_otp_complete_verification('OTP_OK', (string)$claim['owner_token']), 'claim owner must complete OTP');
$completed = test_get('AUTH_OTP_REQUESTS/OTP_OK');
assert_true(!empty($completed['used']) && ($completed['status'] ?? '') === 'VERIFIED', 'completed OTP must be used once');

seed_otp('OTP_BAD', 'USER_LOGIN', 'U1', '123456', now_ts() + 300);
$wrong = auth_otp_claim_verification('OTP_BAD', 'USER_LOGIN', 'U1', '000000');
assert_true(($wrong['code'] ?? '') === 'OTP_INVALID', 'wrong OTP must fail canonically');
assert_true((int)(test_get('AUTH_OTP_REQUESTS/OTP_BAD')['attempts'] ?? 0) === 1, 'wrong OTP attempt must be recorded with CAS');

seed_otp('OTP_EXPIRED', 'USER_LOGIN', 'U1', '123456', now_ts() - 1);
$expired = auth_otp_claim_verification('OTP_EXPIRED', 'USER_LOGIN', 'U1', '123456');
assert_true(($expired['code'] ?? '') === 'OTP_EXPIRED', 'expired OTP must fail');
assert_true((test_get('AUTH_OTP_REQUESTS/OTP_EXPIRED')['status'] ?? '') === 'EXPIRED', 'expired OTP state must persist');

seed_otp('OTP_PURPOSE', 'USER_REGISTER', 'U1', '123456', now_ts() + 300);
$purpose = auth_otp_claim_verification('OTP_PURPOSE', 'USER_LOGIN', 'U1', '123456');
assert_true(($purpose['code'] ?? '') === 'OTP_PURPOSE_MISMATCH', 'OTP purpose must be bound');

seed_otp('OTP_STALE', 'USER_LOGIN', 'U1', '123456', now_ts() + 600);
$first = auth_otp_claim_verification('OTP_STALE', 'USER_LOGIN', 'U1', '123456');
$testNow += auth_otp_verification_lease_seconds() + 1;
$takeover = auth_otp_claim_verification('OTP_STALE', 'USER_LOGIN', 'U1', '123456');
assert_true(!empty($takeover['ok']), 'expired verification lease must allow one takeover');
assert_true(!auth_otp_complete_verification('OTP_STALE', (string)$first['owner_token']), 'old OTP owner must be rejected');
assert_true(auth_otp_complete_verification('OTP_STALE', (string)$takeover['owner_token']), 'takeover owner must complete');

test_set('USERS/U1', [
    'uid' => 'U1',
    'phone' => '60123456789',
    'role' => 'USER',
    'status' => 'ACTIVE',
    'auth_session_epoch' => 'OLD_EPOCH',
]);
$getPaths = [];
$session = auth_issue_website_user_session(
    (array)test_get('USERS/U1'),
    'U1',
    'USER_WEB',
    'User Dashboard',
    ['ip' => '127.0.0.1', 'ip_country' => 'MY']
);
assert_true(!empty($session['ok']), 'website user session must be created');
$sessionRow = test_get('USER_SESSIONS/' . $session['session_hash']);
$userRow = test_get('USERS/U1');
assert_true(($sessionRow['status'] ?? '') === 'ACTIVE', 'website session must be active after checked creation');
assert_true(($sessionRow['auth_session_epoch'] ?? '') === ($userRow['auth_session_epoch'] ?? ''), 'session epoch must match user epoch');
assert_true(($userRow['active_device_id'] ?? '') === 'USER_WEB', 'active website device must be persisted');
assert_true(!in_array('USER_SESSIONS', $getPaths, true), 'website login must not scan the global session node');

$failUserPatch = true;
$failedSession = auth_issue_website_user_session((array)$userRow, 'U1', 'USER_WEB_2', 'User Dashboard');
$failUserPatch = false;
assert_true(empty($failedSession['ok']), 'checked user-state write failure must stop session creation');

$ownerQueryRows = [
    'parent_subadmin_uid' => ['OWN_1' => ['uid' => 'OWN_1', 'parent_subadmin_uid' => 'SUB_1']],
    'created_by_uid' => ['OWN_2' => ['uid' => 'OWN_2', 'created_by_uid' => 'SUB_1']],
];
$ownedRows = admin_users_query_subadmin_users('SUB_1');
assert_true(is_array($ownedRows) && count($ownedRows) === 2, 'subadmin owner queries must merge only scoped user rows');

$root = dirname(__DIR__);
$registerJs = (string)file_get_contents($root . '/api/user/assets/register.js');
$registerProxy = (string)file_get_contents($root . '/api/user/proxy.php');
$userVerify = (string)file_get_contents($root . '/api/auth/user_login_verify_otp.php');
$adminProxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$mfsPending = (string)file_get_contents($root . '/api/admin/mfs/pending.php');
$htaccess = (string)file_get_contents($root . '/.htaccess');
$dashboardJs = (string)file_get_contents($root . '/api/user/assets/dashboard.js');
$usersAdmin = (string)file_get_contents($root . '/api/lib/users_admin.php');
$registerResend = (string)file_get_contents($root . '/api/auth/user_register_resend_otp.php');
$loginResend = (string)file_get_contents($root . '/api/auth/user_login_resend_otp.php');
$bootstrap = (string)file_get_contents($root . '/api/bootstrap.php');
$adminDashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$adminDashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$subadminProxy = (string)file_get_contents($root . '/api/subadmin/proxy.php');
$adminDashboardWrites = [
    $root . '/api/admin/dashboard/config_update.php',
    $root . '/api/admin/dashboard/banners/create.php',
    $root . '/api/admin/dashboard/banners/delete.php',
    $root . '/api/admin/dashboard/banners/update.php',
    $root . '/api/admin/dashboard/services/seed_defaults.php',
    $root . '/api/admin/dashboard/services/update.php',
];

assert_true(str_contains($registerJs, 'identity_number'), 'registration JS must send identity number');
assert_true(str_contains($registerProxy, "'identity_number'"), 'registration proxy must forward identity number');
assert_true(str_contains($userVerify, 'auth_otp_claim_verification'), 'user OTP endpoint must use CAS claim');
assert_true(!str_contains($userVerify, 'auth_activate_user_device('), 'user OTP endpoint must not run global session revocation');
assert_true(!str_contains($adminProxy, "'internal_url' =>"), 'admin proxy must not expose internal URLs');
assert_true(str_contains($adminProxy, "case 'logout':") && str_contains($adminProxy, 'proxy_require_csrf();'), 'admin logout must require CSRF');
assert_true(!str_contains($mfsPending, 'getMessage()'), 'admin MFS errors must not expose exception details');
assert_true(!str_contains($htaccess, '%{HTTP_HOST}'), 'HTTPS redirect must not trust Host header');
assert_true(
    str_contains($dashboardJs, "'WAITING_ADMIN'") && str_contains($dashboardJs, "return 'Pending';"),
    'WAITING_ADMIN must display as Pending'
);
assert_true(str_contains($usersAdmin, "fb_get('USERS', ['shallow' => 'true'])"), 'subadmin user list must use shallow user inventory');
assert_true(str_contains($usersAdmin, 'admin_users_multi_get'), 'subadmin user list must batch detail reads');
assert_true(str_contains($usersAdmin, 'admin_users_query_subadmin_users'), 'subadmin user list must prefer owner-scoped queries');
assert_true(str_contains($registerResend, 'Your previous OTP remains valid.'), 'failed registration resend must preserve prior OTP');
assert_true(str_contains($loginResend, '$newOtpRequestId'), 'login resend must rotate OTP request identity');
assert_true(str_contains($bootstrap, "ini_set('display_errors', '0')"), 'API bootstrap must suppress raw PHP errors');
assert_true(str_contains($bootstrap, "'code' => 'SERVICE_UNAVAILABLE'"), 'missing private config must use canonical API error');
assert_true(str_contains($adminDashboard, 'aria-hidden="true" inert'), 'closed admin drawer must be inaccessible');
assert_true(str_contains($adminDashboardJs, "drawer?.setAttribute('inert', '')"), 'admin drawer close must restore inert state');
assert_true(!str_contains($subadminProxy, 'require_once app_private_config_path()'), 'subadmin proxy must use safe shared bootstrap config loading');
assert_true(str_contains($subadminProxy, 'HTTP_X_FORWARDED_PROTO'), 'subadmin secure cookie detection must support the HTTPS proxy header');
assert_true(!str_contains($subadminProxy, "'raw' => \$row"), 'subadmin MFS response must not expose the internal request row');
foreach ($adminDashboardWrites as $writeEndpoint) {
    $source = (string)file_get_contents($writeEndpoint);
    assert_true(str_contains($source, 'auth_require_admin_session'), basename($writeEndpoint) . ' must require ADMIN');
    assert_true(!str_contains($source, 'zpay_dash_require_admin_or_subadmin'), basename($writeEndpoint) . ' must block SUBADMIN writes');
}

foreach ([
    $root . '/api/auth/admin_login_start.php',
    $root . '/api/auth/admin_login_verify_otp.php',
    $root . '/api/auth/login_start.php',
    $root . '/api/auth/login_verify_otp.php',
] as $sessionEndpoint) {
    $source = (string)file_get_contents($sessionEndpoint);
    assert_true(
        str_contains($source, "'auth_session_epoch' => auth_session_epoch_from_user(\$user)"),
        basename($sessionEndpoint) . ' must preserve session epoch'
    );
}

echo "website auth and panel security tests passed\n";
