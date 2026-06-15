<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/wallet.php';
require_once __DIR__ . '/lib/users_admin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function subadmin_create_user_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subadmin_create_user_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        subadmin_create_user_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function subadmin_create_user_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        subadmin_create_user_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function subadmin_create_user_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function subadmin_create_user_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function subadmin_create_user_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/user_create_by_subadmin.php';
    $apiPath = dirname($script);
    return rtrim(subadmin_create_user_scheme() . '://' . subadmin_create_user_host() . $apiPath, '/');
}

function subadmin_create_user_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = subadmin_create_user_api_base_url() . '/' . ltrim($relativePath, '/');

    $ch = curl_init();
    $finalHeaders = ['Accept: application/json'];

    foreach ($headers as $k => $v) {
        $finalHeaders[] = $k . ': ' . $v;
    }

    if ($body !== null) {
        $finalHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $finalHeaders,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 0,
            'json' => null,
            'error' => $err ?: 'Unknown cURL error',
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'status' => $status,
            'json' => null,
            'error' => 'Invalid JSON response from internal API',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
        'error' => null,
    ];
}

function subadmin_create_user_extract_session_token(): string
{
    $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

function subadmin_create_user_require_actor(): array
{
    $sessionToken = subadmin_create_user_extract_session_token();
    if ($sessionToken === '') {
        subadmin_create_user_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $res = subadmin_create_user_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        subadmin_create_user_response(
            false,
            (string)($json['code'] ?? 'SESSION_EXPIRED'),
            (string)($json['message'] ?? 'Session expired'),
            [],
            $res['status'] > 0 ? $res['status'] : 401
        );
    }

    $actor = (array)($res['json']['data'] ?? []);
    $role = strtoupper(trim((string)($actor['role'] ?? '')));
    $status = strtoupper(trim((string)($actor['status'] ?? 'INACTIVE')));

    if ($role !== 'SUBADMIN' && $role !== 'ADMIN') {
        subadmin_create_user_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can create user', [], 403);
    }

    if ($status !== 'ACTIVE') {
        subadmin_create_user_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return $actor;
}

function subadmin_create_user_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function subadmin_create_user_uid(): string
{
    if (function_exists('make_uid')) {
        return (string)make_uid();
    }
    return 'U' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function subadmin_create_user_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function subadmin_create_user_email_index_key(string $email): string
{
    return md5(strtolower(trim($email)));
}

function subadmin_create_user_find_uid_by_phone(string $phone): string
{
    $phone = subadmin_create_user_normalize_phone($phone);
    if ($phone === '') {
        return '';
    }

    $row = fb_get('USER_INDEX/PHONE/' . $phone);

    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function subadmin_create_user_find_uid_by_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    $key = subadmin_create_user_email_index_key($email);
    $row = fb_get('USER_INDEX/EMAIL/' . $key);

    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

subadmin_create_user_require_method('POST');
$actor = subadmin_create_user_require_actor();
$body = subadmin_create_user_read_json_body();

$name = trim((string)($body['name'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}
$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$pricingCountry = auth_normalize_country_code((string)($body['pricing_country'] ?? $body['service_country'] ?? ''));
$actorUidForCountry = trim((string)($actor['uid'] ?? ''));
$actorUser = (array)(fb_get('USERS/' . $actorUidForCountry) ?: []);
$actorPricingCountry = auth_pricing_country_from_user(
    $actorUser,
    (array)(fb_get('USER_WALLETS/' . $actorUidForCountry) ?: [])
);
if (strtoupper((string)($actor['role'] ?? '')) === 'SUBADMIN' || $pricingCountry === '') {
    $pricingCountry = $actorPricingCountry;
}
$currency = auth_country_currency($pricingCountry);
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$pin = trim((string)($body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));

if ($name === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '' || $pin === '' || $confirmPin === '') {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', 'All fields are required', [], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', 'Valid email is required', [], 422);
}

if ($phone === '') {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

if (strlen($password) < 6) {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters', [], 422);
}

if ($password !== $confirmPassword) {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match', [], 422);
}

if (!preg_match('/^\d{4,8}$/', $pin)) {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits', [], 422);
}

if ($pin !== $confirmPin) {
    subadmin_create_user_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match', [], 422);
}

if (auth_find_uid_by_phone_country($phone, $phoneCountry) !== '') {
    subadmin_create_user_response(false, 'DUPLICATE_PHONE', 'Phone number already registered', [], 409);
}

if (subadmin_create_user_find_uid_by_email($email) !== '') {
    subadmin_create_user_response(false, 'DUPLICATE_EMAIL', 'Email already registered', [], 409);
}

$uid = subadmin_create_user_uid();
$now = subadmin_create_user_now();
$actorUid = trim((string)($actor['uid'] ?? ''));
$actorRole = strtoupper(trim((string)($actor['role'] ?? 'SUBADMIN')));

$parentSubadminUid = $actorRole === 'SUBADMIN' ? $actorUid : '';
$roleSettings = admin_users_role_default('USER');
$roleSettings['api_enabled'] = false;
$roleSettings['updated_at'] = $now;

$userRow = [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'country' => $pricingCountry,
    'country_code' => $pricingCountry,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'country_mismatch' => $pricingCountry !== $phoneCountry,
    'email' => $email,
    'role' => 'USER',
    'status' => 'ACTIVE',
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => 0,
    'created_by_admin' => false,
    'parent_subadmin_uid' => $parentSubadminUid,
    'created_by_uid' => $actorUid,
    'created_by_role' => $actorRole,
    'register_source' => 'SUBADMIN_PANEL',
];

$walletRow = [
    'currency' => $currency,
    'country' => $pricingCountry,
    'country_code' => $pricingCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'wallet_currency' => $currency,
    'available_balance' => 0,
    'hold_balance' => 0,
    'total_topup_spent' => 0,
    'total_bundle_spent' => 0,
    'total_refund' => 0,
    'updated_at' => $now,
];

$emailIndexKey = subadmin_create_user_email_index_key($email);

$okUser = fb_put('USERS/' . $uid, $userRow);
$okWallet = $okUser ? fb_put('USER_WALLETS/' . $uid, $walletRow) : false;
$okRole = $okWallet ? fb_put('USER_ROLE_SETTINGS/' . $uid, $roleSettings) : false;
$phoneIndexKeys = auth_phone_index_candidates($phone, $phoneCountry);
$okPhone = $okRole;
foreach ($phoneIndexKeys as $phoneIndexKey) {
    if (!$okPhone || !fb_put('USER_INDEX/PHONE/' . $phoneIndexKey, $uid)) {
        $okPhone = false;
        break;
    }
}
$okEmail = $okPhone ? fb_put('USER_INDEX/EMAIL/' . $emailIndexKey, $uid) : false;

if (!($okUser && $okWallet && $okRole && $okPhone && $okEmail)) {
    fb_delete('USERS/' . $uid);
    fb_delete('USER_WALLETS/' . $uid);
    fb_delete('USER_ROLE_SETTINGS/' . $uid);
    foreach ($phoneIndexKeys as $phoneIndexKey) {
        fb_delete('USER_INDEX/PHONE/' . $phoneIndexKey);
    }
    fb_delete('USER_INDEX/EMAIL/' . $emailIndexKey);

    subadmin_create_user_response(false, 'SERVER_ERROR', 'Failed to create account', [], 500);
}

if (function_exists('system_log')) {
    system_log('SUBADMIN_CREATE_USER', $uid, 'User created by subadmin/admin panel', [
        'uid' => $uid,
        'phone' => $phone,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'market_country' => $pricingCountry,
        'service_country' => $pricingCountry,
        'country_mismatch' => $pricingCountry !== $phoneCountry,
        'currency' => $currency,
        'email' => $email,
        'actor_uid' => $actorUid,
        'actor_role' => $actorRole,
        'parent_subadmin_uid' => $parentSubadminUid,
    ]);
}

subadmin_create_user_response(true, 'SUCCESS', 'User created successfully', [
    'item' => [
        'uid' => $uid,
        'name' => $name,
        'phone' => $phone,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'market_country' => $pricingCountry,
        'service_country' => $pricingCountry,
        'currency' => $currency,
        'country_mismatch' => $pricingCountry !== $phoneCountry,
        'email' => $email,
        'role' => 'USER',
        'status' => 'ACTIVE',
        'parent_subadmin_uid' => $parentSubadminUid,
        'created_by_uid' => $actorUid,
        'created_by_role' => $actorRole,
        'register_source' => 'SUBADMIN_PANEL',
        'created_at' => $now,
    ],
]);
