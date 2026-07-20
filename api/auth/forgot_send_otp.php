<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth_sms.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function forgot_otp_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function forgot_otp_mask_phone(string $phone): string
{
    return function_exists('auth_app_mask_phone') ? auth_app_mask_phone($phone) : substr($phone, 0, 3) . '***' . substr($phone, -3);
}

function forgot_legacy_token(int $bytes = 16): string
{
    return 'FPA' . strtoupper(bin2hex(random_bytes($bytes)));
}

function forgot_legacy_allowed_role(string $role): bool
{
    return in_array(strtoupper(trim($role)), ['SUBADMIN', 'ADMIN'], true);
}

function forgot_otp_require_same_device(array $body, array $preAuthRow): void
{
    $storedDeviceId = trim((string)($preAuthRow['device_id'] ?? ''));
    $requestDeviceId = auth_app_device_id($body, 'ANDROID_FORGOT');
    if ($storedDeviceId !== '' && $requestDeviceId !== '' && $storedDeviceId !== $requestDeviceId) {
        api_response(false, 'DEVICE_MISMATCH', 'Device mismatch for this reset session.', [], 400);
    }
}

function forgot_legacy_send_otp(array $body): void
{
    $phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
    if ($phoneCountry === '') {
        $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
    }

    $phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
    $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
    $now = forgot_otp_now();

    if ($phone === '') {
        api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
    }

    if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
        api_response(false, 'VALIDATION_ERROR', 'Invalid reset type.', [], 422);
    }

    $uid = auth_find_uid_by_phone_country($phone, $phoneCountry);
    $user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
    if (!is_array($user)) {
        api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
    }

    $role = strtoupper(trim((string)($user['role'] ?? '')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $storedPhoneCountry = auth_phone_country_from_user($user);
    if (!forgot_legacy_allowed_role($role) || $storedPhoneCountry !== $phoneCountry) {
        api_response(false, 'FORBIDDEN', 'Account recovery is not allowed.', [], 403);
    }

    if ($status !== 'ACTIVE') {
        api_response(false, 'FORBIDDEN', 'Account is inactive.', [], 403);
    }

    $otpPhone = normalize_phone_by_country((string)($user['phone'] ?? $phone), $storedPhoneCountry) ?: $phone;
    $purposePrefix = $role === 'ADMIN' ? 'ADMIN' : 'SUBADMIN';
    $purpose = $resetType === 'PIN'
        ? $purposePrefix . '_FORGOT_PIN'
        : $purposePrefix . '_FORGOT_PASSWORD';
    $smsTemplateKey = $role === 'ADMIN' ? 'ADMIN_RESET' : 'SUBADMIN_RESET';
    $sendRateState = auth_otp_send_rate_state($purpose, $otpPhone, $now);
    if (empty($sendRateState['ok'])) {
        api_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
            'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
            'send_count' => (int)($sendRateState['send_count'] ?? 0),
            'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
        ], (int)($sendRateState['http_status'] ?? 429));
    }

    $otpCode = (string)random_int(100000, 999999);
    $otpRequestId = 'FOTP' . strtoupper(bin2hex(random_bytes(6)));
    $preAuthToken = forgot_legacy_token();
    $expiresAt = $now + 300;
    $pricingCountry = auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));
    $message = $resetType === 'PIN'
        ? 'Z-Pay Swift PIN reset OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.'
        : 'Z-Pay Swift password reset OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';

    $otpRow = [
        'otp_request_id' => $otpRequestId,
        'uid' => $uid,
        'phone' => $otpPhone,
        'country' => $storedPhoneCountry,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => $pricingCountry,
        'service_country' => $pricingCountry,
        'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
        'dial_code' => $storedPhoneCountry === 'BD' ? '+880' : '+60',
        'phone_e164' => $otpPhone,
        'ip_country' => auth_request_ip_country($body),
        'created_ip' => auth_request_ip($body),
        'user_agent' => auth_request_user_agent($body),
        'browser_timezone' => auth_request_browser_timezone($body),
        'purpose' => $purpose,
        'account_role' => $role,
        'reset_type' => $resetType,
        'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
        'masked_phone' => forgot_otp_mask_phone($otpPhone),
        'status' => 'SENT',
        'used' => false,
        'resend_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expiresAt,
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
        'device_id' => trim((string)($body['device_id'] ?? 'SUBADMIN_WEB')),
        'device_name' => trim((string)($body['device_name'] ?? 'Subadmin Panel')),
        'otp_request_id' => $otpRequestId,
        'purpose' => $purpose,
        'reset_type' => $resetType,
        'status' => 'OTP_PENDING',
        'account_role' => $role,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expiresAt,
    ];

    $okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
    $okPre = $okOtp ? fb_put('AUTH_FORGOT_PREAUTH/' . $preAuthToken, $preAuthRow) : false;
    if (!($okOtp && $okPre)) {
        @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
        @fb_delete('AUTH_FORGOT_PREAUTH/' . $preAuthToken);
        api_response(false, 'SERVER_ERROR', 'Failed to prepare OTP verification.', [], 500);
    }

    auth_otp_record_send_rate($purpose, $otpPhone, $sendRateState, $now);
    $smsResult = auth_send_otp_sms_by_country($storedPhoneCountry, $otpPhone, $message, $otpRequestId, $smsTemplateKey, $otpCode);
    $smsPatch = auth_sms_result_log_fields($smsResult);
    if (empty($smsResult['ok'])) {
        @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
            'status' => 'SMS_FAILED',
            'updated_at' => forgot_otp_now(),
        ] + $smsPatch);
        @fb_patch('AUTH_FORGOT_PREAUTH/' . $preAuthToken, [
            'status' => 'SMS_FAILED',
            'updated_at' => forgot_otp_now(),
        ]);
        api_response(false, 'SMS_FAILED', 'Failed to send OTP SMS.', [], 502);
    }

    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'updated_at' => forgot_otp_now(),
    ] + $smsPatch);

    api_response(true, 'OTP_REQUIRED', 'OTP sent successfully.', [
        'require_otp' => true,
        'reset_token' => $preAuthToken,
        'forgot_token' => $preAuthToken,
        'pre_auth_token' => $preAuthToken,
        'otp_request_id' => $otpRequestId,
        'request_id' => $otpRequestId,
        'masked_phone' => forgot_otp_mask_phone($otpPhone),
        'expires_in_seconds' => 300,
        'reset_type' => $resetType,
        'phone_country' => $storedPhoneCountry,
    ]);
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
if ($preAuthToken === '') {
    forgot_legacy_send_otp($body);
}

