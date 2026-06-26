<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

function user_reg_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    api_response($ok, $code, $message, $data, $httpStatus);
}

function user_reg_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function user_reg_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function user_reg_make_uid(): string
{
    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return 'U' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
}

function user_reg_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function user_reg_normalize_phone(string $phone, string $country = 'BD'): string
{
    if (function_exists('normalize_phone_by_country')) {
        return normalize_phone_by_country($phone, $country);
    }

    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function user_reg_mask_phone(string $phone): string
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

function user_reg_email_key(string $email): string
{
    return md5(strtolower(trim($email)));
}

function user_reg_find_uid_by_phone(string $phone, string $country): string
{
    if (function_exists('auth_find_uid_by_phone_country')) {
        return auth_find_uid_by_phone_country($phone, $country);
    }

    $phone = user_reg_normalize_phone($phone, $country);
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

function user_reg_find_uid_by_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    $row = fb_get('USER_INDEX/EMAIL/' . user_reg_email_key($email));

    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function user_reg_send_sms(
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

$name = trim((string)($body['name'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));

if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? ''));
}

if ($phoneCountry === '') {
    $phoneCountry = 'BD';
}

$marketDecision = market_registration_decision($body, $phoneCountry);

if (empty($marketDecision['ok'])) {
    user_reg_response(
        false,
        (string)($marketDecision['code'] ?? 'LOCATION_REQUIRED'),
        (string)($marketDecision['message'] ?? 'Location permission is required to create an account.'),
        [],
        422
    );
}

$pricingCountry = (string)$marketDecision['pricing_country'];
$ipCountry = (string)$marketDecision['ip_country'];
$ipSource = (string)($marketDecision['ip_source'] ?? '');

$phone = user_reg_normalize_phone((string)($body['phone'] ?? ''), $phoneCountry);
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');
$pin = trim((string)($body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));
$identityType = auth_app_identity_type($body);
$identityNumber = auth_app_identity_number($body);
$identityHash = auth_app_identity_hash($identityNumber);
$identityLast4 = auth_app_identity_last4($identityNumber);
$deviceId = trim((string)($body['device_id'] ?? 'USER_WEB'));
$deviceName = trim((string)($body['device_name'] ?? 'User Register'));
$userAgent = auth_request_user_agent($body);
$browserTimezone = auth_request_browser_timezone($body);
$currency = auth_country_currency($pricingCountry);
$createdIp = (string)$marketDecision['created_ip'];
$countryMismatch = (bool)$marketDecision['country_mismatch'];
$termsAccepted = user_reg_bool($body['terms_accepted'] ?? false);

if ($name === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '' || $pin === '' || $confirmPin === '') {
    $message = $phone === '' ? auth_phone_validation_message($phoneCountry) : 'All fields are required';
    user_reg_response(false, 'VALIDATION_ERROR', $message, [], 422);
}

if ($identityHash === '') {
    user_reg_response(false, 'IDENTITY_REQUIRED', 'NID or Passport number is required', [
        'field' => 'nid_or_passport_number',
    ], 422);
}

if (!$termsAccepted) {
    user_reg_response(false, 'TERMS_REQUIRED', 'You must accept the Terms & Conditions.', [
        'field' => 'terms_accepted',
    ], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Valid email is required', [], 422);
}

if (strlen($password) < 6) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters', [], 422);
}

if ($password !== $confirmPassword) {
    user_reg_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match', [], 422);
}

if (!preg_match('/^\d{4,8}$/', $pin)) {
    user_reg_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits', [], 422);
}

if ($pin !== $confirmPin) {
    user_reg_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match', [], 422);
}

if (user_reg_find_uid_by_phone($phone, $phoneCountry) !== '') {
    user_reg_response(false, 'DUPLICATE_PHONE', 'Phone number already registered', [], 409);
}

if (user_reg_find_uid_by_email($email) !== '') {
    user_reg_response(false, 'DUPLICATE_EMAIL', 'Email already registered', [], 409);
}

$now = user_reg_now();
$expiresAt = $now + 300;
$termsAcceptedAt = $now;

$uid = user_reg_make_uid();
$otpCode = (string)random_int(100000, 999999);
$otpRequestId = 'UROTP' . strtoupper(bin2hex(random_bytes(6)));
$preAuthToken = 'URPA' . user_reg_token(16);

$message = 'Z-Pay Swift register OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';
$sendRateState = auth_otp_send_rate_state('USER_REGISTER', $phone, $now);
if (empty($sendRateState['ok'])) {
    user_reg_response(false, (string)$sendRateState['code'], (string)$sendRateState['message'], [
        'retry_after_seconds' => (int)($sendRateState['retry_after_seconds'] ?? 0),
        'send_count' => (int)($sendRateState['send_count'] ?? 0),
        'send_limit' => (int)($sendRateState['send_limit'] ?? auth_otp_send_limit_per_hour()),
    ], (int)($sendRateState['http_status'] ?? 429));
}

$otpRow = [
    'otp_request_id' => $otpRequestId,
    'uid' => $uid,
    'phone' => $phone,
    'phone_e164' => $phone,
    'country' => $phoneCountry,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $currency,
    'dial_code' => $phoneCountry === 'MY' ? '+60' : '+880',
    'ip_country' => $ipCountry,
    'ip_source' => $ipSource,
    'country_mismatch' => $countryMismatch,
    'gps_lat' => (float)$marketDecision['gps_lat'],
    'gps_lng' => (float)$marketDecision['gps_lng'],
    'gps_accuracy' => (float)$marketDecision['gps_accuracy'],
    'gps_country' => (string)$marketDecision['gps_country'],
    'vpn_suspected' => (bool)$marketDecision['vpn_suspected'],
    'market_detection_source' => (string)$marketDecision['market_detection_source'],
    'account_review_reason' => (string)$marketDecision['account_review_reason'],
    'account_status' => (string)$marketDecision['account_status'],
    'review_required' => (bool)$marketDecision['review_required'],
    'requires_admin_review' => (bool)$marketDecision['requires_admin_review'],
    'ip_risk_type' => (string)$marketDecision['ip_risk_type'],
    'ip_risk_score' => (int)$marketDecision['ip_risk_score'],
    'created_ip' => $createdIp,
    'user_agent' => $userAgent,
    'purpose' => 'USER_REGISTER',
    'code_hash' => password_hash($otpCode, PASSWORD_DEFAULT),
    'masked_phone' => user_reg_mask_phone($phone),
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
    'name' => $name,
    'phone' => $phone,
    'phone_e164' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'currency' => $currency,
    'ip_country' => $ipCountry,
    'ip_source' => $ipSource,
    'country_mismatch' => $countryMismatch,
    'gps_lat' => (float)$marketDecision['gps_lat'],
    'gps_lng' => (float)$marketDecision['gps_lng'],
    'gps_accuracy' => (float)$marketDecision['gps_accuracy'],
    'gps_country' => (string)$marketDecision['gps_country'],
    'vpn_suspected' => (bool)$marketDecision['vpn_suspected'],
    'market_detection_source' => (string)$marketDecision['market_detection_source'],
    'account_review_reason' => (string)$marketDecision['account_review_reason'],
    'account_status' => (string)$marketDecision['account_status'],
    'review_required' => (bool)$marketDecision['review_required'],
    'requires_admin_review' => (bool)$marketDecision['requires_admin_review'],
    'ip_risk_type' => (string)$marketDecision['ip_risk_type'],
    'ip_risk_score' => (int)$marketDecision['ip_risk_score'],
    'ip_risk_source' => (string)$marketDecision['ip_risk_source'],
    'created_ip' => $createdIp,
    'registration_ip' => $createdIp,
    'user_agent' => $userAgent,
    'browser_timezone' => $browserTimezone,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
    'identity_type' => $identityType,
    'identity_number_hash' => $identityHash,
    'identity_number_last4' => $identityLast4,
    'kyc_status' => 'PENDING_REVIEW',
    'KYC' => [
        'type' => $identityType,
        'identity_number_hash' => $identityHash,
        'identity_number_last4' => $identityLast4,
        'status' => 'PENDING_REVIEW',
        'created_at' => $now,
        'updated_at' => $now,
    ],
    'role' => 'USER',
    'status' => 'OTP_PENDING',
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'masked_phone' => user_reg_mask_phone($phone),
    'terms_accepted_at' => $termsAcceptedAt,
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $expiresAt,
];

$okOtp = fb_put('AUTH_OTP_REQUESTS/' . $otpRequestId, $otpRow);
$okPre = $okOtp ? fb_put('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, $preAuthRow) : false;

if (!($okOtp && $okPre)) {
    if (function_exists('fb_delete')) {
        @fb_delete('AUTH_OTP_REQUESTS/' . $otpRequestId);
        @fb_delete('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken);
    }

    user_reg_response(false, 'SERVER_ERROR', 'Failed to prepare register OTP', [], 500);
}

auth_otp_record_send_rate('USER_REGISTER', $phone, $sendRateState, $now);
$smsResult = user_reg_send_sms($phoneCountry, $phone, $message, $otpRequestId, $otpCode);
$smsPatch = function_exists('auth_sms_result_log_fields')
    ? auth_sms_result_log_fields($smsResult)
    : [];

if (empty($smsResult['ok'])) {
    @fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_reg_now(),
    ] + $smsPatch);

    @fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, [
        'status' => 'SMS_FAILED',
        'updated_at' => user_reg_now(),
    ]);

    user_reg_response(false, 'SMS_FAILED', 'Failed to send OTP SMS', [], 500);
}

