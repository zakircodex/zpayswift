<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/../lib/user_forgot_recovery.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_forgot_send_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_forgot_send_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_forgot_send_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function user_forgot_send_normalize_phone(string $phone, string $country = 'BD'): string
{
    if (function_exists('normalize_phone_by_country')) {
        return normalize_phone_by_country($phone, $country);
    }

    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function user_forgot_send_mask_phone(string $phone): string
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

function user_forgot_send_find_uid_by_phone(string $phone, string $country): string
{
    if (function_exists('auth_find_uid_by_phone_country')) {
        return auth_find_uid_by_phone_country($phone, $country);
    }

    $phone = user_forgot_send_normalize_phone($phone, $country);
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

function user_forgot_send_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['USER', 'RETAILER'], true);
}

function user_forgot_send_sms(
    string $country,
    string $phone,
    string $message,
    string $referenceId,
    string $otpCode
): array
{
    if (function_exists('auth_send_otp_sms_by_country')) {
        return auth_send_otp_sms_by_country(
            $country,
            $phone,
            $message,
            $referenceId,
            'USER_RESET',
            $otpCode
        );
    }

    return ['ok' => false, 'gateway' => '', 'code' => 'SMS_HELPER_MISSING', 'message' => 'SMS helper missing'];
}

function user_forgot_send_combined_from_identity(array $body): void
{
    $preAuthToken = trim((string)($body['pre_auth_token'] ?? $body['reset_token'] ?? $body['forgot_token'] ?? ''));
    if ($preAuthToken === '') {
        user_forgot_send_response(false, 'IDENTITY_REQUIRED', 'Identity verification is required before OTP.', [], 409);
    }

    $now = user_forgot_send_now();
    $path = 'AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken;
    $snapshot = fb_get_with_etag($path);
    $preAuthRow = is_array($snapshot['value'] ?? null) ? $snapshot['value'] : null;
    $etag = trim((string)($snapshot['etag'] ?? ''));
    if (!is_array($preAuthRow) || $etag === '' || (int)($preAuthRow['expires_at'] ?? 0) <= $now) {
        user_forgot_send_response(false, 'FORGOT_SESSION_EXPIRED', 'Recovery session expired. Please start again.', [], 410);
    }

    $resetType = strtoupper(trim((string)($preAuthRow['reset_type'] ?? '')));
    $status = strtoupper(trim((string)($preAuthRow['status'] ?? '')));
    if ($resetType !== 'PASSWORD_PIN' || empty($preAuthRow['identity_verified'])) {
        user_forgot_send_response(false, 'IDENTITY_REQUIRED', 'Identity verification is required before OTP.', [], 409);
    }

    $storedDeviceId = trim((string)($preAuthRow['device_id'] ?? ''));
    $requestDeviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
    if ($storedDeviceId !== '' && $requestDeviceId !== '' && !hash_equals($storedDeviceId, $requestDeviceId)) {
        user_forgot_send_response(false, 'DEVICE_MISMATCH', 'Recovery session does not match this device.', [], 400);
    }

    $existingOtpId = trim((string)($preAuthRow['otp_request_id'] ?? ''));
    $existingOtp = $existingOtpId !== '' ? fb_get('AUTH_OTP_REQUESTS/' . $existingOtpId) : null;
    if (in_array($status, ['OTP_PENDING', 'OTP_SENDING'], true) && is_array($existingOtp)) {
        $existingStatus = strtoupper(trim((string)($existingOtp['status'] ?? '')));
        $existingActive = empty($existingOtp['used'])
            && (int)($existingOtp['expires_at'] ?? 0) > $now
            && in_array($existingStatus, ['SENT', 'RESENT'], true);
        if ($existingActive) {
            if ($status === 'OTP_SENDING') {
                @fb_patch($path, ['status' => 'OTP_PENDING', 'updated_at' => $now]);
            }
            user_forgot_send_response(true, 'OTP_REQUIRED', 'OTP verification required', [
                'require_otp' => true,
                'pre_auth_token' => $preAuthToken,
                'otp_request_id' => $existingOtpId,
                'masked_phone' => (string)($existingOtp['masked_phone'] ?? $preAuthRow['masked_phone'] ?? ''),
                'expires_at' => (int)($existingOtp['expires_at'] ?? 0),
                'expires_in_seconds' => max(0, (int)($existingOtp['expires_at'] ?? 0) - $now),
                'reset_type' => 'PASSWORD_PIN',
                'phone_country' => (string)($preAuthRow['phone_country'] ?? ''),
                'replayed' => true,
            ]);
        }
        if ($status === 'OTP_SENDING') {
            user_forgot_send_response(false, 'OTP_SEND_IN_PROGRESS', 'OTP is being prepared. Please wait briefly.', [], 409);
        }
        user_forgot_send_response(false, 'OTP_EXPIRED', 'OTP expired. Please resend OTP to continue.', [], 410);
    }

    if (!in_array($status, ['IDENTITY_VERIFIED', 'SMS_FAILED'], true)) {
        user_forgot_send_response(false, 'FORGOT_SESSION_INVALID', 'Recovery verification is incomplete. Please start again.', [], 409);
    }

    $uid = trim((string)($preAuthRow['uid'] ?? ''));
    $userRow = $uid !== '' ? fb_get('USERS/' . $uid) : null;
    if (!is_array($userRow)) {
        user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account recovery is unavailable.', [], 404);
    }

    $accountStatus = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));
    $role = strtoupper(trim((string)($userRow['role'] ?? '')));
    if ($accountStatus !== 'ACTIVE' || !user_forgot_send_allowed_role($role)) {
        user_forgot_send_response(false, 'FORBIDDEN', 'This account is not eligible for recovery.', [], 403);
    }

    $phoneCountry = auth_phone_country_from_user($userRow);
    $phone = normalize_phone_by_country((string)($userRow['phone'] ?? $preAuthRow['phone'] ?? ''), $phoneCountry);
    if ($phone === '' || $phone !== normalize_phone_by_country((string)($preAuthRow['phone'] ?? ''), $phoneCountry)) {
        user_forgot_send_response(false, 'FORGOT_SESSION_INVALID', 'Recovery session is invalid. Please start again.', [], 409);
    }

    $pricingCountry = auth_pricing_country_from_user($userRow, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));
    $purpose = 'USER_FORGOT_PASSWORD_PIN';
    $sendRateState = auth_otp_send_rate_state($purpose, $phone, $now);
    if (empty($sendRateState['ok'])) {
        user_forgot_send_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
            'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
            'send_count' => (int)($sendRateState['send_count'] ?? 0),
            'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
        ], (int)($sendRateState['http_status'] ?? 429));
    }

    $otpCode = (string)random_int(100000, 999999);
    $otpRequestId = 'UFOTP' . strtoupper(bin2hex(random_bytes(6)));
    $expiresAt = $now + 300;
    $claim = $preAuthRow;
    $claim['otp_request_id'] = $otpRequestId;
    $claim['status'] = 'OTP_SENDING';
    $claim['updated_at'] = $now;
    $claim['expires_at'] = $expiresAt;
    $claimWrite = fb_put_if_match($path, $claim, $etag);
    if (empty($claimWrite['ok'])) {
        user_forgot_send_response(false, 'OTP_SEND_IN_PROGRESS', 'OTP is already being prepared.', [], 409);
    }

    $otpRow = [
        'otp_request_id' => $otpRequestId,
        'uid' => $uid,
        'phone' => $phone,
        'country' => $phoneCountry,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'service_country' => $pricingCountry,
        'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
        'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
        'phone_e164' => $phone,
        'ip_country' => (string)($preAuthRow['ip_country'] ?? ''),
        'created_ip' => (string)($preAuthRow['created_ip'] ?? ''),
        'user_agent' => (string)($preAuthRow['user_agent'] ?? ''),
        'purpose' => $purpose,
        'reset_type' => 'PASSWORD_PIN',
        'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
        'masked_phone' => user_forgot_send_mask_phone($phone),
        'status' => 'SENDING',
        'used' => false,
        'resend_count' => 0,
        'identity_verified' => true,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expiresAt,
    ] + auth_otp_reset_attempts_patch();

    if (!fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow)) {
        @fb_patch($path, ['status' => 'IDENTITY_VERIFIED', 'otp_request_id' => null, 'updated_at' => $now, 'expires_at' => $now + 900]);
        user_forgot_send_response(false, 'SERVER_ERROR', 'OTP could not be prepared. Please try again.', [], 500);
    }

    auth_otp_record_send_rate($purpose, $phone, $sendRateState, $now);
    $message = 'Z-Pay Swift password/PIN reset OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
    $smsResult = user_forgot_send_sms($phoneCountry, $phone, $message, $otpRequestId, $otpCode);
    $smsPatch = auth_sms_result_log_fields($smsResult);
    if (empty($smsResult['ok'])) {
        @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, ['status' => 'SMS_FAILED', 'updated_at' => user_forgot_send_now()] + $smsPatch);
        @fb_patch($path, ['status' => 'SMS_FAILED', 'updated_at' => user_forgot_send_now(), 'expires_at' => $now + 900]);
        user_forgot_send_response(false, 'SMS_FAILED', 'OTP could not be sent. Please try again.', [], 500);
    }

    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, ['status' => 'SENT', 'updated_at' => user_forgot_send_now()] + $smsPatch);
    @fb_patch($path, ['status' => 'OTP_PENDING', 'updated_at' => user_forgot_send_now(), 'expires_at' => $expiresAt]);

    if (function_exists('system_log')) {
        system_log('USER_WEB_FORGOT_OTP_SENT', $otpRequestId, 'Web forgot OTP sent after identity verification', [
            'uid' => $uid,
            'reset_type' => 'PASSWORD_PIN',
        ]);
    }

    user_forgot_send_response(true, 'OTP_REQUIRED', 'OTP verification required', [
        'require_otp' => true,
        'pre_auth_token' => $preAuthToken,
        'otp_request_id' => $otpRequestId,
        'masked_phone' => user_forgot_send_mask_phone($phone),
        'expires_in_seconds' => 300,
        'reset_type' => 'PASSWORD_PIN',
        'phone_country' => $phoneCountry,
    ]);
}

