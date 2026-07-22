<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_login_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string)$value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON'], true);
}

function user_login_mask_phone(string $phone): string
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

function user_login_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function user_login_issue_session(array $user, string $uid, string $deviceId, string $deviceName, array $requestMeta = []): array
{
    return auth_issue_website_user_session($user, $uid, $deviceId, $deviceName, $requestMeta);
}

function user_login_has_valid_trusted_device(string $uid, string $cookieValue): bool
{
    $cookieValue = trim($cookieValue);

    if ($cookieValue === '' || strpos($cookieValue, ':') === false) {
        return false;
    }

    [$selector, $token] = explode(':', $cookieValue, 2);

    $selector = trim($selector);
    $token = trim($token);

    if ($selector === '' || $token === '') {
        return false;
    }

    $row = fb_get('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector);
    if (!is_array($row)) {
        return false;
    }

    $storedHash = trim((string)($row['token_hash'] ?? ''));
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    $expiresAt = (int)($row['expires_at'] ?? 0);

    if ($storedHash === '' || $status !== 'ACTIVE' || $expiresAt < now_ts()) {
        return false;
    }

    if (!hash_equals($storedHash, hash('sha256', $token))) {
        return false;
    }

    fb_patch('AUTH_TRUSTED_DEVICES/' . $uid . '/' . $selector, [
        'last_used_at' => now_ts(),
        'updated_at'   => now_ts(),
    ]);

    return true;
}

$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? ''));
}
if ($phoneCountry === '') {
    $phoneCountry = 'BD';
}

$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$password = (string)($body['password'] ?? '');
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Dashboard'));
$trustDevice = user_login_bool_value($body['trust_device'] ?? true);
$trustedDeviceCookie = trim((string)($body['trusted_device_cookie'] ?? ''));
$requestMeta = [
    'ip' => auth_request_ip($body),
    'ip_country' => auth_request_ip_country($body),
    'user_agent' => auth_request_user_agent($body),
    'browser_timezone' => auth_request_browser_timezone($body),
];

if ($phone === '' || $password === '') {
    api_response(
        false,
        'VALIDATION_ERROR',
        $phone === '' ? auth_phone_validation_message($phoneCountry) : 'Phone and password are required',
        [],
        422
    );
}

$uid = auth_find_uid_by_phone_country($phone, $phoneCountry);
if ($uid === '') {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

$status = strtoupper(trim((string)($user['status'] ?? '')));
$accountStatus = strtoupper(trim((string)($user['account_status'] ?? $status)));
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

if ($accountStatus === 'REVIEW' || $status === 'REVIEW') {
    api_response(false, 'ACCOUNT_REVIEW_REQUIRED', 'Account is pending admin review', [], 403);
}

if ($accountStatus === 'BLOCKED' || $status === 'BLOCKED') {
    api_response(false, 'ACCOUNT_BLOCKED', 'Account is blocked', [], 403);
}

if ($accountStatus === 'REJECTED' || $status === 'REJECTED') {
    api_response(false, 'ACCOUNT_REJECTED', 'Account registration was rejected', [], 403);
}

if ($status !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'User account is not active', [], 403);
}

if (!user_login_allowed_role($role)) {
    api_response(false, 'FORBIDDEN', 'User dashboard access required', [], 403);
}

if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
    api_response(false, 'INVALID_CREDENTIALS', 'Invalid phone or password', [], 401);
}