$now = forgot_otp_now();
$preAuthRow = fb_get('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
if (!is_array($preAuthRow) || (int)($preAuthRow['expires_at'] ?? 0) <= $now) {
    api_response(false, 'FORGOT_SESSION_EXPIRED', 'Forgot session expired. Please start again.', [], 410);
}

forgot_otp_require_same_device($body, $preAuthRow);

if (empty($preAuthRow['identity_verified'])) {
    api_response(false, 'IDENTITY_REQUIRED', 'Please verify your NID/Passport first.', [], 409);
}

$biometricOk = !empty($body['biometric_verified'])
    || !empty($body['screen_lock_verified'])
    || !empty($body['device_credential_verified'])
    || !empty($preAuthRow['biometric_verified']);
if (!$biometricOk) {
    api_response(false, 'BIOMETRIC_REQUIRED', 'Please verify fingerprint or screen lock first.', [], 409);
}

$uid = trim((string)($preAuthRow['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

auth_app_guard_user_login($user);

$phoneCountry = auth_phone_country_from_user($user);
$phone = normalize_phone_by_country((string)($user['phone'] ?? $preAuthRow['phone'] ?? ''), $phoneCountry);
if ($phone === '') {
    $phone = normalize_phone_by_country((string)($preAuthRow['phone'] ?? ''), $phoneCountry);
}
if ($phone === '') {
    api_response(false, 'FORGOT_SESSION_INVALID', 'Forgot session is invalid. Please start again.', [], 400);
}

$purpose = 'USER_FORGOT_PASSWORD_PIN';
$oldOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));
if ($oldOtpRequestId !== '') {
    if (!empty($preAuthRow['otp_verified'])) {
        api_response(true, 'OTP_ALREADY_VERIFIED', 'OTP already verified.', [
            'require_otp' => false,
            'otp_verified' => true,
            'forgot_token' => $preAuthToken,
            'reset_token' => $preAuthToken,
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $oldOtpRequestId,
            'request_id' => $oldOtpRequestId,
            'masked_phone' => forgot_otp_mask_phone($phone),
            'reset_type' => 'PASSWORD_PIN',
            'phone_country' => $phoneCountry,
        ]);
    }

    $oldOtpRow = fb_get('AUTH_OTP_REQUESTS/' . $oldOtpRequestId);
    if (is_array($oldOtpRow)) {
        $oldStatus = strtoupper(trim((string)($oldOtpRow['status'] ?? '')));
        $oldExpiresAt = (int)($oldOtpRow['expires_at'] ?? 0);
        $oldSameUser = trim((string)($oldOtpRow['uid'] ?? '')) === $uid;
        if ($oldSameUser && empty($oldOtpRow['used']) && in_array($oldStatus, ['SENT', 'RESENT'], true) && $oldExpiresAt > $now) {
            @fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
                'biometric_verified' => true,
                'biometric_verified_at' => $now,
                'status' => 'OTP_PENDING',
                'updated_at' => $now,
                'expires_at' => max((int)($preAuthRow['expires_at'] ?? 0), $oldExpiresAt),
            ]);
            api_response(true, 'OTP_STILL_VALID', 'OTP already sent and still valid.', [
                'require_otp' => true,
                'forgot_token' => $preAuthToken,
                'reset_token' => $preAuthToken,
                'pre_auth_token' => $preAuthToken,
                'otp_request_id' => $oldOtpRequestId,
                'request_id' => $oldOtpRequestId,
                'masked_phone' => forgot_otp_mask_phone($phone),
                'expires_in_seconds' => max(1, $oldExpiresAt - $now),
                'remaining_seconds' => max(1, $oldExpiresAt - $now),
                'reset_type' => 'PASSWORD_PIN',
                'phone_country' => $phoneCountry,
            ]);
        }

        if ($oldSameUser && empty($oldOtpRow['used']) && $oldExpiresAt <= $now) {
            @fb_patch('AUTH_OTP_REQUESTS/' . $oldOtpRequestId, [
                'status' => 'EXPIRED',
                'updated_at' => $now,
            ]);
        } elseif ($oldSameUser && empty($oldOtpRow['used'])) {
            @fb_patch('AUTH_OTP_REQUESTS/' . $oldOtpRequestId, [
                'status' => 'CANCELLED',
                'updated_at' => $now,
            ]);
        }
    }
}