$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? ''));
}
if ($phoneCountry === '') {
    $phoneCountry = 'BD';
}

$phone = user_forgot_send_normalize_phone((string)($body['phone'] ?? ''), $phoneCountry);
$resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Forgot'));

if ($resetType === 'PASSWORD_PIN') {
    user_forgot_send_combined_from_identity($body);
}

if ($phone === '') {
    user_forgot_send_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

if (!in_array($resetType, ['PASSWORD', 'PIN', 'PASSWORD_PIN'], true)) {
    user_forgot_send_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
}

$uid = user_forgot_send_find_uid_by_phone($phone, $phoneCountry);

if ($uid === '') {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$userRow = fb_get('USERS/' . $uid);

if (!is_array($userRow)) {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found', [], 404);
}

$status = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));
$role = strtoupper(trim((string)($userRow['role'] ?? '')));
$storedPhoneCountry = auth_phone_country_from_user($userRow);
$pricingCountry = auth_pricing_country_from_user($userRow, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));

if ($storedPhoneCountry !== $phoneCountry) {
    user_forgot_send_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found for selected country/number', [], 404);
}

$otpPhone = normalize_phone_by_country((string)($userRow['phone'] ?? $phone), $storedPhoneCountry);
if ($otpPhone === '') {
    $otpPhone = $phone;
}

