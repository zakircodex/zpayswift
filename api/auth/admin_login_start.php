<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function admin_login_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON'], true);
}

function admin_login_mask_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function admin_login_find_uid_by_phone(string $phone, string $country): string
{
    return auth_find_uid_by_phone_country($phone, $country);
}

function admin_login_issue_session(
    array $user,
    string $uid,
    string $deviceId,
    string $deviceName,
    array $requestMeta = []
): string
{
    $token = random_token(32);
    $hash = session_hash($token);
    $sessionId = make_session_id();
    $now = now_ts();

    $session = [
        'session_id' => $sessionId,
        'uid' => $uid,
        'phone' => (string)($user['phone'] ?? ''),
        'token_last8' => substr($token, -8),
        'device_name' => $deviceName,
        'device_id' => $deviceId,
        'status' => 'ACTIVE',
        'ip' => client_ip(),
        'created_at' => $now,
        'expires_at' => $now + SESSION_TTL_SECONDS,
        'last_seen_at' => $now,
    ];

    if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
        api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
    }

    fb_patch('USERS/' . $uid, [
        'last_login_at' => $now,
        'last_login_ip' => (string)($requestMeta['created_ip'] ?? ''),
        'last_login_ip_country' => (string)($requestMeta['ip_country'] ?? ''),
        'last_login_user_agent' => (string)($requestMeta['user_agent'] ?? ''),
        'browser_timezone' => (string)($requestMeta['browser_timezone'] ?? ($user['browser_timezone'] ?? '')),
        'updated_at' => $now,
    ]);

    return $token;
}

function admin_login_has_valid_trusted_device(string $uid, string $cookieValue): bool
{
    $cookieValue = trim($cookieValue);

    if ($cookieValue === '' || strpos($cookieValue, ':') === false) {
        return false;
    }

    [$selector, $rawToken] = explode(':', $cookieValue, 2);

    $selector = trim($selector);
    $rawToken = trim($rawToken);

    if ($selector === '' || $rawToken === '') {
        return false;
    }

    $path = 'AUTH_ADMIN_TRUSTED_DEVICES/' . $uid . '/' . $selector;
    $row = fb_get($path);

    if (!is_array($row)) {
        return false;
    }

    $status = strtoupper(trim((string)($row['status'] ?? 'INACTIVE')));
    $storedHash = trim((string)($row['token_hash'] ?? ''));
    $expiresAt = (int)($row['expires_at'] ?? 0);
    $now = now_ts();

    if ($status !== 'ACTIVE') {
        return false;
    }

    if ($storedHash === '' || $expiresAt <= $now) {
        fb_patch($path, [
            'status' => 'EXPIRED',
            'updated_at' => $now,
        ]);
        return false;
    }

    if (!hash_equals($storedHash, hash('sha256', $rawToken))) {
        return false;
    }

    fb_patch($path, [
        'last_used_at' => $now,
        'updated_at' => $now,
    ]);

    return true;
}

$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}
$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$password = (string)($body['password'] ?? '');
$deviceId = trim((string)($body['device_id'] ?? 'ADMIN_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'Admin Dashboard'));
$trustDevice = admin_login_bool_value($body['trust_device'] ?? true);
$trustedDeviceCookie = trim((string)($body['trusted_device_cookie'] ?? ''));
$requestMeta = auth_request_metadata($body);

if ($phone === '' || $password === '') {
    api_response(false, 'VALIDATION_ERROR', $phone === '' ? auth_phone_validation_message($phoneCountry) : 'Phone and password are required', [], 422);
}

$uid = admin_login_find_uid_by_phone($phone, $phoneCountry);

if ($uid === '') {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$user = fb_get('USERS/' . $uid);

if (!is_array($user)) {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

$status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
$role = strtoupper(trim((string)($user['role'] ?? '')));
$passwordHash = (string)($user['password_hash'] ?? '');
$storedPhoneCountry = auth_phone_country_from_user($user);
$pricingCountry = auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));

if ($storedPhoneCountry !== $phoneCountry) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$otpPhone = normalize_phone_by_country((string)($user['phone'] ?? $phone), $storedPhoneCountry);
if ($otpPhone === '') {
    $otpPhone = $phone;
}

if ($status !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'Admin account is not active', [], 403);
}

if ($role !== 'ADMIN') {
    api_response(false, 'FORBIDDEN', 'Admin access required', [], 403);
}

if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

if (admin_login_has_valid_trusted_device($uid, $trustedDeviceCookie)) {
    $sessionToken = admin_login_issue_session($user, $uid, $deviceId, $deviceName, $requestMeta);

    if (function_exists('system_log')) {
        system_log('ADMIN_TRUSTED_LOGIN', $uid, 'Admin trusted device login successful', [
            'uid' => $uid,
            'phone' => $phone,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'ip' => client_ip(),
        ]);
    }

    api_response(true, 'SUCCESS', 'Trusted device login successful', [
        'require_otp' => false,
        'session_token' => $sessionToken,
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? $phone),
        'role' => $role,
    ]);
}

$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'AOTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = 'APA' . random_token(24);
$now = now_ts();
$expiresAt = $now + 300;

$message = 'Z-Pay Swift admin login OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
$sendRateState = auth_otp_send_rate_state('ADMIN_LOGIN', $otpPhone, $now);
if (empty($sendRateState['ok'])) {
    api_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
        'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
        'send_count' => (int)($sendRateState['send_count'] ?? 0),
        'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
    ], (int)($sendRateState['http_status'] ?? 429));
}

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $otpPhone,
    'country' => $storedPhoneCountry,
    'phone_country' => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
    'dial_code' => $storedPhoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164' => $otpPhone,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'purpose' => 'ADMIN_LOGIN',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => admin_login_mask_phone($otpPhone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'expires_at' => $expiresAt,
    'updated_at' => $now,
] + auth_otp_reset_attempts_patch();

$preAuthRow = [
    'pre_auth_token' => $preAuthToken,
    'uid' => $uid,
    'phone' => $otpPhone,
    'phone_country' => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'browser_timezone' => auth_request_browser_timezone($body),
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'trust_device' => $trustDevice,
    'otp_request_id' => $otpRequestId,
    'purpose' => 'ADMIN_LOGIN',
    'status' => 'OTP_PENDING',
    'created_at' => $now,
    'expires_at' => $expiresAt,
    'updated_at' => $now,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
    @fb_delete('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken);

    api_response(false, 'SERVER_ERROR', 'Failed to prepare OTP verification', [], 500);
}

auth_otp_record_send_rate('ADMIN_LOGIN', $otpPhone, $sendRateState, $now);
$smsResult = auth_send_otp_sms_by_country(
    $storedPhoneCountry,
    $otpPhone,
    $message,
    $otpRequestId,
    'ADMIN_LOGIN',
    $otpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ] + $smsPatch);

    fb_patch('AUTH_ADMIN_LOGIN_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => now_ts(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('ADMIN_LOGIN_OTP_SENT', $otpRequestId, 'Admin login OTP sent', [
        'uid' => $uid,
        'phone' => $otpPhone,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => $pricingCountry,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'ip' => client_ip(),
    ]);
}

api_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => admin_login_mask_phone($otpPhone),
    'expires_in_seconds' => 300,
    'phone_country' => $storedPhoneCountry,
]);
