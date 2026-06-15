<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/roles.php';
require_once __DIR__ . '/../lib/account_review.php';

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

function user_reg_confirm_find_uid_by_phone(string $phone, string $country): string
{
    if (function_exists('auth_find_uid_by_phone_country')) {
        return auth_find_uid_by_phone_country($phone, $country);
    }

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
$phoneCountry = auth_normalize_country_code((string)($preAuthRow['phone_country'] ?? ''));
$pricingCountry = auth_normalize_country_code((string)(
    $preAuthRow['pricing_country']
    ?? $preAuthRow['market_country']
    ?? $preAuthRow['service_country']
    ?? ''
));
$ipCountry = function_exists('market_iso_country_code')
    ? market_iso_country_code($preAuthRow['ip_country'] ?? '')
    : strtoupper(trim((string)($preAuthRow['ip_country'] ?? '')));
$currency = auth_country_currency($pricingCountry !== '' ? $pricingCountry : 'BD');
$accountStatus = strtoupper(trim((string)($preAuthRow['account_status'] ?? 'ACTIVE')));
$termsAcceptedAt = (int)($preAuthRow['terms_accepted_at'] ?? 0);

if (!in_array($accountStatus, ['ACTIVE', 'REVIEW', 'BLOCKED'], true)) {
    $accountStatus = 'REVIEW';
}

if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country($phone) ?: 'BD';
}

if ($pricingCountry === '') {
    $pricingCountry = auth_normalize_country_code((string)($preAuthRow['country'] ?? '')) ?: 'BD';
    $currency = auth_country_currency($pricingCountry);
}

if ($uid === '' || $name === '' || $phone === '' || $email === '' || $passwordHash === '' || $pinHash === '') {
    user_reg_confirm_response(false, 'REGISTER_SESSION_INVALID', 'Register session is invalid. Please start again.', [], 400);
}

if ($termsAcceptedAt <= 0) {
    user_reg_confirm_response(false, 'TERMS_REQUIRED', 'Terms & Conditions acceptance is required. Please start again.', [], 422);
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

if (user_reg_confirm_find_uid_by_phone($phone, $phoneCountry) !== '') {
    user_reg_confirm_response(false, 'DUPLICATE_PHONE', 'Phone number already registered', [], 409);
}

if (user_reg_confirm_find_uid_by_email($email) !== '') {
    user_reg_confirm_response(false, 'DUPLICATE_EMAIL', 'Email already registered', [], 409);
}

$userRow = [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'phone_e164' => (string)($preAuthRow['phone_e164'] ?? $phone),
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'country_code' => $pricingCountry,
    'country' => $pricingCountry,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'ip_country' => $ipCountry,
    'country_mismatch' => (bool)($preAuthRow['country_mismatch'] ?? ($pricingCountry !== $phoneCountry)),
    'gps_lat' => (float)($preAuthRow['gps_lat'] ?? 0),
    'gps_lng' => (float)($preAuthRow['gps_lng'] ?? 0),
    'gps_accuracy' => (float)($preAuthRow['gps_accuracy'] ?? 0),
    'gps_country' => (string)($preAuthRow['gps_country'] ?? ''),
    'vpn_suspected' => (bool)($preAuthRow['vpn_suspected'] ?? false),
    'market_detection_source' => (string)($preAuthRow['market_detection_source'] ?? 'BROWSER_GPS'),
    'account_review_reason' => (string)($preAuthRow['account_review_reason'] ?? ''),
    'account_status' => $accountStatus,
    'review_required' => $accountStatus !== 'ACTIVE',
    'requires_admin_review' => $accountStatus !== 'ACTIVE',
    'ip_risk_type' => (string)($preAuthRow['ip_risk_type'] ?? ''),
    'ip_risk_score' => (int)($preAuthRow['ip_risk_score'] ?? 0),
    'ip_risk_source' => (string)($preAuthRow['ip_risk_source'] ?? ''),
    'registration_ip' => (string)($preAuthRow['registration_ip'] ?? $preAuthRow['created_ip'] ?? ''),
    'created_ip' => (string)($preAuthRow['created_ip'] ?? $preAuthRow['registration_ip'] ?? ''),
    'last_login_ip' => '',
    'browser_timezone' => (string)($preAuthRow['browser_timezone'] ?? ''),
    'user_agent' => (string)($preAuthRow['user_agent'] ?? ''),
    'email' => $email,
    'role' => 'USER',
    'status' => $accountStatus,
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
    'terms_accepted_at' => $termsAcceptedAt,
];

$walletRow = [
    'available_balance' => 0,
    'hold_balance' => 0,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
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
$phoneIndexes = auth_phone_index_candidates($phone, $phoneCountry);
$okPhone = $okRole;

foreach ($phoneIndexes as $phoneIndex) {
    if (!$okPhone || !fb_put('USER_INDEX/PHONE/' . $phoneIndex, $uid)) {
        $okPhone = false;
        break;
    }
}
$okEmail = $okPhone ? fb_put('USER_INDEX/EMAIL/' . $emailIndexKey, $uid) : false;

if (!($okUser && $okWallet && $okRole && $okPhone && $okEmail)) {
    user_reg_confirm_delete_if_exists('USERS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_WALLETS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_ROLE_SETTINGS/' . $uid);
    foreach ($phoneIndexes as $phoneIndex) {
        user_reg_confirm_delete_if_exists('USER_INDEX/PHONE/' . $phoneIndex);
    }
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
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'gps_country' => (string)($preAuthRow['gps_country'] ?? ''),
        'ip_country' => $ipCountry,
        'account_status' => $accountStatus,
        'email' => $email,
    ]);
}

