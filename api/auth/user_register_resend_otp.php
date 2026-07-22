<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_reg_res_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_reg_res_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_reg_res_mask_phone(string $phone): string
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

function user_reg_res_send_sms(
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
            'USER_REGISTER',
            $otpCode
        );
    }

    return ['ok' => false, 'gateway' => '', 'code' => 'SMS_HELPER_MISSING', 'message' => 'SMS helper missing'];
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$oldOtpRequestId = trim((string)($body['otp_request_id'] ?? ''));

if ($preAuthToken === '' || $oldOtpRequestId === '') {
    user_reg_res_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
}

$preAuthRow = fb_get('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken);

if (!is_array($preAuthRow)) {
    user_reg_res_response(false, 'REGISTER_SESSION_EXPIRED', 'Register session expired. Please start again.', [], 410);
}

$storedOtpRequestId = trim((string)($preAuthRow['otp_request_id'] ?? ''));

if ($storedOtpRequestId === '' || $storedOtpRequestId !== $oldOtpRequestId) {
    user_reg_res_response(false, 'OTP_MISMATCH', 'OTP request mismatch', [], 400);
}

$preAuthStatus = strtoupper(trim((string)($preAuthRow['status'] ?? '')));

if (!in_array($preAuthStatus, ['OTP_PENDING', 'SENT', 'RESENT'], true)) {
    user_reg_res_response(false, 'REGISTER_SESSION_EXPIRED', 'Register session expired. Please start again.', [], 410);
}

$phone = trim((string)($preAuthRow['phone'] ?? ''));
$uid = trim((string)($preAuthRow['uid'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($preAuthRow['phone_country'] ?? ''));
$pricingCountry = auth_normalize_country_code((string)(
    $preAuthRow['pricing_country']
    ?? $preAuthRow['market_country']
    ?? $preAuthRow['service_country']
    ?? ''
));

if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country($phone) ?: 'BD';
}

if ($pricingCountry === '') {
    $pricingCountry = 'BD';
}

if ($phone === '') {
    user_reg_res_response(false, 'REGISTER_SESSION_INVALID', 'Register session is invalid. Please start again.', [], 400);
}

$now = user_reg_res_now();
$expiresAt = $now + 300;

$newOtpCode = (string)random_int(100000, 999999);
$newOtpRequestId = 'UROTP' . strtoupper(bin2hex(random_bytes(6)));

$oldOtpRow = fb_get('AUTH_OTP_REQUESTS/' . $oldOtpRequestId);
if (!is_array($oldOtpRow)) {
    user_reg_res_response(false, 'OTP_NOT_FOUND', 'Previous OTP request not found', [], 404);
}

if (
    strtoupper(trim((string)($preAuthRow['purpose'] ?? 'USER_REGISTER'))) !== 'USER_REGISTER'
    || strtoupper(trim((string)($oldOtpRow['purpose'] ?? ''))) !== 'USER_REGISTER'
) {
    user_reg_res_response(false, 'OTP_PURPOSE_MISMATCH', 'OTP purpose mismatch', [], 400);
}

$resendState = auth_otp_resend_state($oldOtpRow, $now);
if (empty($resendState['ok'])) {
    user_reg_res_response(false, (string)$resendState['code'], (string)$resendState['message'], [
        'retry_after_seconds' => (int)($resendState['retry_after_seconds'] ?? 0),
        'resend_count' => (int)($resendState['resend_count'] ?? 0),
        'resend_limit' => (int)($resendState['resend_limit'] ?? auth_otp_resend_limit()),
    ], (int)($resendState['http_status'] ?? 429));
}

$otpRow = [
    'otp_request_id' => $newOtpRequestId,
    'uid' => $uid,
    'phone' => $phone,
    'phone_e164' => (string)($preAuthRow['phone_e164'] ?? $phone),
    'country' => $phoneCountry,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $pricingCountry === 'MY' ? 'MYR' : 'BDT',
    'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
    'ip_country' => (string)($preAuthRow['ip_country'] ?? ''),
    'ip_source' => (string)($preAuthRow['ip_source'] ?? ''),
    'country_mismatch' => (bool)($preAuthRow['country_mismatch'] ?? ($pricingCountry !== $phoneCountry)),
    'gps_lat' => (float)($preAuthRow['gps_lat'] ?? 0),
    'gps_lng' => (float)($preAuthRow['gps_lng'] ?? 0),
    'gps_accuracy' => (float)($preAuthRow['gps_accuracy'] ?? 0),
    'gps_country' => (string)($preAuthRow['gps_country'] ?? ''),
    'vpn_suspected' => (bool)($preAuthRow['vpn_suspected'] ?? false),
    'market_detection_source' => (string)($preAuthRow['market_detection_source'] ?? 'BROWSER_GPS'),
    'account_review_reason' => (string)($preAuthRow['account_review_reason'] ?? ''),
    'account_status' => (string)($preAuthRow['account_status'] ?? 'ACTIVE'),
    'review_required' => (bool)($preAuthRow['review_required'] ?? $preAuthRow['requires_admin_review'] ?? false),
    'requires_admin_review' => (bool)($preAuthRow['requires_admin_review'] ?? $preAuthRow['review_required'] ?? false),
    'ip_risk_type' => (string)($preAuthRow['ip_risk_type'] ?? ''),
    'ip_risk_score' => (int)($preAuthRow['ip_risk_score'] ?? 0),
    'created_ip' => (string)($preAuthRow['created_ip'] ?? $preAuthRow['registration_ip'] ?? ''),
    'user_agent' => (string)($preAuthRow['user_agent'] ?? ''),
    'purpose' => 'USER_REGISTER',
    'code_hash' => password_hash($newOtpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_reg_res_mask_phone($phone),
    'status' => 'RESENT',
    'used' => false,
    'resend_count' => (int)($resendState['resend_count'] ?? 0) + 1,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
] + auth_otp_reset_attempts_patch();

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $newOtpRequestId, $otpRow);

if (!$okOtp) {
    user_reg_res_response(false, 'SERVER_ERROR', 'Failed to prepare new OTP', [], 500);
}

$message = 'Z-Pay Swift register OTP is ' . $newOtpCode . '. Valid for 5 minutes. Do not share this code.';
$smsResult = user_reg_res_send_sms(
    $phoneCountry,
    $phone,
    $message,
    $newOtpRequestId,
    $newOtpCode
);
$smsPatch = function_exists('auth_sms_result_log_fields')
    ? auth_sms_result_log_fields($smsResult)
    : [];

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $newOtpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_reg_res_now(),
    ] + $smsPatch);

    user_reg_res_response(false, 'SMS_FAILED', 'Failed to send OTP SMS. Your previous OTP remains valid.', [], 500);
}