$sendRateState = auth_otp_send_rate_state($purpose, $phone, $now);
if (empty($sendRateState['ok'])) {
    api_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
        'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
        'send_count' => (int)($sendRateState['send_count'] ?? 0),
        'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
    ], (int)($sendRateState['http_status'] ?? 429));
}

$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'UFOTP' . strtoupper(bin2hex(random_bytes(6)));
$expiresAt = $now + 300;
$pricingCountry = auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));
$message = 'Z-Pay Swift password/PIN reset OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'pre_auth_token' => $preAuthToken,
    'forgot_token' => $preAuthToken,
    'uid' => $uid,
    'phone' => $phone,
    'country' => $phoneCountry,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
    'dial_code' => $phoneCountry === 'BD' ? '+880' : '+60',
    'phone_e164' => $phone,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'purpose' => $purpose,
    'reset_type' => 'PASSWORD_PIN',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => forgot_otp_mask_phone($phone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
] + auth_otp_reset_attempts_patch();

if (!fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow)) {
    api_response(false, 'SERVER_ERROR', 'Failed to prepare OTP.', [], 500);
}

auth_otp_record_send_rate($purpose, $phone, $sendRateState, $now);
$smsResult = auth_send_otp_sms_by_country($phoneCountry, $phone, $message, $otpRequestId, 'USER_RESET', $otpCode);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => forgot_otp_now(),
    ] + $smsPatch);
    api_response(false, 'SMS_FAILED', 'Failed to send OTP SMS.', [], 502);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => forgot_otp_now(),
] + $smsPatch);

@fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
    'biometric_verified' => true,
    'biometric_verified_at' => $now,
    'otp_request_id' => $otpRequestId,
    'status' => 'OTP_PENDING',
    'updated_at' => $now,
    'expires_at' => $now + 900,
]);

api_response(true, 'OTP_SENT', 'OTP sent successfully.', [
    'require_otp' => true,
    'forgot_token' => $preAuthToken,
    'reset_token' => $preAuthToken,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'request_id' => $otpRequestId,
    'masked_phone' => forgot_otp_mask_phone($phone),
    'expires_in_seconds' => 300,
    'reset_type' => 'PASSWORD_PIN',
    'phone_country' => $phoneCountry,
]);