/*
|--------------------------------------------------------------------------
| Trusted device -> OTP skip
|--------------------------------------------------------------------------
*/
if (user_login_has_valid_trusted_device($uid, $trustedDeviceCookie)) {
    $sessionResult = user_login_issue_session($user, $uid, $deviceId, $deviceName, $requestMeta);
    if (empty($sessionResult['ok'])) {
        api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
    }
    $sessionToken = (string)($sessionResult['session_token'] ?? '');

    if (function_exists('system_log')) {
        system_log('USER_TRUSTED_LOGIN', $uid, 'Trusted device login successful', [
            'uid'         => $uid,
            'phone'       => $phone,
            'device_id'   => $deviceId,
            'device_name' => $deviceName,
        ]);
    }

    api_response(true, 'SUCCESS', 'Trusted device login successful', [
        'require_otp'   => false,
        'session_token' => $sessionToken,
        'uid'           => $uid,
        'name'          => (string)($user['name'] ?? ''),
        'phone'         => (string)($user['phone'] ?? $phone),
    ]);
}

/*
|--------------------------------------------------------------------------
| OTP required
|--------------------------------------------------------------------------
*/
$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'OTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = random_token(24);
$now = now_ts();
$expiresAt = $now + 300;

$message = 'Z-Pay Swift login OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
$sendRateState = auth_otp_send_rate_state('USER_LOGIN', $otpPhone, $now);
if (empty($sendRateState['ok'])) {
    api_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
        'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
        'send_count' => (int)($sendRateState['send_count'] ?? 0),
        'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
    ], (int)($sendRateState['http_status'] ?? 429));
}

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid'            => $uid,
    'phone'          => $otpPhone,
    'country'        => $storedPhoneCountry,
    'phone_country'  => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency'       => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
    'dial_code'      => $storedPhoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164'     => $otpPhone,
    'ip_country'     => (string)$requestMeta['ip_country'],
    'created_ip'     => (string)$requestMeta['ip'],
    'user_agent'     => (string)$requestMeta['user_agent'],
    'purpose'        => 'USER_LOGIN',
    'code_hash'      => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone'   => user_login_mask_phone($otpPhone),
    'status'         => 'SENT',
    'used'           => false,
    'resend_count'   => 0,
    'created_at'     => $now,
    'expires_at'     => $expiresAt,
    'updated_at'     => $now,
] + auth_otp_reset_attempts_patch();

$preAuthRow = [
    'pre_auth_token' => $preAuthToken,
    'uid'            => $uid,
    'phone'          => $otpPhone,
    'phone_country'  => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'ip_country'     => (string)$requestMeta['ip_country'],
    'created_ip'     => (string)$requestMeta['ip'],
    'user_agent'     => (string)$requestMeta['user_agent'],
    'browser_timezone' => (string)$requestMeta['browser_timezone'],
    'device_id'      => $deviceId,
    'device_name'    => $deviceName,
    'trust_device'   => $trustDevice,
    'otp_request_id' => $otpRequestId,
    'purpose'        => 'USER_LOGIN',
    'status'         => 'OTP_PENDING',
    'created_at'     => $now,
    'expires_at'     => $expiresAt,
    'updated_at'     => $now,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_LOGIN_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
    @fb_delete('AUTH_LOGIN_PREAUTH/' . $preAuthToken);

    api_response(false, 'SERVER_ERROR', 'Failed to prepare OTP verification', [], 500);
}

auth_otp_record_send_rate('USER_LOGIN', $otpPhone, $sendRateState, $now);
$smsResult = auth_send_otp_sms_by_country(
    $storedPhoneCountry,
    $otpPhone,
    $message,
    $otpRequestId,
    'USER_LOGIN',
    $otpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status'     => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ] + $smsPatch);

    @fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
        'status'     => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => now_ts(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('USER_LOGIN_OTP_SENT', $otpRequestId, 'User login OTP sent', [
        'uid'         => $uid,
        'phone'       => $otpPhone,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => $pricingCountry,
        'device_id'   => $deviceId,
        'device_name' => $deviceName,
    ]);
}

api_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp'        => true,
    'pre_auth_token'     => $preAuthToken,
    'otp_request_id'     => $otpRequestId,
    'masked_phone'       => user_login_mask_phone($otpPhone),
    'expires_in_seconds' => 300,
    'phone_country'      => $storedPhoneCountry,
]);
