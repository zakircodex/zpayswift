<?php
declare(strict_types=1);

require_once '/home/zedpayhe/private/zawtopup/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once '/home/zedpayhe/public_html/zawtopup/api/lib/auth_sms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function ucotp_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function ucotp_now(): int
{
    return function_exists('now_ts') ? (int) now_ts() : time();
}

function ucotp_session_hash(string $token): string
{
    return function_exists('session_hash') ? session_hash($token) : hash('sha256', $token);
}

function ucotp_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function ucotp_mask_phone(string $phone): string
{
    $phone = ucotp_normalize_phone($phone);
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function ucotp_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['SUBADMIN', 'ADMIN'], true);
}

function ucotp_secret_key(): string
{
    return hash('sha256', APP_KEY . '|subadmin-user-create-temp-secret', true);
}

function ucotp_encrypt_payload(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        ucotp_response(false, 'SERVER_ERROR', 'Failed to prepare secure create-user payload', [], 500);
    }

    $cipher = 'AES-256-CBC';
    $ivLen = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivLen);

    $encrypted = openssl_encrypt(
        $json,
        $cipher,
        ucotp_secret_key(),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encrypted === false) {
        ucotp_response(false, 'SERVER_ERROR', 'Failed to encrypt create-user payload', [], 500);
    }

    return base64_encode($iv . $encrypted);
}

function ucotp_load_actor_from_session(): array
{
    $sessionToken = trim((string) ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    if ($sessionToken === '') {
        ucotp_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $hash = ucotp_session_hash($sessionToken);
    $session = fb_get('USER_SESSIONS/' . $hash);

    if (!is_array($session)) {
        ucotp_response(false, 'SESSION_EXPIRED', 'Session not found', [], 401);
    }

    $now = ucotp_now();
    $status = strtoupper(trim((string) ($session['status'] ?? 'INACTIVE')));
    $expiresAt = (int) ($session['expires_at'] ?? 0);
    $uid = trim((string) ($session['uid'] ?? ''));

    if ($uid === '' || $status !== 'ACTIVE' || $expiresAt < $now) {
        ucotp_response(false, 'SESSION_EXPIRED', 'Session expired', [], 401);
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        ucotp_response(false, 'UNAUTHORIZED', 'Account not found', [], 401);
    }

    $role = strtoupper(trim((string) ($user['role'] ?? '')));
    $userStatus = strtoupper(trim((string) ($user['status'] ?? 'INACTIVE')));

    if (!ucotp_allowed_role($role)) {
        ucotp_response(false, 'FORBIDDEN', 'Subadmin access required', [], 403);
    }

    if ($userStatus !== 'ACTIVE') {
        ucotp_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return [
        'uid' => $uid,
        'name' => (string) ($user['name'] ?? ''),
        'phone' => ucotp_normalize_phone((string) ($user['phone'] ?? '')),
        'email' => (string) ($user['email'] ?? ''),
        'role' => $role,
    ];
}

function ucotp_validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function ucotp_send_sms(string $phone, string $message): bool
{
    if (function_exists('auth_send_otp_sms')) {
        return (bool) auth_send_otp_sms($phone, $message);
    }

    return false;
}

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

$actor = ucotp_load_actor_from_session();

$name = trim((string)($body['name'] ?? ''));
$phone = ucotp_normalize_phone((string)($body['phone'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$pin = trim((string)($body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));

if ($name === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '' || $pin === '' || $confirmPin === '') {
    ucotp_response(false, 'VALIDATION_ERROR', 'All fields are required', [], 422);
}

if (!ucotp_validate_email($email)) {
    ucotp_response(false, 'VALIDATION_ERROR', 'Valid email is required', [], 422);
}

if ($password !== $confirmPassword) {
    ucotp_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match', [], 422);
}

if (strlen($password) < 6) {
    ucotp_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters', [], 422);
}

if (!preg_match('/^\d{4,8}$/', $pin)) {
    ucotp_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits', [], 422);
}

if ($pin !== $confirmPin) {
    ucotp_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match', [], 422);
}

$existingUid = fb_get('USER_INDEX/PHONE/' . $phone);
if (is_string($existingUid) && trim($existingUid) !== '') {
    ucotp_response(false, 'PHONE_ALREADY_EXISTS', 'Phone number already exists', [], 409);
}

$now = ucotp_now();
$otpCode = (string) random_int(100000, 999999);
$otpRequestId = 'UCOTP' . strtoupper(bin2hex(random_bytes(5)));
$preAuthToken = 'UCPA' . strtoupper(bin2hex(random_bytes(12)));
$expiresAt = $now + 300;

$payloadSecret = ucotp_encrypt_payload([
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'password' => $password,
    'pin' => $pin,
]);

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => (string)($actor['uid'] ?? ''),
    'phone' => (string)($actor['phone'] ?? ''),
    'purpose' => 'SUBADMIN_USER_CREATE',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => ucotp_mask_phone((string)($actor['phone'] ?? '')),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'resent_at' => $now,
    'expires_at' => $expiresAt,
];

$preAuthRow = [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'actor_uid' => (string)($actor['uid'] ?? ''),
    'actor_phone' => (string)($actor['phone'] ?? ''),
    'actor_role' => (string)($actor['role'] ?? ''),
    'target_name' => $name,
    'target_phone' => $phone,
    'target_email' => $email,
    'payload_secret' => $payloadSecret,
    'purpose' => 'SUBADMIN_USER_CREATE',
    'status' => 'OTP_PENDING',
    'created_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
    @fb_delete('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken);
    ucotp_response(false, 'SERVER_ERROR', 'Failed to prepare user creation OTP', [], 500);
}

$message =
    'ZawTopup OTP to create user ' . $phone .
    ' is ' . $otpCode .
    '. Valid for 5 minutes. Do not share this code.';

$smsOk = ucotp_send_sms((string)($actor['phone'] ?? ''), $message);

if (!$smsOk) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => ucotp_now(),
    ]);

    @fb_patch('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => ucotp_now(),
    ]);

    ucotp_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

if (function_exists('system_log')) {
    system_log('SUBADMIN_USER_CREATE_OTP_SENT', $otpRequestId, 'User create OTP sent', [
        'actor_uid' => (string)($actor['uid'] ?? ''),
        'actor_phone' => (string)($actor['phone'] ?? ''),
        'target_phone' => $phone,
        'target_email' => $email,
    ]);
}

ucotp_response(true, 'SUCCESS', 'OTP sent successfully', [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => ucotp_mask_phone((string)($actor['phone'] ?? '')),
    'expires_in_seconds' => 300,
    'target_name' => $name,
    'target_phone' => $phone,
    'target_email' => $email,
]);