@fb_patch('AUTH_OTP_REQUESTS/' . $otpRequestId, [
    'updated_at' => user_reg_now(),
] + $smsPatch);

if (function_exists('system_log')) {
    system_log('USER_REGISTER_OTP_SENT', $otpRequestId, 'User register OTP sent', [
        'uid' => $uid,
        'phone' => $phone,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'ip_country' => $ipCountry,
        'ip_source' => $ipSource,
        'gps_country' => (string)$marketDecision['gps_country'],
        'account_status' => (string)$marketDecision['account_status'],
        'vpn_suspected' => (bool)$marketDecision['vpn_suspected'],
        'email' => $email,
        'device_id' => $deviceId,
        'device_name' => $deviceName,
    ]);
}

user_reg_response(true, 'OTP_REQUIRED', 'OTP verification required', [
    'require_otp' => true,
    'pre_auth_token' => $preAuthToken,
    'otp_request_id' => $otpRequestId,
    'masked_phone' => user_reg_mask_phone($phone),
    'expires_in_seconds' => 300,
    'expires_at' => $expiresAt,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'currency' => $currency,
    'gps_country' => (string)$marketDecision['gps_country'],
    'ip_country' => $ipCountry,
    'ip_source' => $ipSource,
    'account_status' => (string)$marketDecision['account_status'],
    'review_required' => (bool)$marketDecision['review_required'],
    'requires_admin_review' => (bool)$marketDecision['requires_admin_review'],
]);
