<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$preAuthRow = auth_app_get_valid_preauth($preAuthToken);

if (empty($preAuthRow['password_verified']) || empty($preAuthRow['pin_verified'])) {
    api_response(false, 'PIN_REQUIRED', 'Password and PIN verification required before OTP.', [], 400);
}

$account = auth_app_preauth_user($preAuthRow);
$uid = (string)$account['uid'];
$user = (array)$account['user'];
$phoneCountry = auth_phone_country_from_user($user);
$pricingCountry = auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: []));
$otpPhone = normalize_phone_by_country((string)($user['phone'] ?? $preAuthRow['phone'] ?? ''), $phoneCountry);

if ($otpPhone === '') {
    api_response(false, 'PHONE_INVALID', auth_phone_validation_message($phoneCountry), [], 422);
}

$now = now_ts();
$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'OTP' . strtoupper(bin2hex(random_bytes(6)));
$expiresAt = $now + 300;
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
    'uid' => $uid,
    'phone' => $otpPhone,
    'country' => $phoneCountry,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
    'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
    'phone_e164' => $otpPhone,
    'ip_country' => (string)($preAuthRow['ip_country'] ?? auth_request_ip_country($body)),
    'created_ip' => (string)($preAuthRow['created_ip'] ?? auth_request_ip($body)),
    'user_agent' => (string)($preAuthRow['user_agent'] ?? auth_request_user_agent($body)),
    'purpose' => 'USER_LOGIN',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => auth_app_mask_phone($otpPhone),
    'status' => 'SENT',
    'used' => false,
    'resend_count' => 0,
    'created_at' => $now,
    'expires_at' => $expiresAt,
    'updated_at' => $now,
] + auth_otp_reset_attempts_patch();

if (!fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow)) {
    api_response(false, 'SERVER_ERROR', 'Failed to create OTP request.', [], 500);
}

auth_otp_record_send_rate('USER_LOGIN', $otpPhone, $sendRateState, $now);
$message = 'Z-Pay Swift login OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
$smsResult = auth_send_otp_sms_by_country(
    $phoneCountry,
    $otpPhone,
    $message,
    $otpRequestId,
    'USER_LOGIN',
    $otpCode
);
$smsPatch = auth_sms_result_log_fields($smsResult);

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => now_ts(),
    ] + $smsPatch);

    api_response(false, 'SMS_FAILED', 'Failed to send OTP SMS.', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => now_ts(),
] + $smsPatch);

@fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
    'otp_request_id' => $otpRequestId,
    'status' => 'OTP_PENDING',
    'expires_at' => $expiresAt,
    'updated_at' => now_ts(),
]);

if (function_exists('system_log')) {
    system_log('USER_LOGIN_OTP_SENT_ANDROID', $otpRequestId, 'Android user login OTP sent', [
        'uid' => $uid,
        'phone' => $otpPhone,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'device_id' => (string)($preAuthRow['device_id'] ?? ''),
    ]);
}

api_response(true, 'OTP_SENT', 'OTP পাঠানো হয়েছে।', [
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => auth_app_mask_phone($otpPhone),
    'expires_in_seconds' => 300,
    'expires_at' => $expiresAt,
    'phone_country' => $phoneCountry,
]);
