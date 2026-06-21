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

function auth_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function auth_mask_phone(string $phone): string
{
    $phone = auth_normalize_phone($phone);
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function auth_get_token_from_body(array $body): string
{
    return trim((string)(
        $body['pre_auth_token']
        ?? $body['reset_token']
        ?? $body['forgot_token']
        ?? ''
    ));
}

function auth_send_forgot_otp_sms(string $phone, string $message): bool
{
    if (function_exists('auth_send_otp_sms')) {
        return (bool) auth_send_otp_sms($phone, $message);
    }
    return false;
}

function auth_set_pending_forgot_session(array $row): void
{
    $_SESSION['subadmin_forgot_pending'] = $row;
}

auth_start_session();
auth_require_post();

if (function_exists('api_require_app_key')) {
    api_require_app_key();
}

$body = auth_read_json_body();

$preAuthToken = auth_get_token_from_body($body);
$otpRequestId = trim((string)($body['otp_request_id'] ?? $body['request_id'] ?? ''));
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$now = auth_now();

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    auth_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

if ($preAuthToken === '' || $otpRequestId === '') {
    auth_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
}

$preAuthRow = fb_get('AUTH_FORGOT_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow)) {
    auth_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));
if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
    auth_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
    auth_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

$preAuthExpiresAt = (int)($preAuthRow['expires_at'] ?? 0);
if ($preAuthExpiresAt <= $now) {
    @fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    auth_response(false, 'OTP_EXPIRED', 'OTP expired. Please start again.', [], 410);
}

$storedResetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? $resetType)));
if (!in_array($storedResetType, ['PASSWORD', 'PIN'], true)) {
    $storedResetType = $resetType;
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($preAuthRow['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($preAuthRow['phone'] ?? '')) ?: 'BD';
}
$phone = normalize_phone_by_country((string)($preAuthRow['phone'] ?? ''), $phoneCountry);

if ($uid === '' || $phone === '') {
    auth_response(false, 'FORGOT_SESSION_INVALID', 'Forgot session is invalid. Please start again.', [], 400);
}

$accountRole = strtoupper(trim((string)($preAuthRow['account_role'] ?? '')));
if ($accountRole === '') {
    $forgotUser = fb_get('USERS/' . $uid);
    $accountRole = is_array($forgotUser)
        ? strtoupper(trim((string)($forgotUser['role'] ?? 'SUBADMIN')))
        : 'SUBADMIN';
}
$smsTemplateKey = $accountRole === 'ADMIN' ? 'ADMIN_RESET' : 'SUBADMIN_RESET';
$purposePrefix = $accountRole === 'ADMIN' ? 'ADMIN' : 'SUBADMIN';
$forgotPurpose = $storedResetType === 'PIN'
    ? $purposePrefix . '_FORGOT_PIN'
    : $purposePrefix . '_FORGOT_PASSWORD';

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    auth_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

$otpUid = trim((string)($otpRow['uid'] ?? ''));
if ($otpUid === '' || $otpUid !== $uid) {
    auth_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account', [], 400);
}

if (!empty($otpRow['used'])) {
    auth_response(false, 'OTP_ALREADY_USED', 'OTP already used. Please start again.', [], 400);
}

$resendState = auth_otp_resend_state($otpRow, $now);
if (empty($resendState['ok'])) {
    auth_response(false, (string)$resendState['code'], (string)$resendState['message'], [
        'retry_after_seconds' => (int)($resendState['retry_after_seconds'] ?? 0),
        'resend_count' => (int)($resendState['resend_count'] ?? 0),
        'resend_limit' => (int)($resendState['resend_limit'] ?? auth_otp_resend_limit()),
    ], (int)($resendState['http_status'] ?? 429));
}

$newOtp = (string) random_int(100000, 999999);
$newExpiresAt = $now + 300;

$message = $storedResetType === 'PIN'
    ? 'Z-Pay Swift PIN reset OTP is ' . $newOtp . '. Valid for 5 minutes. Do not share this code.'
    : 'Z-Pay Swift password reset OTP is ' . $newOtp . '. Valid for 5 minutes. Do not share this code.';

$updatedOtpRow = [
    'code_hash' => password_hash($newOtp, PASSWORD_DEFAULT),
    'status' => 'RESENT',
    'used' => false,
    'resent_at' => $now,
    'updated_at' => $now,
    'expires_at' => $newExpiresAt,
    'reset_type' => $storedResetType,
    'purpose' => $forgotPurpose,
    'masked_phone' => auth_mask_phone($phone),
    'resend_count' => (int)($resendState['resend_count'] ?? 0) + 1,
    'account_role' => $accountRole,
    'purpose' => $forgotPurpose,
] + auth_otp_reset_attempts_patch();

$updatedPreAuthRow = [
    'status' => 'OTP_PENDING',
    'updated_at' => $now,
    'expires_at' => $newExpiresAt,
    'reset_type' => $storedResetType,
    'account_role' => $accountRole,
];

$okOtp = fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, $updatedOtpRow);
$okPre = $okOtp ? fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, $updatedPreAuthRow) : false;

if (!($okOtp && $okPre)) {
    auth_response(false, 'SERVER_ERROR', 'Failed to prepare resend OTP', [], 500);
}

$smsResult = auth_send_otp_sms_by_country(
    $phoneCountry,
    $phone,
    $message,
    $otpRequestId,
    $smsTemplateKey,
    $newOtp
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
    'phone_country' => $phoneCountry,
    'country' => $phoneCountry,
    'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164' => $phone,
    'updated_at' => auth_now(),
] + $smsPatch);

$pendingSession = [
    'uid' => $uid,
    'phone' => $phone,
    'masked_phone' => auth_mask_phone($phone),
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'device_id' => (string)($preAuthRow['device_id'] ?? 'SUBADMIN_WEB'),
    'device_name' => (string)($preAuthRow['device_name'] ?? 'Subadmin Panel'),
    'purpose' => $forgotPurpose,
    'reset_type' => $storedResetType,
    'created_at' => (int)($preAuthRow['created_at'] ?? $now),
    'expires_at' => $newExpiresAt,
    'session_binding' => hash('sha256', session_id()),
];

auth_set_pending_forgot_session($pendingSession);

if (function_exists('system_log')) {
    system_log(
        'SUBADMIN_FORGOT_OTP_RESENT',
        $otpRequestId,
        'Subadmin forgot OTP resent',
        [
            'uid' => $uid,
            'reset_type' => $storedResetType,
        ]
    );
}

auth_response(true, 'SUCCESS', 'OTP resent successfully', [
    'require_otp' => true,
    'reset_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'request_id' => $otpRequestId,
    'masked_phone' => auth_mask_phone($phone),
    'expires_in_seconds' => 300,
    'reset_type' => $storedResetType,
]);