$okPre = fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, [
    'otp_request_id' => $newOtpRequestId,
    'status' => 'OTP_PENDING',
    'updated_at' => $now,
    'expires_at' => $expiresAt,
]);

if (!$okPre) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $newOtpRequestId, [
        'status' => 'CANCELLED',
        'updated_at' => user_reg_res_now(),
    ]);

    user_reg_res_response(false, 'SERVER_ERROR', 'Failed to update register OTP session', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $oldOtpRequestId, [
    'status' => 'CANCELLED',
    'updated_at' => user_reg_res_now(),
]);

@fb_patch('AUTH_OTP_REQUESTS/' . $newOtpRequestId, [
    'updated_at' => user_reg_res_now(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('USER_REGISTER_OTP_RESENT', $newOtpRequestId, 'User register OTP resent', [
        'uid' => $uid,
        'phone' => $phone,
    ]);
}

user_reg_res_response(true, 'SUCCESS', 'OTP resent successfully', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $newOtpRequestId,
    'masked_phone' => user_reg_res_mask_phone($phone),
    'expires_in_seconds' => 300,
    'expires_at' => $expiresAt,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'gps_country' => (string)($preAuthRow['gps_country'] ?? ''),
    'ip_country' => (string)($preAuthRow['ip_country'] ?? ''),
    'ip_source' => (string)($preAuthRow['ip_source'] ?? ''),
    'account_status' => (string)($preAuthRow['account_status'] ?? 'ACTIVE'),
    'review_required' => (bool)($preAuthRow['review_required'] ?? $preAuthRow['requires_admin_review'] ?? false),
    'requires_admin_review' => strtoupper((string)($preAuthRow['account_status'] ?? 'ACTIVE')) !== 'ACTIVE',
]);
