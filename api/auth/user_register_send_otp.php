<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_reg_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_reg_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_reg_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function user_reg_make_uid(): string
{
    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return 'U' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
}

function user_reg_normalize_phone(string $phone): string
{
    if (function_exists('normalize_login_phone')) {
        return (string)normalize_login_phone($phone);
    }

    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function user_reg_mask_phone(string $phone): string
{
    $phone = user_reg_normalize_phone($phone);
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function user_reg_email_key(string $email): string
{
    return md5(strtolower(trim($email)));
}

function user_reg_find_uid_by_phone(string $phone): string
{
    $phone = user_reg_normalize_phone($phone);
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

function user_reg_find_uid_by_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    $row = fb_get('USER_INDEX/EMAIL/' . user_reg_email_key($email));

    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function user_reg_send_sms(string $phone, string $message): bool
{
    if (function_exists('auth_send_otp_sms')) {
        return (bool)auth_send_otp_sms($phone, $message);
    }

    return false;
}

$name = trim((string)($body['name'] ?? ''));
$phone = user_reg_normalize_phone((string)($body['phone'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$pin = trim((string)($body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Register'));

if ($name === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '' || $pin === '' || $confirmPin === '') {
    user_reg_response(false, 'VALIDATION_ERROR', 'All fields are required', [], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Valid email is required', [], 422);
}

if (strlen($phone) < 10) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Valid phone number is required', [], 422);
}

if (strlen($password) < 6) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters', [], 422);
}

if ($password !== $confirmPassword) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match', [], 422);
}

if (!preg_match('/^\d{4,8}$/', $pin)) {
    user_reg_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits', [], 422);
}

if ($pin !== $confirmPin) {
    user_reg_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match', [], 422);
}

if (user_reg_find_uid_by_phone($phone) !== '') {
    user_reg_response(false, 'DUPLICATE_PHONE', 'Phone number already registered', [], 409);
}

if (user_reg_find_uid_by_email($email) !== '') {
    user_reg_response(false, 'DUPLICATE_EMAIL', 'Email already registered', [], 409);
}

$now = user_reg_now();
$expiresAt = $now + 300;

$uid = user_reg_make_uid();
$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'UROTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = 'URPA' . user_reg_token(16);

$message = 'Z-Pay Swift register OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $phone,
    'purpose' => 'USER_REGISTER',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_reg_mask_phone($phone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$preAuthRow = [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
    'role' => 'USER',
    'status' => 'OTP_PENDING',
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'masked_phone' => user_reg_mask_phone($phone),
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    if (function_exists('fb_delete')) {
        @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
        @fb_delete('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken);
    }

    user_reg_response(false, 'SERVER_ERROR', 'Failed to prepare register OTP', [], 500);
}

$smsOk = user_reg_send_sms($phone, $message);

if (!$smsOk) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_reg_now(),
    ]);

    @fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_reg_now(),
    ]);

    user_reg_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

if (function_exists('system_log')) {
    system_log('USER_REGISTER_OTP_SENT', $otpRequestId, 'User register OTP sent', [
        'uid' => $uid,
        'phone' => $phone,
        'email' => $email,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
    ]);
}

user_reg_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => user_reg_mask_phone($phone),
    'expires_in_seconds' => 300,
]);