if ($status !== 'ACTIVE') {
    user_forgot_send_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
}

if (!user_forgot_send_allowed_role($role)) {
    user_forgot_send_response(false, 'FORBIDDEN', 'Only USER or RETAILER account can use this recovery', [], 403);
}

$now = user_forgot_send_now();
$expiresAt = $now + 300;

$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'UFOTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = 'UFPA' . user_forgot_send_token(16);

$label = $resetType === 'PIN'
    ? 'PIN reset'
    : ($resetType === 'PASSWORD_PIN' ? 'password/PIN reset' : 'password reset');
$purpose = $resetType === 'PIN'
    ? 'USER_FORGOT_PIN'
    : ($resetType === 'PASSWORD_PIN' ? 'USER_FORGOT_PASSWORD_PIN' : 'USER_FORGOT_PASSWORD');

$message = 'Z-Pay Swift ' . $label . ' OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
$sendRateState = auth_otp_send_rate_state($purpose, $otpPhone, $now);
if (empty($sendRateState['ok'])) {
    user_forgot_send_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
        'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
        'send_count' => (int)($sendRateState['send_count'] ?? 0),
        'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
    ], (int)($sendRateState['http_status'] ?? 429));
}

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $phone,
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
    'reset_type' => $resetType,
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_forgot_send_mask_phone($otpPhone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
] + auth_otp_reset_attempts_patch();

$preAuthRow = [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $otpPhone,
    'phone_country' => $storedPhoneCountry,
    'pricing_country' => $pricingCountry,
    'ip_country' => auth_request_ip_country($body),
    'created_ip' => auth_request_ip($body),
    'user_agent' => auth_request_user_agent($body),
    'browser_timezone' => auth_request_browser_timezone($body),
    'masked_phone' => user_forgot_send_mask_phone($otpPhone),
    'reset_type' => $resetType,
    'purpose' => $purpose,
    'status' => 'OTP_PENDING',
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    if (function_exists('fb_delete')) {
        @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
        @fb_delete('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken);
    }

    user_forgot_send_response(false, 'SERVER_ERROR', 'Failed to prepare OTP verification', [], 500);
}

auth_otp_record_send_rate($purpose, $otpPhone, $sendRateState, $now);
$smsResult = user_forgot_send_sms(
    $storedPhoneCountry,
    $otpPhone,
    $message,
    $otpRequestId,
    $otpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_send_now(),
    ] + $smsPatch);

    @fb_patch('AUTH_USER_FORGOT_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_forgot_send_now(),
    ]);

    user_forgot_send_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => user_forgot_send_now(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('USER_FORGOT_OTP_SENT', $otpRequestId, 'User forgot OTP sent', [
        'uid' => $uid,
        'phone' => $otpPhone,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => $pricingCountry,
        'reset_type' => $resetType,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
    ]);
}

user_forgot_send_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => user_forgot_send_mask_phone($otpPhone),
    'expires_in_seconds' => 300,
    'reset_type' => $resetType,
    'phone_country' => $storedPhoneCountry,
]);
