<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';
require_once __DIR__ . '/../lib/user_registration_identity.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if (!in_array($phoneCountry, ['BD', 'MY'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'This phone country is not supported.', ['field' => 'phone_country'], 422);
}

$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$name = trim((string)($body['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$identityType = strtoupper(trim((string)($body['identity_type'] ?? '')));
$identityNumber = auth_app_identity_number($body);
$identityHash = auth_app_identity_hash($identityNumber);
$identityHashes = user_web_registration_identity_hashes($identityNumber);
$identityLast4 = auth_app_identity_last4($identityNumber);
$nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), ['field' => 'phone'], 422);
}
if ($name === '' || $nameLength > 100) {
    api_response(false, 'VALIDATION_ERROR', 'Please enter your full name.', ['field' => 'name'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_response(false, 'VALIDATION_ERROR', 'Please enter a valid email address.', ['field' => 'email'], 422);
}
if (!in_array($identityType, ['NID', 'PASSPORT'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'Select a valid identity type.', ['field' => 'identity_type'], 422);
}
if ($identityHash === '' || empty($identityHashes)) {
    api_response(false, 'IDENTITY_REQUIRED', $identityType === 'PASSPORT'
        ? 'Passport number is required.'
        : 'NID number is required.', ['field' => 'identity_number'], 422);
}

if (reg_app_phone_uid($phone, $phoneCountry) !== '') {
    api_response(false, 'PHONE_ALREADY_REGISTERED', 'This phone number is already registered.', [], 409);
}
if (reg_app_email_uid($email) !== '') {
    api_response(false, 'EMAIL_ALREADY_REGISTERED', 'This email is already registered.', [], 409);
}

$identityLookup = user_web_registration_identity_lookup($identityHashes, $identityType);
if (empty($identityLookup['ok'])) {
    api_response(false, 'IDENTITY_CHECK_UNAVAILABLE', 'Identity availability could not be checked. Please try again.', [], 503);
}
if (!empty($identityLookup['occupied'])) {
    api_response(
        false,
        $identityType === 'PASSPORT' ? 'PASSPORT_ALREADY_REGISTERED' : 'NID_ALREADY_REGISTERED',
        $identityType === 'PASSPORT' ? 'This Passport is already registered.' : 'This NID is already registered.',
        [],
        409
    );
}

$now = reg_app_now();
$registerToken = reg_app_token('URKYC', 20);
$uid = function_exists('make_uid') ? (string)make_uid() : ('U' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))));
$row = [
    'pre_auth_token' => $registerToken,
    'register_token' => $registerToken,
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'phone_e164' => $phone,
    'phone_country' => $phoneCountry,
    'email' => $email,
    'identity_type' => $identityType,
    'document_type' => $identityType,
    'identity_number_hash' => $identityHash,
    'identity_hash_variants' => $identityHashes,
    'identity_number_last4' => $identityLast4,
    'document_verified' => true,
    'web_kyc_draft' => true,
    'registration_source' => 'USER_WEB',
    'status' => 'KYC_PENDING',
    'KYC' => [
        'type' => $identityType,
        'document_type' => $identityType,
        'identity_number_hash' => $identityHash,
        'identity_number_last4' => $identityLast4,
        'status' => 'PENDING_UPLOAD',
        'created_at' => $now,
        'updated_at' => $now,
    ],
    'created_at' => $now,
    'updated_at' => $now,
    'expires_at' => $now + 3600,
];

if (!fb_put('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, $row)) {
    api_response(false, 'SERVER_ERROR', 'Registration verification could not be prepared.', [], 500);
}

api_response(true, 'REGISTER_KYC_READY', 'Identity verification upload is ready.', [
    'register_token' => $registerToken,
    'pre_auth_token' => $registerToken,
    'identity_type' => $identityType,
    'document_required' => true,
    'selfie_required' => true,
    'expires_in_seconds' => 3600,
]);
