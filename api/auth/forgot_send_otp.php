<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth_sms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_name('zawtopup_subadmin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function auth_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function auth_require_post(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        auth_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function auth_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        auth_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function auth_now(): int
{
    return time();
}

function auth_make_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function auth_normalize_phone(string $phone, string $country = 'BD'): string
{
    return normalize_phone_by_country($phone, $country);
}

function auth_mask_phone(string $phone): string
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

function auth_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['SUBADMIN', 'ADMIN'], true);
}

function auth_find_uid_by_phone(string $phone, string $country): string
{
    return auth_find_uid_by_phone_country($phone, $country);
}

function auth_load_user(string $uid): array
{
    $row = fb_get('USERS/' . $uid);
    return is_array($row) ? $row : [];
}

function auth_session_binding(): string
{
    return hash('sha256', session_id());
}

function auth_get_pending_forgot_session(): array
{
    $row = $_SESSION['subadmin_forgot_pending'] ?? [];
    return is_array($row) ? $row : [];
}

function auth_set_pending_forgot_session(array $row): void
{
    $_SESSION['subadmin_forgot_pending'] = $row;
}

function auth_clear_pending_forgot_session(): void
{
    unset($_SESSION['subadmin_forgot_pending']);
}

function auth_send_forgot_otp_sms(string $phone, string $message): bool
{
    if (function_exists('auth_send_otp_sms')) {
        return (bool) auth_send_otp_sms($phone, $message);
    }

    return false;
}

function auth_cancel_pending_forgot_records(array $pending): void
{
    $otpRequestId = trim((string)($pending['otp_request_id'] ?? ''));
    $preAuthToken = trim((string)($pending['pre_auth_token'] ?? ''));
    $now = auth_now();

    if ($otpRequestId !== '') {
        @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
            'status' => 'CANCELLED',
            'updated_at' => $now,
        ]);
    }

    if ($preAuthToken !== '') {
        @fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, [
            'status' => 'CANCELLED',
            'updated_at' => $now,
        ]);
    }
}

auth_start_session();
auth_require_post();

if (function_exists('api_require_app_key')) {
    api_require_app_key();
}

$body = auth_read_json_body();

$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}
$phone = auth_normalize_phone((string)($body['phone'] ?? ''), $phoneCountry);
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$deviceId = trim((string)($body['device_id'] ?? 'SUBADMIN_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'Subadmin Panel'));
$now = auth_now();

if ($phone === '') {
    auth_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    auth_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

$uid = auth_find_uid_by_phone($phone, $phoneCountry);
if ($uid === '') {
    auth_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$user = auth_load_user($uid);
if (!$user) {
    auth_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$role = strtoupper(trim((string)($user['role'] ?? '')));
$status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
$storedPhoneCountry = auth_phone_country_from_user($user);
$pricingCountry = auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));

if ($storedPhoneCountry !== $phoneCountry) {
    auth_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$otpPhone = normalize_phone_by_country((string)($user['phone'] ?? $phone), $storedPhoneCountry);
if ($otpPhone === '') {
    $otpPhone = $phone;
}

if (!auth_allowed_role($role)) {
    auth_response(false, 'FORBIDDEN', 'Subadmin access required', [], 403);
}

if ($status !== 'ACTIVE') {
    auth_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
}

$purposePrefix = $role === 'ADMIN' ? 'ADMIN' : 'SUBADMIN';
$purpose = $resetType === 'PIN'
    ? $purposePrefix . '_FORGOT_PIN'
    : $purposePrefix . '_FORGOT_PASSWORD';
$smsTemplateKey = $role === 'ADMIN' ? 'ADMIN_RESET' : 'SUBADMIN_RESET';

$pending = auth_get_pending_forgot_session();
$binding = auth_session_binding();

if ($pending) {
        $pendingPhone = auth_normalize_phone((string)($pending['phone'] ?? ''), $storedPhoneCountry);
    $pendingResetType = strtoupper(trim((string)($pending['reset_type'] ?? 'PASSWORD')));
    $pendingExpiresAt = (int)($pending['expires_at'] ?? 0);
    $pendingBinding = trim((string)($pending['session_binding'] ?? ''));

    if (
        $pendingPhone === $phone &&
        $pendingResetType === $resetType &&
        $pendingBinding === $binding &&
        $pendingExpiresAt > $now
    ) {
        $existingPreAuthToken = trim((string)($pending['pre_auth_token'] ?? ''));
        $existingOtpRequestId = trim((string)($pending['otp_request_id'] ?? ''));

        if ($existingPreAuthToken !== '' && $existingOtpRequestId !== '') {
            auth_response(true, 'OTP_REQUIRED', 'OTP already sent. Please verify the code.', [
                'require_otp' => true,
                'reset_token' => $existingPreAuthToken,
                'forgot_token' => $existingPreAuthToken,
                'pre_auth_token' => $existingPreAuthToken,
                'otp_request_id' => $existingOtpRequestId,
                'request_id' => $existingOtpRequestId,
                'masked_phone' => (string)($pending['masked_phone'] ?? auth_mask_phone($phone)),
                'expires_in_seconds' => max(1, $pendingExpiresAt - $now),
                'reset_type' => $resetType,
            ]);
        }
    }

    auth_cancel_pending_forgot_records($pending);
    auth_clear_pending_forgot_session();
}

$otpCode = (string) random_int(100000, 999999);
$otpRequestId = 'FOTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = 'FPA' . auth_make_token(16);
$expiresAt = $now + 300;

if ($resetType === 'PIN') {
    $message = 'Z-Pay Swift PIN reset OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
} else {
    $message = 'Z-Pay Swift password reset OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
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
    'purpose' => $purpose,
    'account_role' => $role,
    'reset_type' => $resetType,
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => auth_mask_phone($otpPhone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'resent_at' => $now,
    'expires_at' => $expiresAt,
];

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
    'otp_request_id' => $otpRequestId,
    'purpose' => $purpose,
    'reset_type' => $resetType,
    'status' => 'OTP_PENDING',
    'account_role' => $role,
    'created_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_FORGOT_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
    @fb_delete('AUTH_FORGOT_PREAUTH/' . $preAuthToken);
    auth_response(false, 'SERVER_ERROR', 'Failed to prepare OTP verification', [], 500);
}

$smsResult = auth_send_otp_sms_by_country(
    $storedPhoneCountry,
    $otpPhone,
    $message,
    $otpRequestId,
    $smsTemplateKey,
    $otpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => auth_now(),
    ] + $smsPatch);

    @fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => auth_now(),
    ]);

    auth_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => auth_now(),
] + $smsPatch);

$pendingSession = [
    'uid' => $uid,
    'phone' => $otpPhone,
    'phone_country' => $storedPhoneCountry,
    'masked_phone' => auth_mask_phone($otpPhone),
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'purpose' => $purpose,
    'reset_type' => $resetType,
    'created_at' => $now,
    'expires_at' => $expiresAt,
    'session_binding' => $binding,
];

auth_set_pending_forgot_session($pendingSession);

if (function_exists('system_log')) {
    system_log('SUBADMIN_FORGOT_OTP_SENT', $otpRequestId, 'Subadmin forgot OTP sent', [
        'uid' => $uid,
        'phone' => $otpPhone,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => $pricingCountry,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'reset_type' => $resetType,
    ]);
}

auth_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'reset_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'request_id' => $otpRequestId,
    'masked_phone' => auth_mask_phone($otpPhone),
    'expires_in_seconds' => 300,
    'reset_type' => $resetType,
    'phone_country' => $storedPhoneCountry,
]);
