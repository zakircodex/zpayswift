<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ucc_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function ucc_now(): int
{
    return function_exists('now_ts') ? (int) now_ts() : time();
}

function ucc_session_hash(string $token): string
{
    return function_exists('session_hash') ? session_hash($token) : hash('sha256', $token);
}

function ucc_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function ucc_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['SUBADMIN', 'ADMIN'], true);
}

function ucc_secret_key(): string
{
    return hash('sha256', APP_KEY . '|subadmin-user-create-temp-secret', true);
}

function ucc_decrypt_payload(string $encoded): array
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return [];
    }

    $raw = base64_decode($encoded, true);
    if ($raw === false || $raw === '') {
        return [];
    }

    $cipher = 'AES-256-CBC';
    $ivLen = openssl_cipher_iv_length($cipher);

    if (strlen($raw) <= $ivLen) {
        return [];
    }

    $iv = substr($raw, 0, $ivLen);
    $cipherText = substr($raw, $ivLen);

    $plain = openssl_decrypt(
        $cipherText,
        $cipher,
        ucc_secret_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if (!is_string($plain) || $plain === '') {
        return [];
    }

    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : [];
}

function ucc_load_actor_from_session(): array
{
    $sessionToken = trim((string) ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    if ($sessionToken === '') {
        ucc_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $hash = ucc_session_hash($sessionToken);
    $session = fb_get('USER_SESSIONS/' . $hash);

    if (!is_array($session)) {
        ucc_response(false, 'SESSION_EXPIRED', 'Session not found', [], 401);
    }

    $now = ucc_now();
    $status = strtoupper(trim((string) ($session['status'] ?? 'INACTIVE')));
    $expiresAt = (int) ($session['expires_at'] ?? 0);
    $uid = trim((string) ($session['uid'] ?? ''));

    if ($uid === '' || $status !== 'ACTIVE' || $expiresAt < $now) {
        ucc_response(false, 'SESSION_EXPIRED', 'Session expired', [], 401);
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        ucc_response(false, 'UNAUTHORIZED', 'Account not found', [], 401);
    }

    $role = strtoupper(trim((string) ($user['role'] ?? '')));
    $userStatus = strtoupper(trim((string) ($user['status'] ?? 'INACTIVE')));

    if (!ucc_allowed_role($role)) {
        ucc_response(false, 'FORBIDDEN', 'Subadmin access required', [], 403);
    }

    if ($userStatus !== 'ACTIVE') {
        ucc_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return [
        'session_token' => $sessionToken,
        'uid' => $uid,
        'name' => (string) ($user['name'] ?? ''),
        'phone' => ucc_normalize_phone((string) ($user['phone'] ?? '')),
        'email' => (string) ($user['email'] ?? ''),
        'role' => $role,
    ];
}

function ucc_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function ucc_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function ucc_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/auth/user_create_confirm.php';
    $apiPath = dirname(dirname($script));
    return rtrim(ucc_scheme() . '://' . ucc_host() . $apiPath, '/');
}

function ucc_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = ucc_api_base_url() . '/' . ltrim($relativePath, '/');

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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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


api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

$actor = ucc_load_actor_from_session();

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    ucc_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
}

$preAuthRow = fb_get('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow)) {
    ucc_response(false, 'PREAUTH_NOT_FOUND', 'User create verification session not found', [], 404);
}

if ((string)($preAuthRow['otp_request_id'] ?? '') !== $otpRequestId) {
    ucc_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

if ((string)($preAuthRow['actor_uid'] ?? '') !== (string)($actor['uid'] ?? '')) {
    ucc_response(false, 'FORBIDDEN', 'This OTP request does not belong to your account', [], 403);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
if ($preAuthStatus !== 'OTP_PENDING') {
    ucc_response(false, 'OTP_NOT_PENDING', 'OTP is not pending for this user creation request', [], 400);
}

$now = ucc_now();
if ((int)($preAuthRow['expires_at'] ?? 0) < $now) {
    @fb_patch('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);
    ucc_response(false, 'OTP_EXPIRED', 'OTP verification session expired', [], 410);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    ucc_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

if ((string)($otpRow['uid'] ?? '') !== (string)($actor['uid'] ?? '')) {
    ucc_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account', [], 400);
}

$pendingPhoneCountry = auth_normalize_country_code(
    (string)($preAuthRow['target_phone_country'] ?? '')
);
$pendingPhone = normalize_phone_by_country(
    (string)($preAuthRow['target_phone_e164'] ?? $preAuthRow['target_phone'] ?? ''),
    $pendingPhoneCountry
);
$otpPhoneCountry = auth_normalize_country_code(
    (string)($otpRow['target_phone_country'] ?? $otpRow['phone_country'] ?? $otpRow['country'] ?? '')
);
$otpPhone = normalize_phone_by_country(
    (string)($otpRow['target_phone_e164'] ?? $otpRow['phone_e164'] ?? $otpRow['phone'] ?? ''),
    $otpPhoneCountry
);
if (
    $pendingPhone === ''
    || $otpPhone === ''
    || $pendingPhoneCountry !== $otpPhoneCountry
    || $pendingPhone !== $otpPhone
) {
    if (function_exists('system_log')) {
        system_log('SUBADMIN_USER_CREATE_OTP_TARGET_MISMATCH', $otpRequestId, 'Create-user OTP target mismatch', [
            'actor_uid' => (string)($actor['uid'] ?? ''),
            'actor_role' => (string)($actor['role'] ?? ''),
        ]);
    }
    ucc_response(false, 'OTP_TARGET_MISMATCH', 'OTP target phone validation failed', [], 400);
}

if ((bool)($otpRow['used'] ?? false)) {
    ucc_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
if (!in_array($otpStatus, ['SENT', 'RESENT'], true)) {
    ucc_response(false, 'OTP_INVALID_STATUS', 'OTP is not active', [], 400);
}

if ((int)($otpRow['expires_at'] ?? 0) < $now) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);
    @fb_patch('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);
    ucc_response(false, 'OTP_EXPIRED', 'OTP expired', [], 410);
}

$codeHash = (string)($otpRow['code_hash'] ?? '');
if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'failed_attempt_at' => $now,
        'updated_at' => $now,
    ]);
    ucc_response(false, 'OTP_INVALID', 'Invalid OTP', [], 400);
}

$payload = ucc_decrypt_payload((string)($preAuthRow['payload_secret'] ?? ''));
if (!$payload) {
    ucc_response(false, 'PREAUTH_INVALID', 'Secure user creation payload missing or invalid', [], 400);
}

$name = trim((string)($payload['name'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($payload['phone_country'] ?? $preAuthRow['target_phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($payload['phone'] ?? '')) ?: 'BD';
}
$phone = normalize_phone_by_country((string)($payload['phone'] ?? ''), $phoneCountry);
$pricingCountry = auth_normalize_country_code((string)(
    $payload['pricing_country']
    ?? $payload['market_country']
    ?? $preAuthRow['target_pricing_country']
    ?? $preAuthRow['target_market_country']
    ?? ''
));
if ($pricingCountry === '') {
    $pricingCountry = 'BD';
}
$email = strtolower(trim((string)($payload['email'] ?? '')));
$password = (string)($payload['password'] ?? '');
$pin = trim((string)($payload['pin'] ?? ''));

if ($name === '' || $phone === '' || $email === '' || $password === '' || $pin === '') {
    ucc_response(false, 'PREAUTH_INVALID', 'Stored user creation payload is incomplete', [], 400);
}

$pendingPricingCountry = auth_normalize_country_code(
    (string)($preAuthRow['target_pricing_country'] ?? $pricingCountry)
);
if (
    $phoneCountry !== $pendingPhoneCountry
    || $phone !== $pendingPhone
    || $pricingCountry !== $pendingPricingCountry
) {
    if (function_exists('system_log')) {
        system_log('SUBADMIN_USER_CREATE_PENDING_DATA_MISMATCH', $otpRequestId, 'Create-user pending data mismatch', [
            'actor_uid' => (string)($actor['uid'] ?? ''),
            'actor_role' => (string)($actor['role'] ?? ''),
        ]);
    }
    ucc_response(false, 'PREAUTH_INVALID', 'Stored user creation target validation failed', [], 400);
}

if (auth_find_uid_by_phone_country($phone, $phoneCountry) !== '') {
    ucc_response(false, 'PHONE_ALREADY_EXISTS', 'Phone number already exists', [], 409);
}

$createRes = ucc_internal_api_request('POST', 'user_create_by_subadmin.php', [
    'name' => $name,
    'phone' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'email' => $email,
    'password' => $password,
    'confirm_password' => $password,
    'pin' => $pin,
    'confirm_pin' => $pin,
], [
    'X-APP-KEY' => APP_KEY,
    'X-SESSION-TOKEN' => (string)($actor['session_token'] ?? ''),
]);

if (!$createRes['ok']) {
    $json = $createRes['json'] ?? [];
    ucc_response(
        false,
        (string)($json['code'] ?? 'CREATE_FAILED'),
        (string)($json['message'] ?? 'Failed to create user'),
        (array)($json['data'] ?? []),
        $createRes['status'] > 0 ? $createRes['status'] : 400
    );
}

$createdData = (array)($createRes['json']['data'] ?? []);

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

@fb_patch('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken, [
    'status' => 'COMPLETED',
    'completed_at' => $now,
    'updated_at' => $now,
]);

if (function_exists('system_log')) {
    system_log('SUBADMIN_USER_CREATE_CONFIRMED', $otpRequestId, 'User created after OTP verification', [
        'actor_uid' => (string)($actor['uid'] ?? ''),
        'actor_phone' => (string)($actor['phone'] ?? ''),
        'target_phone' => $phone,
        'target_email' => $email,
        'created_uid' => (string)($createdData['uid'] ?? ''),
    ]);
}

ucc_response(true, 'SUCCESS', 'User created successfully', [
    'created' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'target_name' => $name,
    'target_phone' => $phone,
    'target_phone_country' => $phoneCountry,
    'target_pricing_country' => $pricingCountry,
    'target_email' => $email,
    'user' => $createdData,
]);
