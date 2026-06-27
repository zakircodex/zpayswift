<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';

api_require_method('POST');
api_require_app_key();

$body = reg_app_body();
$phoneCountry = reg_app_phone_country($body);
$phone = reg_app_phone($body, $phoneCountry);

if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
}

if (reg_app_phone_uid($phone, $phoneCountry) !== '') {
    api_response(false, 'PHONE_ALREADY_REGISTERED', 'This phone number is already registered. Please login.', [
        'exists' => true,
        'phone' => $phone,
        'masked_phone' => reg_app_mask_phone($phone),
    ], 409);
}

$marketDecision = reg_app_market_decision($body, $phoneCountry);
if (empty($marketDecision['ok'])) {
    api_response(
        false,
        (string)($marketDecision['code'] ?? 'LOCATION_REQUIRED'),
        (string)($marketDecision['message'] ?? 'Please allow location permission to continue.'),
        [],
        422
    );
}

$now = reg_app_now();
$otpCode = (string)random_int(100000, 999999);
$otpRequestId = reg_app_token('UROTP', 6);
$registerToken = reg_app_token('URPA', 16);
$expiresAt = $now + 300;
$preAuthExpiresAt = $now + 3600;
$marketFields = reg_app_common_market_fields($marketDecision);
$currency = (string)$marketFields['currency'];

$sendRateState = auth_otp_send_rate_state('USER_REGISTER', $phone, $now);
if (empty($sendRateState['ok'])) {
    api_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
        'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
        'send_count' => (int)($sendRateState['send_count'] ?? 0),
        'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
    ], (int)($sendRateState['http_status'] ?? 429));
}

$deviceId = reg_app_device_id($body);
$deviceName = reg_app_device_name($body);
$appVersion = trim((string)($body['app_version'] ?? ''));

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'register_token' => $registerToken,
    'pre_auth_token' => $registerToken,
    'uid' => '',
    'phone' => $phone,
    'phone_e164' => $phone,
    'country' => $phoneCountry,
    'phone_country' => $phoneCountry,
    'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
    'purpose' => 'USER_REGISTER',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => reg_app_mask_phone($phone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
] + $marketFields + auth_otp_reset_attempts_patch();

$preAuthRow = [
    'pre_auth_token' => $registerToken,
    'register_token' => $registerToken,
    'otp_request_id' => $otpRequestId,
    'uid' => '',
    'phone' => $phone,
    'phone_e164' => $phone,
    'phone_country' => $phoneCountry,
    'masked_phone' => reg_app_mask_phone($phone),
    'role' => 'USER',
    'status' => 'OTP_PENDING',
    'otp_verified' => false,
    'document_verified' => false,
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'app_version' => $appVersion,
    'user_agent' => auth_request_user_agent($body),
    'browser_timezone' => auth_request_browser_timezone($body),
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $preAuthExpiresAt,
] + $marketFields;

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
    @fb_delete('AUTH_USER_REGISTER_PREAUTH/' . $registerToken);
    api_response(false, 'SERVER_ERROR', 'Failed to prepare registration OTP.', [], 500);
}

auth_otp_record_send_rate('USER_REGISTER', $phone, $sendRateState, $now);
$smsResult = reg_app_send_register_sms($phoneCountry, $phone, $otpCode, $otpRequestId);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => reg_app_now(),
    ] + $smsPatch);
    @fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => reg_app_now(),
    ]);
    api_response(false, 'OTP_SEND_FAILED', 'Failed to send OTP SMS.', [], 502);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => reg_app_now(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('ANDROID_REGISTER_OTP_SENT', $otpRequestId, 'Android registration OTP sent', [
        'phone_country' => $phoneCountry,
        'pricing_country' => (string)$marketFields['pricing_country'],
        'gps_country' => (string)$marketFields['gps_country'],
        'ip_country' => (string)$marketFields['ip_country'],
        'ip_source' => (string)$marketFields['ip_source'],
        'account_status' => (string)$marketFields['account_status'],
        'device_id' => $deviceId,
    ]);
}

api_response(true, 'OTP_SENT', 'OTP sent successfully.', [
    'register_token' => $registerToken,
    'pre_auth_token' => $registerToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => reg_app_mask_phone($phone),
    'expires_in_seconds' => 300,
    'phone_country' => $phoneCountry,
    'pricing_country' => (string)$marketFields['pricing_country'],
    'market_country' => (string)$marketFields['market_country'],
    'currency' => $currency,
    'gps_country' => (string)$marketFields['gps_country'],
    'ip_country' => (string)$marketFields['ip_country'],
    'ip_source' => (string)$marketFields['ip_source'],
    'account_status' => (string)$marketFields['account_status'],
    'review_required' => (bool)$marketFields['review_required'],
]);
