<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/roles.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_reg_confirm_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_reg_confirm_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_reg_confirm_email_key(string $email): string
{
    return md5(strtolower(trim($email)));
}

function user_reg_confirm_find_uid_by_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
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

function user_reg_confirm_find_uid_by_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    $row = fb_get('USER_INDEX/EMAIL/' . user_reg_confirm_email_key($email));

    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function user_reg_confirm_default_role_settings(string $role = 'USER'): array
{
    if (function_exists('role_default_settings')) {
        $row = role_default_settings($role);
        if (is_array($row)) {
            return $row;
        }
    }

    return [
        'commission_per_1000' => 0,
        'api_enabled' => false,
        'topup_enabled' => true,
        'bundle_enabled' => false,
        'min_amount' => 20,
        'max_amount' => 500,
        'updated_at' => user_reg_confirm_now(),
    ];
}

function user_reg_confirm_delete_if_exists(string $path): void
{
    if (function_exists('fb_delete')) {
        @fb_delete($path);
    }
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    user_reg_confirm_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
}

$now = user_reg_confirm_now();

$preAuthRow = fb_get('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken);

if (!is_array($preAuthRow)) {
    user_reg_confirm_response(false, 'REGISTER_SESSION_EXPIRED', 'Register session expired. Please start again.', [], 410);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));

if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
    user_reg_confirm_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));

if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
    user_reg_confirm_response(false, 'REGISTER_SESSION_EXPIRED', 'Register session expired. Please start again.', [], 410);
}

$preAuthExpiresAt = (int)($preAuthRow['expires_at'] ?? 0);

if ($preAuthExpiresAt <= $now) {
    @fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    user_reg_confirm_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$name = trim((string)($preAuthRow['name'] ?? ''));
$phone = preg_replace('/\D+/', '', trim((string)($preAuthRow['phone'] ?? ''))) ?? '';
$email = strtolower(trim((string)($preAuthRow['email'] ?? '')));
$passwordHash = trim((string)($preAuthRow['password_hash'] ?? ''));
$pinHash = trim((string)($preAuthRow['pin_hash'] ?? ''));

if ($uid === '' || $name === '' || $phone === '' || $email === '' || $passwordHash === '' || $pinHash === '') {
    user_reg_confirm_response(false, 'REGISTER_SESSION_INVALID', 'Register session is invalid. Please start again.', [], 400);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);

if (!is_array($otpRow)) {
    user_reg_confirm_response(false, 'OTP_NOT_FOUND', 'OTP request not found', [], 404);
}

if (trim((string)($otpRow['uid'] ?? '')) !== $uid) {
    user_reg_confirm_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this registration', [], 400);
}

if (!empty($otpRow['used'])) {
    user_reg_confirm_response(false, 'OTP_ALREADY_USED', 'OTP already used', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));

if (!in_array($otpStatus, ['SENT', 'RESENT'], true)) {
    user_reg_confirm_response(false, 'OTP_INVALID_STATUS', 'OTP is not active', [], 400);
}

$otpExpiresAt = (int)($otpRow['expires_at'] ?? 0);

if ($otpExpiresAt <= $now) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    user_reg_confirm_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
}

$codeHash = trim((string)($otpRow['code_hash'] ?? ''));

if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'failed_attempt_at' => $now,
        'updated_at' => $now,
    ]);

    user_reg_confirm_response(false, 'OTP_INVALID', 'Invalid OTP', [], 400);
}

if (user_reg_confirm_find_uid_by_phone($phone) !== '') {
    user_reg_confirm_response(false, 'DUPLICATE_PHONE', 'Phone number already registered', [], 409);
}

if (user_reg_confirm_find_uid_by_email($email) !== '') {
    user_reg_confirm_response(false, 'DUPLICATE_EMAIL', 'Email already registered', [], 409);
}

$userRow = [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'role' => 'USER',
    'status' => 'ACTIVE',
    'password_hash' => $passwordHash,
    'pin_hash' => $pinHash,
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => 0,
    'created_by_admin' => false,
    'parent_subadmin_uid' => '',
    'created_by_uid' => '',
    'created_by_role' => 'SELF',
    'register_source' => 'USER_WEB_OTP',
];

$walletRow = [
    'available_balance' => 0,
    'hold_balance' => 0,
    'total_topup_spent' => 0,
    'total_bundle_spent' => 0,
    'total_refund' => 0,
    'updated_at' => $now,
];

$roleSettings = user_reg_confirm_default_role_settings('USER');
$roleSettings['api_enabled'] = false;
$roleSettings['updated_at'] = $now;

$emailIndexKey = user_reg_confirm_email_key($email);

$okUser = fb_put('USERS/' . $uid, $userRow);
$okWallet = $okUser ? fb_put('USER_WALLETS/' . $uid, $walletRow) : false;
$okRole = $okWallet ? fb_put('USER_ROLE_SETTINGS/' . $uid, $roleSettings) : false;
$okPhone = $okRole ? fb_put('USER_INDEX/PHONE/' . $phone, $uid) : false;
$okEmail = $okPhone ? fb_put('USER_INDEX/EMAIL/' . $emailIndexKey, $uid) : false;

if (!($okUser && $okWallet && $okRole && $okPhone && $okEmail)) {
    user_reg_confirm_delete_if_exists('USERS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_WALLETS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_ROLE_SETTINGS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_INDEX/PHONE/' . $phone);
    user_reg_confirm_delete_if_exists('USER_INDEX/EMAIL/' . $emailIndexKey);

    user_reg_confirm_response(false, 'SERVER_ERROR', 'Failed to create account', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

@fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, [
    'status' => 'COMPLETED',
    'verified_at' => $now,
    'completed_at' => $now,
    'updated_at' => $now,
]);

if (function_exists('system_log')) {
    system_log('USER_REGISTER_COMPLETED', $uid, 'User register completed with OTP', [
        'uid' => $uid,
        'phone' => $phone,
        'email' => $email,
    ]);
}

user_reg_confirm_response(true, 'SUCCESS', 'Account created successfully', [
    'uid' => $uid,
    'role' => 'USER',
    'phone' => $phone,
    'email' => $email,
]);