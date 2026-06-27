<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';
require_once __DIR__ . '/../lib/register_android.php';

api_require_method('POST');
api_require_app_key();

$body = reg_app_body();
$registerToken = reg_app_find_preauth_token($body);
$preAuth = reg_app_get_preauth($registerToken);
reg_app_require_otp_verified($preAuth);

$documentType = reg_app_document_type($body);
$documentNumber = reg_app_document_number($body);
$documentHash = reg_app_document_hash($documentNumber);
$documentLast4 = reg_app_document_last4($documentNumber);

if ($documentHash === '') {
    api_response(false, 'VALIDATION_ERROR', 'NID/Passport number is required.', [
        'field' => 'document_number',
    ], 422);
}

$existingUid = reg_app_document_owner_uid($documentHash, $documentType);
if ($existingUid !== '') {
    api_response(false, 'DOCUMENT_ALREADY_USED', 'This NID/Passport is already used by another account.', [
        'document_available' => false,
    ], 409);
}

$now = reg_app_now();
if (!fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $registerToken, [
    'document_verified' => true,
    'document_verified_at' => $now,
    'identity_type' => $documentType,
    'document_type' => $documentType,
    'identity_number_hash' => $documentHash,
    'document_number_hash' => $documentHash,
    'identity_number_last4' => $documentLast4,
    'document_number_last4' => $documentLast4,
    'KYC' => [
        'type' => $documentType,
        'identity_number_hash' => $documentHash,
        'identity_number_last4' => $documentLast4,
        'status' => 'PENDING_REVIEW',
        'updated_at' => $now,
    ],
    'status' => 'DOCUMENT_VERIFIED',
    'updated_at' => $now,
    'expires_at' => $now + 3600,
])) {
    api_response(false, 'SERVER_ERROR', 'Failed to update document verification state.', [], 500);
}

api_response(true, 'DOCUMENT_AVAILABLE', 'Document verified for registration.', [
    'document_available' => true,
    'register_token' => $registerToken,
    'pre_auth_token' => $registerToken,
    'document_type' => $documentType,
    'identity_number_last4' => $documentLast4,
]);