$requiresReview = $accountStatus !== 'ACTIVE';
$telegramReviewSent = false;

if ($requiresReview) {
    $telegramResult = account_review_send_telegram($uid, $userRow);
    $telegramReviewSent = !empty($telegramResult['ok']);
    $telegramPatch = [
        'telegram_review_sent' => $telegramReviewSent,
        'telegram_review_updated_at' => $now,
        'updated_at' => $now,
    ];

    if ($telegramReviewSent) {
        $telegramPatch['telegram_review_message_id'] = (int)($telegramResult['data']['message_id'] ?? 0);
        $telegramPatch['telegram_review_chat_id'] = (string)($telegramResult['data']['chat_id'] ?? '');
        $telegramPatch['telegram_review_error'] = '';
        $telegramPatch['telegram_review_sent_at'] = $now;
    } else {
        $telegramPatch['telegram_review_error'] = substr(
            (string)($telegramResult['message'] ?? 'Telegram review notification failed'),
            0,
            300
        );
    }

    @fb_patch('USERS/' . $uid, $telegramPatch);

    if (function_exists('system_log')) {
        system_log(
            $telegramReviewSent ? 'ACCOUNT_REVIEW_TELEGRAM_SENT' : 'ACCOUNT_REVIEW_TELEGRAM_FAILED',
            $uid,
            $telegramReviewSent
                ? 'Account review Telegram alert sent'
                : 'Account review Telegram alert failed',
            [
                'uid' => $uid,
                'account_status' => $accountStatus,
                'error' => $telegramReviewSent ? '' : (string)($telegramResult['code'] ?? 'TELEGRAM_ERROR'),
            ]
        );
    }
}

user_reg_confirm_response(
    true,
    'SUCCESS',
    $requiresReview
        ? 'Account created and pending admin review'
        : 'Account created successfully',
    [
        'uid' => $uid,
        'role' => 'USER',
        'phone' => $phone,
        'email' => $email,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'currency' => $currency,
        'gps_country' => (string)($preAuthRow['gps_country'] ?? ''),
        'ip_country' => $ipCountry,
        'account_status' => $accountStatus,
        'review_required' => $requiresReview,
        'requires_admin_review' => $requiresReview,
    ]);
