<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';
require_once __DIR__ . '/../lib/account_review.php';

api_require_method('POST');
api_require_app_key();

$body = reg_app_body();
$registerToken = reg_app_find_preauth_token($body);
$preAuth = reg_app_get_preauth($registerToken);
reg_app_require_otp_verified($preAuth);

$now = reg_app_now();
$name = trim((string)($body['name'] ?? $preAuth['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? $preAuth['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? $password);
$pin = trim((string)($body['pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? $pin));

if ($name === '') {
    api_response(false, 'VALIDATION_ERROR', 'Full name is required.', ['field' => 'name'], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_response(false, 'VALIDATION_ERROR', 'Valid email is required.', ['field' => 'email'], 422);
}

if (!reg_app_password_valid($password)) {
    api_response(false, 'VALIDATION_ERROR', 'Password must be at least 6 characters.', ['field' => 'password'], 422);
}

if ($password !== $confirmPassword) {
    api_response(false, 'VALIDATION_ERROR', 'Password confirmation does not match.', ['field' => 'confirm_password'], 422);
}

if (!reg_app_pin_valid($pin)) {
    api_response(false, 'VALIDATION_ERROR', 'PIN must be 4 to 8 digits.', ['field' => 'pin'], 422);
}

if ($pin !== $confirmPin) {
    api_response(false, 'VALIDATION_ERROR', 'PIN confirmation does not match.', ['field' => 'confirm_pin'], 422);
}

$phoneCountry = auth_normalize_country_code((string)($preAuth['phone_country'] ?? ''));
$phone = normalize_phone_by_country((string)($preAuth['phone'] ?? $body['phone'] ?? ''), $phoneCountry);
if ($phoneCountry === '' || $phone === '') {
    api_response(false, 'REGISTER_SESSION_INVALID', 'Registration phone state is invalid. Please start again.', [], 400);
}

if (reg_app_phone_uid($phone, $phoneCountry) !== '') {
    api_response(false, 'PHONE_ALREADY_REGISTERED', 'This phone number is already registered. Please login.', [], 409);
}

if ($email !== '' && reg_app_email_uid($email) !== '') {
    api_response(false, 'EMAIL_ALREADY_REGISTERED', 'This email is already registered. Please login.', [], 409);
}

$documentType = reg_app_document_type($body + $preAuth);
$documentNumber = reg_app_document_number($body);
$documentHash = reg_app_document_hash($documentNumber);
$documentLast4 = reg_app_document_last4($documentNumber);

if ($documentHash === '') {
    $documentHash = trim((string)($preAuth['identity_number_hash'] ?? $preAuth['document_number_hash'] ?? ''));
    $documentLast4 = trim((string)($preAuth['identity_number_last4'] ?? $preAuth['document_number_last4'] ?? ''));
    $documentType = strtoupper(trim((string)($preAuth['identity_type'] ?? $preAuth['document_type'] ?? $documentType)));
}

if ($documentHash === '' || !in_array($documentType, ['NID', 'PASSPORT'], true)) {
    api_response(false, 'DOCUMENT_REQUIRED', 'NID/Passport verification is required.', [], 422);
}

if (empty($preAuth['document_verified'])) {
    $owner = reg_app_document_owner_uid($documentHash, $documentType);
    if ($owner !== '') {
        api_response(false, 'DOCUMENT_ALREADY_USED', 'This NID/Passport is already used by another account.', [], 409);
    }
} else {
    $storedDocumentHash = trim((string)($preAuth['identity_number_hash'] ?? $preAuth['document_number_hash'] ?? ''));
    if ($storedDocumentHash === '' || !hash_equals($storedDocumentHash, $documentHash)) {
        api_response(false, 'DOCUMENT_MISMATCH', 'Document information changed. Please verify document again.', [], 409);
    }
}

if (reg_app_document_owner_uid($documentHash, $documentType) !== '') {
    api_response(false, 'DOCUMENT_ALREADY_USED', 'This NID/Passport is already used by another account.', [], 409);
}

$pricingCountry = auth_normalize_country_code((string)($preAuth['pricing_country'] ?? $preAuth['market_country'] ?? ''));
if ($pricingCountry === '') {
    $pricingCountry = 'MY';
}

$currency = auth_country_currency($pricingCountry);
$accountStatus = strtoupper(trim((string)($preAuth['account_status'] ?? 'ACTIVE')));
if (!in_array($accountStatus, ['ACTIVE', 'REVIEW', 'BLOCKED', 'REJECTED'], true)) {
    $accountStatus = 'REVIEW';
}

$reviewRequired = $accountStatus !== 'ACTIVE';
$uid = function_exists('make_uid') ? make_uid() : ('U' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3))));
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$pinHash = password_hash($pin, PASSWORD_DEFAULT);
$deviceId = reg_app_device_id($body + $preAuth);
$deviceName = reg_app_device_name($body + $preAuth);
$appVersion = trim((string)($body['app_version'] ?? $preAuth['app_version'] ?? ''));

$firstNonEmpty = static function (...$values): string {
    foreach ($values as $value) {
        $clean = trim((string)$value);
        $upper = strtoupper($clean);
        if ($clean !== '' && !in_array($upper, ['DOCUMENT', 'SELFIE'], true)) {
            return $clean;
        }
    }

    return '';
};

$documentPath = $firstNonEmpty(
    $body['document_path_private'] ?? '',
    $body['document_photo_path'] ?? '',
    $preAuth['document_path_private'] ?? '',
    $preAuth['KYC']['document_path_private'] ?? '',
    $preAuth['kyc']['document_path'] ?? '',
    $preAuth['kyc']['document_path_private'] ?? ''
);
$selfiePath = $firstNonEmpty(
    $body['selfie_path_private'] ?? '',
    $body['selfie_photo_path'] ?? '',
    $preAuth['selfie_path_private'] ?? '',
    $preAuth['KYC']['selfie_path_private'] ?? '',
    $preAuth['kyc']['selfie_path'] ?? '',
    $preAuth['kyc']['selfie_path_private'] ?? ''
);
$documentUploadRef = trim((string)($body['document_upload_ref'] ?? $preAuth['document_upload_ref'] ?? ''));
$selfieUploadRef = trim((string)($body['selfie_upload_ref'] ?? $preAuth['selfie_upload_ref'] ?? ''));

foreach ([$documentPath, $selfiePath] as $pathValue) {
    if ($pathValue !== '' && (str_contains($pathValue, '..') || preg_match('/^https?:\/\//i', $pathValue) === 1)) {
        api_response(false, 'VALIDATION_ERROR', 'KYC file reference must be a private storage path.', [], 422);
    }
}

if ($documentPath === '' || $selfiePath === '') {
    api_response(false, 'KYC_REQUIRED', 'Document photo and selfie are required.', [
        'document_required' => $documentPath === '',
        'selfie_required' => $selfiePath === '',
    ], 422);
}

$kyc = [
    'type' => $documentType,
    'identity_number_hash' => $documentHash,
    'identity_number_last4' => $documentLast4,
    'status' => 'PENDING_REVIEW',
    'created_at' => $now,
    'updated_at' => $now,
];

if ($documentPath !== '') {
    $kyc['document_path_private'] = $documentPath;
}
if ($selfiePath !== '') {
    $kyc['selfie_path_private'] = $selfiePath;
}
if ($documentUploadRef !== '') {
    $kyc['document_upload_ref'] = $documentUploadRef;
}
if ($selfieUploadRef !== '') {
    $kyc['selfie_upload_ref'] = $selfieUploadRef;
}

$userRow = [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'phone_e164' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'country_code' => $pricingCountry,
    'country' => $pricingCountry,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'email' => $email,
    'role' => 'USER',
    'status' => $accountStatus,
    'account_status' => $accountStatus,
    'review_required' => $reviewRequired,
    'requires_admin_review' => $reviewRequired,
    'password_hash' => $passwordHash,
    'pin_hash' => $pinHash,
    'identity_type' => $documentType,
    'identity_number_hash' => $documentHash,
    'identity_number_last4' => $documentLast4,
    'kyc_status' => 'PENDING_REVIEW',
    'KYC' => $kyc,
    'gps_lat' => (float)($preAuth['gps_lat'] ?? 0),
    'gps_lng' => (float)($preAuth['gps_lng'] ?? 0),
    'gps_accuracy' => (float)($preAuth['gps_accuracy'] ?? 0),
    'gps_country' => (string)($preAuth['gps_country'] ?? ''),
    'ip_country' => (string)($preAuth['ip_country'] ?? 'UNKNOWN'),
    'ip_source' => (string)($preAuth['ip_source'] ?? 'UNKNOWN'),
    'created_ip' => (string)($preAuth['created_ip'] ?? ''),
    'registration_ip' => (string)($preAuth['created_ip'] ?? ''),
    'country_mismatch' => (bool)($preAuth['country_mismatch'] ?? ($phoneCountry !== $pricingCountry)),
    'vpn_suspected' => (bool)($preAuth['vpn_suspected'] ?? false),
    'market_detection_source' => (string)($preAuth['market_detection_source'] ?? ''),
    'account_review_reason' => (string)($preAuth['account_review_reason'] ?? ''),
    'ip_risk_type' => (string)($preAuth['ip_risk_type'] ?? ''),
    'ip_risk_score' => (int)($preAuth['ip_risk_score'] ?? 0),
    'ip_risk_source' => (string)($preAuth['ip_risk_source'] ?? ''),
    'user_agent' => (string)($preAuth['user_agent'] ?? auth_request_user_agent($body)),
    'browser_timezone' => (string)($preAuth['browser_timezone'] ?? auth_request_browser_timezone($body)),
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'app_version' => $appVersion,
    'active_device_id' => '',
    'ACTIVE_DEVICE_ID' => '',
    'last_login_at' => 0,
    'last_login_ip' => '',
    'created_at' => $now,
    'updated_at' => $now,
    'created_by_admin' => false,
    'created_by_uid' => '',
    'created_by_role' => 'SELF',
    'parent_subadmin_uid' => '',
    'register_source' => 'ANDROID_APP',
    'terms_accepted_at' => (int)($body['terms_accepted_at'] ?? $preAuth['terms_accepted_at'] ?? $now),
];

$walletRow = [
    'available_balance' => 0,
    'balance' => 0,
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

$roleSettings = role_default_settings('USER');
$roleSettings['api_enabled'] = false;
$roleSettings['updated_at'] = $now;

$phoneIndexes = auth_phone_index_candidates($phone, $phoneCountry);
$documentIndexPaths = reg_app_document_index_paths($documentHash, $documentType);
$emailIndexPath = $email !== '' ? 'USER_INDEX/EMAIL/' . reg_app_email_key($email) : '';

$written = [];
$ok = fb_put('USERS/' . $uid, $userRow);
if ($ok) {
    $written[] = 'USERS/' . $uid;
    $ok = fb_put('USER_WALLETS/' . $uid, $walletRow);
}
if ($ok) {
    $written[] = 'USER_WALLETS/' . $uid;
    $ok = fb_put('USER_ROLE_SETTINGS/' . $uid, $roleSettings);
}
if ($ok) {
    $written[] = 'USER_ROLE_SETTINGS/' . $uid;
    foreach ($phoneIndexes as $phoneIndex) {
        $path = 'USER_INDEX/PHONE/' . $phoneIndex;
        if (!fb_put($path, $uid)) {
            $ok = false;
            break;
        }
        $written[] = $path;
    }
}
if ($ok && $emailIndexPath !== '') {
    $ok = fb_put($emailIndexPath, $uid);
    if ($ok) {
        $written[] = $emailIndexPath;
    }
}
if ($ok) {
    foreach ($documentIndexPaths as $path) {
        if (!fb_put($path, [
            'uid' => $uid,
            'document_type' => $documentType,
            'identity_number_last4' => $documentLast4,
            'created_at' => $now,
        ])) {
            $ok = false;
            break;
        }
        $written[] = $path;
    }
}

if (!$ok) {
    foreach (array_reverse($written) as $path) {
        @fb_delete($path);
    }

    api_response(false, 'SERVER_ERROR', 'Failed to create account.', [], 500);
}

@fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, [
    'uid' => $uid,
    'status' => 'COMPLETED',
    'completed_at' => $now,
    'updated_at' => $now,
]);

if (function_exists('system_log')) {
    system_log('ANDROID_REGISTER_COMPLETED', $uid, 'Android registration completed', [
        'uid' => $uid,
        'phone_country' => $phoneCountry,
        'pricing_country' => $pricingCountry,
        'account_status' => $accountStatus,
        'kyc_status' => 'PENDING_REVIEW',
        'gps_country' => (string)($preAuth['gps_country'] ?? ''),
        'ip_country' => (string)($preAuth['ip_country'] ?? 'UNKNOWN'),
    ]);
}

$telegramReviewSent = false;
if ($reviewRequired && function_exists('account_review_send_telegram')) {
    $telegramResult = account_review_send_telegram($uid, $userRow);
    $telegramReviewSent = !empty($telegramResult['ok']);
    @fb_patch('USERS/' . $uid, [
        'telegram_review_sent' => $telegramReviewSent,
        'telegram_review_updated_at' => $now,
        'telegram_review_error' => $telegramReviewSent ? '' : substr((string)($telegramResult['message'] ?? ''), 0, 300),
        'updated_at' => $now,
    ]);
}

api_response(true, $reviewRequired ? 'REGISTER_REVIEW' : 'REGISTER_SUCCESS', $reviewRequired
    ? 'Registration submitted. Your account is under review.'
    : 'Registration successful. Please login.', [
    'uid' => $uid,
    'role' => 'USER',
    'phone' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'currency' => $currency,
    'account_status' => $accountStatus,
    'review_required' => $reviewRequired,
    'requires_admin_review' => $reviewRequired,
    'kyc_status' => 'PENDING_REVIEW',
    'telegram_review_sent' => $telegramReviewSent,
]);
