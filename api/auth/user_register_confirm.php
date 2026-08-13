<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/roles.php';
require_once __DIR__ . '/../lib/account_review.php';
require_once __DIR__ . '/../lib/register_android.php';
require_once __DIR__ . '/../lib/user_registration_identity.php';
require_once __DIR__ . '/../lib/user_registration_kyc.php';

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

    if (function_exists('auth_find_uid_by_email')) {
        return auth_find_uid_by_email($email);
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
$identityType = strtoupper(trim((string)($preAuthRow['identity_type'] ?? $preAuthRow['KYC']['type'] ?? '')));
$identityHash = trim((string)($preAuthRow['identity_number_hash'] ?? $preAuthRow['KYC']['identity_number_hash'] ?? ''));
$identityHashes = is_array($preAuthRow['identity_hash_variants'] ?? null)
    ? $preAuthRow['identity_hash_variants']
    : [];
$identityLast4 = trim((string)($preAuthRow['identity_number_last4'] ?? $preAuthRow['KYC']['identity_number_last4'] ?? ''));
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
if ($ipCountry === '' && strtoupper(trim((string)($preAuthRow['ip_country'] ?? ''))) === 'UNKNOWN') {
    $ipCountry = 'UNKNOWN';
}
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

if ($identityHash === '') {
    user_reg_confirm_response(false, 'IDENTITY_REQUIRED', 'NID or Passport number is required. Please start again.', [], 422);
}

if (!in_array($identityType, ['NID', 'PASSPORT'], true)) {
    user_reg_confirm_response(false, 'REGISTER_SESSION_INVALID', 'Register session is invalid. Please start again.', [], 400);
}

$validatedIdentityHashes = [];
foreach (array_merge([$identityHash], $identityHashes) as $hash) {
    $hash = strtolower(trim((string)$hash));
    if (preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
        $validatedIdentityHashes[] = $hash;
    }
}
$identityHashes = array_values(array_unique($validatedIdentityHashes));
if (empty($identityHashes)) {
    user_reg_confirm_response(false, 'REGISTER_SESSION_INVALID', 'Register session is invalid. Please start again.', [], 400);
}

if ($termsAcceptedAt <= 0) {
    user_reg_confirm_response(false, 'TERMS_REQUIRED', 'Terms & Conditions acceptance is required. Please start again.', [], 422);
}

if (user_reg_confirm_find_uid_by_phone($phone, $phoneCountry) !== '') {
    user_reg_confirm_response(false, 'DUPLICATE_PHONE', 'Phone number already registered', [], 409);
}

if (user_reg_confirm_find_uid_by_email($email) !== '') {
    user_reg_confirm_response(false, 'DUPLICATE_EMAIL', 'Email already registered', [], 409);
}

$identityLookup = user_web_registration_identity_lookup($identityHashes, $identityType);
if (empty($identityLookup['ok'])) {
    user_reg_confirm_response(false, 'IDENTITY_CHECK_UNAVAILABLE', 'Identity availability could not be checked. Please try again.', [], 503);
}
if (!empty($identityLookup['occupied'])) {
    $identityCode = $identityType === 'PASSPORT' ? 'PASSPORT_ALREADY_REGISTERED' : 'NID_ALREADY_REGISTERED';
    $identityMessage = $identityType === 'PASSPORT'
        ? 'This Passport is already registered.'
        : 'This NID is already registered.';
    user_reg_confirm_response(false, $identityCode, $identityMessage, [], 409);
}

$kycState = user_registration_kyc_state($preAuthRow, $preAuthToken);
if (empty($kycState['kyc_ready'])) {
    user_reg_confirm_response(false, 'KYC_REQUIRED', 'Identity document and selfie verification are required.', [
        'document_required' => empty($kycState['document_ready']),
        'selfie_required' => empty($kycState['selfie_ready']),
    ], 422);
}
$preAuthKyc = is_array($kycState['kyc'] ?? null) ? (array)$kycState['kyc'] : [];
$kycType = strtoupper(trim((string)($preAuthKyc['document_type'] ?? $preAuthKyc['type'] ?? '')));
$kycIdentityHash = trim((string)($preAuthKyc['identity_number_hash'] ?? ''));
if (
    !hash_equals($identityType, $kycType)
    || $kycIdentityHash === ''
    || !hash_equals($identityHash, $kycIdentityHash)
) {
    user_reg_confirm_response(false, 'KYC_SESSION_MISMATCH', 'Registration verification does not match these account details.', [], 409);
}

$otpClaim = auth_otp_claim_verification($otpRequestId, 'USER_REGISTER', $uid, $otp, $now);
if (empty($otpClaim['ok'])) {
    user_reg_confirm_response(
        false,
        (string)($otpClaim['code'] ?? 'OTP_VERIFY_FAILED'),
        (string)($otpClaim['message'] ?? 'OTP verification failed'),
        (array)($otpClaim['data'] ?? []),
        (int)($otpClaim['http_status'] ?? 400)
    );
}
$otpOwner = (string)($otpClaim['owner_token'] ?? '');

$userKyc = [
    'type' => $identityType,
    'document_type' => $identityType,
    'identity_number_hash' => $identityHash,
    'identity_number_last4' => $identityLast4,
    'document_path_private' => (string)$kycState['document_path_private'],
    'document_mime' => (string)($preAuthKyc['document_mime'] ?? ''),
    'document_size' => (int)($preAuthKyc['document_size'] ?? 0),
    'document_uploaded_at' => (int)($preAuthKyc['document_uploaded_at'] ?? 0),
    'document_upload_ref' => 'DOCUMENT',
    'selfie_path_private' => (string)$kycState['selfie_path_private'],
    'selfie_mime' => (string)($preAuthKyc['selfie_mime'] ?? ''),
    'selfie_size' => (int)($preAuthKyc['selfie_size'] ?? 0),
    'selfie_uploaded_at' => (int)($preAuthKyc['selfie_uploaded_at'] ?? 0),
    'selfie_upload_ref' => 'SELFIE',
    'status' => 'PENDING_REVIEW',
    'created_at' => $now,
    'updated_at' => $now,
];

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
    'ip_source' => (string)($preAuthRow['ip_source'] ?? ''),
    'country_mismatch' => array_key_exists('country_mismatch', $preAuthRow)
        ? (bool)$preAuthRow['country_mismatch']
        : market_gps_ip_country_mismatch($preAuthRow['gps_country'] ?? '', $preAuthRow['ip_country'] ?? ''),
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
    'identity_type' => $identityType,
    'identity_number_hash' => $identityHash,
    'identity_number_last4' => $identityLast4,
    'kyc_status' => 'PENDING_REVIEW',
    'KYC' => $userKyc,
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

$phoneIndexes = auth_phone_index_candidates($phone, $phoneCountry);
$identityIndexPaths = [];
foreach ($identityHashes as $hash) {
    $identityIndexPaths = array_merge($identityIndexPaths, reg_app_document_index_paths($hash, $identityType));
}
$identityIndexPaths = array_values(array_unique($identityIndexPaths));

$indexClaims = [];
$indexPayloads = [];
foreach ($phoneIndexes as $phoneIndex) {
    $indexPayloads['USER_INDEX/PHONE/' . $phoneIndex] = $uid;
}
foreach (auth_email_index_keys($email) as $emailIndexKey) {
    $indexPayloads['USER_INDEX/EMAIL/' . $emailIndexKey] = $uid;
}
foreach ($identityIndexPaths as $path) {
    $indexPayloads[$path] = [
        'uid' => $uid,
        'document_type' => $identityType,
        'identity_number_last4' => $identityLast4,
        'created_at' => $now,
    ];
}

foreach ($indexPayloads as $path => $payload) {
    $claim = auth_index_claim($path, $uid, $payload);
    if (empty($claim['ok'])) {
        foreach (array_reverse($indexClaims) as $claimedPath) {
            @auth_index_release($claimedPath, $uid);
        }
        auth_otp_release_verification($otpRequestId, $otpOwner, $now);

        $code = str_contains($path, '/EMAIL/')
            ? 'EMAIL_ALREADY_REGISTERED'
            : (str_contains($path, '/PHONE/')
                ? 'PHONE_ALREADY_REGISTERED'
                : ($identityType === 'PASSPORT' ? 'PASSPORT_ALREADY_REGISTERED' : 'NID_ALREADY_REGISTERED'));
        $message = $code === 'EMAIL_ALREADY_REGISTERED'
            ? 'This email is already registered.'
            : ($code === 'PHONE_ALREADY_REGISTERED'
                ? 'This phone number is already registered.'
                : ($identityType === 'PASSPORT'
                    ? 'This Passport is already registered.'
                    : 'This NID is already registered.'));
        user_reg_confirm_response(false, $code, $message, [], !empty($claim['conflict']) ? 409 : 500);
    }

    if (!empty($claim['claimed'])) {
        $indexClaims[] = $path;
    }
}

$okUser = fb_put('USERS/' . $uid, $userRow);
$okWallet = $okUser ? fb_put('USER_WALLETS/' . $uid, $walletRow) : false;
$okRole = $okWallet ? fb_put('USER_ROLE_SETTINGS/' . $uid, $roleSettings) : false;

if (!($okUser && $okWallet && $okRole)) {
    user_reg_confirm_delete_if_exists('USERS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_WALLETS/' . $uid);
    user_reg_confirm_delete_if_exists('USER_ROLE_SETTINGS/' . $uid);
    foreach (array_reverse($indexClaims) as $path) {
        @auth_index_release($path, $uid);
    }
    auth_otp_release_verification($otpRequestId, $otpOwner, $now);

    user_reg_confirm_response(false, 'SERVER_ERROR', 'Failed to create account', [], 500);
}

$otpFinalized = auth_otp_complete_verification($otpRequestId, $otpOwner, $now);

@fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $preAuthToken, [
    'status' => 'COMPLETED',
    'verified_at' => $now,
    'completed_at' => $now,
    'otp_finalize_pending' => !$otpFinalized,
    'updated_at' => $now,
]);

if (!$otpFinalized && function_exists('system_log')) {
    system_log('USER_REGISTER_OTP_FINALIZE_PENDING', $uid, 'Account created but OTP finalization needs review', [
        'uid' => $uid,
        'otp_request_id' => $otpRequestId,
    ]);
}

if (function_exists('system_log')) {
    system_log('USER_REGISTER_COMPLETED', $uid, 'User register completed with OTP', [
        'uid' => $uid,
        'phone' => $phone,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'gps_country' => (string)($preAuthRow['gps_country'] ?? ''),
        'ip_country' => $ipCountry,
        'ip_source' => (string)($preAuthRow['ip_source'] ?? ''),
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
        'ip_source' => (string)($preAuthRow['ip_source'] ?? ''),
        'account_status' => $accountStatus,
        'review_required' => $requiresReview,
        'requires_admin_review' => $requiresReview,
    ]);
