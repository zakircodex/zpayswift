<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function forgot_verify_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function forgot_legacy_verify_otp(array $body, string $preAuthToken, string $otpRequestId, string $otp): void
{
    $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
    $now = forgot_verify_now();

    if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
        api_response(false, 'VALIDATION_ERROR', 'Invalid reset type.', [], 422);
    }

    if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
        api_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required.', [], 422);
    }

    $preAuthRow = fb_get('AUTH_FORGOT_PREAUTH/' . $preAuthToken);
    if (!is_array($preAuthRow)) {
        api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
    }

    $storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));
    if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
        api_response(false, 'OTP_MISMATCH', 'OTP request mismatch.', [], 400);
    }

    $preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
    if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
        api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
    }

    if ((int)($preAuthRow['expires_at'] ?? 0) <= $now) {
        @fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, [
            'status' => 'EXPIRED',
            'updated_at' => $now,
        ]);
        api_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
    }

    $storedResetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? $resetType)));
    if ($storedResetType !== $resetType) {
        api_response(false, 'RESET_TYPE_MISMATCH', 'Reset type mismatch.', [], 400);
    }

    $uid = trim((string)($preAuthRow['uid'] ?? ''));
    $otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
    if ($uid === '' || !is_array($otpRow) || trim((string)($otpRow['uid'] ?? '')) !== $uid) {
        api_response(false, 'OTP_NOT_FOUND', 'OTP request not found.', [], 404);
    }

    if (!empty($otpRow['used'])) {
        api_response(false, 'OTP_ALREADY_USED', 'OTP already used.', [], 400);
    }

    $otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
    if (!in_array($otpStatus, ['SENT', 'RESENT', 'LOCKED'], true)) {
        api_response(false, 'OTP_INVALID_STATUS', 'OTP is not active.', [], 400);
    }

    if ((int)($otpRow['expires_at'] ?? 0) <= $now) {
        @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
            'status' => 'EXPIRED',
            'updated_at' => $now,
        ]);
        api_response(false, 'OTP_EXPIRED', 'OTP expired. Please send OTP again.', [], 410);
    }

    $lockState = auth_otp_lock_state($otpRow);
    if (!empty($lockState['locked'])) {
        api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
            'attempts_left' => 0,
        ], 423);
    }

    $codeHash = trim((string)($otpRow['code_hash'] ?? ''));
    if ($codeHash === '' || !password_verify($otp, $codeHash)) {
        $failedState = auth_otp_record_failed_attempt($otpRequestId, $otpRow, $now);
        if (!empty($failedState['locked'])) {
            api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
                'attempts_left' => 0,
            ], 423);
        }
        api_response(false, 'OTP_INVALID', 'Invalid OTP.', [
            'attempts_left' => (int)($failedState['attempts_left'] ?? 0),
        ], 400);
    }

    $userRow = fb_get('USERS/' . $uid);
    if (!is_array($userRow)) {
        api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
    }

    $update = ['updated_at' => $now];
    if ($resetType === 'PIN') {
        $newPin = trim((string)($body['new_pin'] ?? ''));
        $confirmPin = trim((string)($body['confirm_pin'] ?? ''));
        if ($newPin === '' || $confirmPin === '') {
            api_response(false, 'VALIDATION_ERROR', 'New PIN and confirm PIN are required.', [], 422);
        }
        if ($newPin !== $confirmPin) {
            api_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match.', [], 422);
        }
        if (!preg_match('/^\d{4,8}$/', $newPin)) {
            api_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits.', [], 422);
        }
        $update['pin_hash'] = password_hash($newPin, PASSWORD_DEFAULT);
        $update['pin_updated_at'] = $now;
    } else {
        $newPassword = (string)($body['new_password'] ?? '');
        $confirmPassword = (string)($body['confirm_password'] ?? '');
        if ($newPassword === '' || $confirmPassword === '') {
            api_response(false, 'VALIDATION_ERROR', 'New password and confirm password are required.', [], 422);
        }
        if ($newPassword !== $confirmPassword) {
            api_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match.', [], 422);
        }
        if (strlen($newPassword) < 6) {
            api_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters.', [], 422);
        }
        $update['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $update['password_updated_at'] = $now;
    }

    if (!fb_patch('USERS/' . $uid, $update)) {
        api_response(false, 'SERVER_ERROR', 'Failed to update account credentials.', [], 500);
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

    if (function_exists('system_log')) {
        system_log('SUBADMIN_FORGOT_RESET_COMPLETED', $otpRequestId, 'Subadmin forgot reset completed', [
            'uid' => $uid,
            'reset_type' => $resetType,
        ]);
    }

    api_response(true, 'SUCCESS', ($resetType === 'PIN' ? 'PIN' : 'Password') . ' reset successful.', [
        'reset_type' => $resetType,
        'uid' => $uid,
    ]);
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
$otpRequestId = trim((string)($body['otp_request_id'] ?? $body['request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));

if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
    api_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required.', [], 422);
}

$now = forgot_verify_now();
$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow) || (int)($preAuthRow['expires_at'] ?? 0) <= $now) {
    $legacyPreAuth = $preAuthToken !== '' ? fb_get('AUTH_FORGOT_PREAUTH/' . $preAuthToken) : null;
    if (is_array($legacyPreAuth)) {
        forgot_legacy_verify_otp($body, $preAuthToken, $otpRequestId, $otp);
    }
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

if (empty($preAuthRow['identity_verified']) || empty($preAuthRow['biometric_verified'])) {
    api_response(false, 'FORGOT_SESSION_INVALID', 'Forgot verification steps are incomplete.', [], 409);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));
if ($storedOtpRequestId === '' || $storedOtpRequestId !== $otpRequestId) {
    api_response(false, 'OTP_MISMATCH', 'OTP request mismatch.', [], 400);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
if (!empty($preAuthRow['otp_verified'])) {
    api_response(true, 'OTP_VERIFIED', 'OTP already verified.', [
        'forgot_token' => $preAuthToken,
        'reset_token' => $preAuthToken,
        'pre_auth_token' => $preAuthToken,
        'otp_request_id' => $otpRequestId,
        'otp_verified' => true,
    ]);
}

$otpRow = fb_get('AUTH_OTP_REQUESTS/' . $otpRequestId);
if (!is_array($otpRow)) {
    api_response(false, 'OTP_NOT_FOUND', 'OTP request not found.', [], 404);
}

if (trim((string)($otpRow['uid'] ?? '')) !== $uid) {
    api_response(false, 'OTP_UID_MISMATCH', 'OTP does not match this account.', [], 400);
}

if (!empty($otpRow['used'])) {
    api_response(false, 'OTP_ALREADY_USED', 'OTP already used.', [], 400);
}

$otpStatus = strtoupper(trim((string)($otpRow['status'] ?? '')));
if (!in_array($otpStatus, ['SENT', 'RESENT', 'LOCKED'], true)) {
    api_response(false, 'OTP_INVALID_STATUS', 'OTP is not active.', [], 400);
}

if ((int)($otpRow['expires_at'] ?? 0) <= $now) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);
    api_response(false, 'OTP_EXPIRED', 'OTP expired. Please request a new OTP.', [], 410);
}

$lockState = auth_otp_lock_state($otpRow);
if (!empty($lockState['locked'])) {
    api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
        'attempts_left' => 0,
    ], 423);
}

$codeHash = trim((string)($otpRow['code_hash'] ?? ''));
if ($codeHash === '' || !password_verify($otp, $codeHash)) {
    $failedState = auth_otp_record_failed_attempt($otpRequestId, $otpRow, $now);
    if (!empty($failedState['locked'])) {
        api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
            'attempts_left' => 0,
        ], 423);
    }

    api_response(false, 'OTP_INVALID', 'Wrong OTP. Please try again.', [
        'attempts_left' => (int)($failedState['attempts_left'] ?? 0),
    ], 400);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'used' => true,
    'used_at' => $now,
    'status' => 'VERIFIED',
    'updated_at' => $now,
]);

@fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'otp_verified' => true,
    'otp_verified_at' => $now,
    'status' => 'OTP_VERIFIED',
    'updated_at' => $now,
    'expires_at' => $now + 900,
]);

api_response(true, 'OTP_VERIFIED', 'OTP verified successfully.', [
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'pre_auth_token' => $preAuthToken,
    'otp_verified' => true,
]);
