<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

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

function auth_get_token_from_body(array $body): string
{
    return trim((string)(
        $body['pre_auth_token']
        ?? $body['reset_token']
        ?? $body['forgot_token']
        ?? ''
    ));
}

function auth_clear_pending_forgot_session(): void
{
    unset($_SESSION['subadmin_forgot_pending']);
}

auth_start_session();
auth_require_post();

if (function_exists('api_require_app_key')) {
    api_require_app_key();
}

$body = auth_read_json_body();

$preAuthToken = auth_get_token_from_body($body);
$otpRequestId = trim((string)($body['otp_request_id'] ?? $body['request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$now = auth_now();

if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
    auth_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    auth_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
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

    auth_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
}

$storedResetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? $resetType)));
if (!in_array($storedResetType, ['PASSWORD', 'PIN'], true)) {
    $storedResetType = $resetType;
}

if ($storedResetType !== $resetType) {
    auth_response(false, 'RESET_TYPE_MISMATCH', 'Reset type mismatch', [], 400);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
if ($uid === '') {
    auth_response(false, 'FORGOT_SESSION_INVALID', 'Forgot session is invalid. Please start again.', [], 400);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    auth_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

$otpUid = trim((string)($otpRow['uid'] ?? ''));
if ($otpUid === '' || $otpUid !== $uid) {
    auth_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
if (!in_array($otpStatus, ['SENT', 'RESENT'], true)) {
    auth_response(false, 'OTP_INVALID_STATUS', 'OTP is not active', [], 400);
}

if (!empty($otpRow['used'])) {
    auth_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$otpExpiresAt = (int)($otpRow['expires_at'] ?? 0);
if ($otpExpiresAt <= $now) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    auth_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
}

$codeHash = trim((string)($otpRow['code_hash'] ?? ''));
if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'failed_attempt_at' => $now,
        'updated_at' => $now,
    ]);

    auth_response(false, 'OTP_INVALID', 'Invalid OTP', [], 400);
}

$userRow = fb_get('USERS/' . $uid);
if (!is_array($userRow)) {
    auth_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$update = [
    'updated_at' => $now,
];

if ($resetType === 'PIN') {
    $newPin = trim((string)($body['new_pin'] ?? ''));
    $confirmPin = trim((string)($body['confirm_pin'] ?? ''));

    if ($newPin === '' || $confirmPin === '') {
        auth_response(false, 'VALIDATION_ERROR', 'New PIN and confirm PIN are required', [], 422);
    }

    if ($newPin !== $confirmPin) {
        auth_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match', [], 422);
    }

    if (!preg_match('/^\d{4,8}$/', $newPin)) {
        auth_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits', [], 422);
    }

    $update['pin_hash'] = password_hash($newPin, PASSWORD_DEFAULT);
    $update['pin_updated_at'] = $now;
} else {
    $newPassword = (string)($body['new_password'] ?? '');
    $confirmPassword = (string)($body['confirm_password'] ?? '');

    if ($newPassword === '' || $confirmPassword === '') {
        auth_response(false, 'VALIDATION_ERROR', 'New password and confirm password are required', [], 422);
    }

    if ($newPassword !== $confirmPassword) {
        auth_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match', [], 422);
    }

    if (strlen($newPassword) < 6) {
        auth_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters', [], 422);
    }

    $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $update['password_updated_at'] = $now;
}

$okUser = fb_patch('USERS/' . $uid, $update);

if (!$okUser) {
    auth_response(false, 'SERVER_ERROR', 'Failed to update account credentials', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

@fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, [
    'status' => 'COMPLETED',
    'verified_at' => $now,
    'completed_at' => $now,
    'updated_at' => $now,
]);

auth_clear_pending_forgot_session();

if (function_exists('system_log')) {
    system_log(
        'SUBADMIN_FORGOT_RESET_COMPLETED',
        $otpRequestId,
        'Subadmin forgot reset completed',
        [
            'uid' => $uid,
            'reset_type' => $resetType,
        ]
    );
}

auth_response(true, 'SUCCESS', ($resetType === 'PIN' ? 'PIN' : 'Password') . ' reset successful', [
    'reset_type' => $resetType,
    'uid' => $uid,
]);
