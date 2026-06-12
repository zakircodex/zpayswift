<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth_sms.php';

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
        'phone' => (string) ($user['phone'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => $role,
        'phone_country' => auth_phone_country_from_user($user),
        'pricing_country' => auth_pricing_country_from_user(
            $user,
            (array)(fb_get('USER_WALLETS/' . $uid) ?: [])
        ),
    ];
}

function ucotp_validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

$actor = ucotp_load_actor_from_session();

$name = trim((string)($body['name'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}
$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$pricingCountry = auth_normalize_country_code((string)($body['pricing_country'] ?? $body['service_country'] ?? ''));
if (strtoupper((string)($actor['role'] ?? '')) === 'SUBADMIN') {
    $pricingCountry = (string)($actor['pricing_country'] ?? 'BD');
} elseif ($pricingCountry === '') {
    $pricingCountry = (string)($actor['pricing_country'] ?? 'BD');
}
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$pin = trim((string)($body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));

if ($name === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '' || $pin === '' || $confirmPin === '') {
    ucotp_response(
        false,
        'VALIDATION_ERROR',
        $phone === '' ? auth_phone_validation_message($phoneCountry) : 'All fields are required',
        [],
        422
    );
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

$existingUid = auth_find_uid_by_phone_country($phone, $phoneCountry);
if ($existingUid !== '') {
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
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'email' => $email,
    'password' => $password,
    'pin' => $pin,
]);

$actorPhoneCountry = auth_normalize_country_code((string)($actor['phone_country'] ?? '')) ?: 'BD';
$actorPhone = normalize_phone_by_country((string)($actor['phone'] ?? ''), $actorPhoneCountry);
if ($actorPhone === '') {
    ucotp_response(false, 'VALIDATION_ERROR', 'Creator account phone number is invalid', [], 422);
}

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => (string)($actor['uid'] ?? ''),
    'phone' => $actorPhone,
    'country' => $actorPhoneCountry,
    'phone_country' => $actorPhoneCountry,
    'pricing_country' => (string)($actor['pricing_country'] ?? 'BD'),
    'dial_code' => $actorPhoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164' => $actorPhone,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'purpose' => 'SUBADMIN_USER_CREATE',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => ucotp_mask_phone($actorPhone),
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
    'actor_phone' => $actorPhone,
    'actor_phone_country' => $actorPhoneCountry,
    'actor_role' => (string)($actor['role'] ?? ''),
    'target_name' => $name,
    'target_phone' => $phone,
    'target_phone_country' => $phoneCountry,
    'target_pricing_country' => $pricingCountry,
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
    'Z-Pay Swift OTP to create user ' . $phone .
    ' is ' . $otpCode .
    '. Valid for 5 minutes. Do not share this code.';

$smsResult = auth_send_otp_sms_by_country($actorPhoneCountry, $actorPhone, $message, $otpRequestId);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => ucotp_now(),
    ] + $smsPatch);

    @fb_patch('AUTH_USER_CREATE_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => ucotp_now(),
    ]);

    ucotp_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => ucotp_now(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('SUBADMIN_USER_CREATE_OTP_SENT', $otpRequestId, 'User create OTP sent', [
        'actor_uid' => (string)($actor['uid'] ?? ''),
        'actor_phone' => $actorPhone,
        'actor_phone_country' => $actorPhoneCountry,
        'target_phone' => $phone,
        'target_phone_country' => $phoneCountry,
        'target_pricing_country' => $pricingCountry,
        'target_email' => $email,
    ]);
}

ucotp_response(true, 'SUCCESS', 'OTP sent successfully', [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => ucotp_mask_phone($actorPhone),
    'expires_in_seconds' => 300,
    'target_name' => $name,
    'target_phone' => $phone,
    'target_phone_country' => $phoneCountry,
    'target_pricing_country' => $pricingCountry,
    'target_email' => $email,
]);
