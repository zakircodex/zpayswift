<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function reg_app_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function reg_app_body(): array
{
    $body = api_read_json_body();
    if ($body) {
        return $body;
    }

    return $_POST ?: [];
}

function reg_app_phone_country(array $body): string
{
    $country = auth_normalize_country_code((string)($body['phone_country'] ?? $body['country'] ?? $body['country_code'] ?? ''));
    if ($country !== '') {
        return $country;
    }

    $detected = detect_phone_country((string)($body['phone'] ?? ''));
    return $detected !== '' ? $detected : 'MY';
}

function reg_app_phone(array $body, string $country): string
{
    return normalize_phone_by_country((string)($body['phone'] ?? $body['phone_e164'] ?? ''), $country);
}

function reg_app_mask_phone(string $phone): string
{
    return function_exists('auth_app_mask_phone') ? auth_app_mask_phone($phone) : substr($phone, 0, 3) . '***' . substr($phone, -3);
}

function reg_app_token(string $prefix, int $bytes = 16): string
{
    return $prefix . strtoupper(bin2hex(random_bytes($bytes)));
}

function reg_app_phone_uid(string $phone, string $country): string
{
    return auth_find_uid_by_phone_country($phone, $country);
}

function reg_app_email_key(string $email): string
{
    return md5(strtolower(trim($email)));
}

function reg_app_email_uid(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    $row = fb_get('USER_INDEX/EMAIL/' . reg_app_email_key($email));
    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function reg_app_document_type(array $body): string
{
    $type = strtoupper(trim((string)($body['document_type'] ?? '')));
    if (in_array($type, ['NID', 'PASSPORT'], true)) {
        return $type;
    }

    return auth_app_identity_type($body);
}

function reg_app_document_number(array $body): string
{
    $number = trim((string)($body['document_number'] ?? ''));
    if ($number !== '') {
        return $number;
    }

    return auth_app_identity_number($body);
}

function reg_app_document_hash(string $documentNumber): string
{
    return auth_app_identity_hash($documentNumber);
}

function reg_app_document_last4(string $documentNumber): string
{
    return auth_app_identity_last4($documentNumber);
}

function reg_app_document_index_paths(string $hash, string $type = ''): array
{
    $hash = trim($hash);
    $type = strtoupper(trim($type));
    if ($hash === '') {
        return [];
    }

    $paths = [
        'USER_IDENTITY_INDEX/' . $hash,
        'USER_INDEX/IDENTITY/' . $hash,
    ];

    if ($type === 'NID') {
        $paths[] = 'USER_INDEX/NID/' . $hash;
    } elseif ($type === 'PASSPORT') {
        $paths[] = 'USER_INDEX/PASSPORT/' . $hash;
    }

    return array_values(array_unique($paths));
}

function reg_app_index_uid_from_row($row): string
{
    if (is_string($row)) {
        return trim($row);
    }

    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }

    return '';
}

function reg_app_document_owner_uid(string $hash, string $type = ''): string
{
    foreach (reg_app_document_index_paths($hash, $type) as $path) {
        $uid = reg_app_index_uid_from_row(fb_get($path));
        if ($uid !== '') {
            return $uid;
        }
    }

    return '';
}

function reg_app_find_preauth_token(array $body): string
{
    return trim((string)(
        $body['register_token']
        ?? $body['pre_auth_token']
        ?? $body['registration_token']
        ?? ''
    ));
}

function reg_app_get_preauth(string $token): array
{
    $token = trim($token);
    if ($token === '') {
        api_response(false, 'VALIDATION_ERROR', 'register_token is required.', [], 422);
    }

    $row = fb_get('AUTH_USER_REGISTER_PREAUTH/' . $token);
    if (!is_array($row)) {
        api_response(false, 'REGISTER_SESSION_EXPIRED', 'Registration session expired. Please start again.', [], 410);
    }

    $expiresAt = (int)($row['expires_at'] ?? 0);
    if ($expiresAt > 0 && $expiresAt <= reg_app_now()) {
        @fb_patch('AUTH_USER_REGISTER_PREAUTH/' . $token, [
            'status' => 'EXPIRED',
            'updated_at' => reg_app_now(),
        ]);
        api_response(false, 'REGISTER_SESSION_EXPIRED', 'Registration session expired. Please start again.', [], 410);
    }

    $row['pre_auth_token'] = $token;
    $row['register_token'] = $token;
    return $row;
}

function reg_app_require_otp_verified(array $preAuth): void
{
    if (empty($preAuth['otp_verified'])) {
        api_response(false, 'OTP_REQUIRED', 'OTP verification is required before continuing.', [], 409);
    }
}

function reg_app_device_id(array $body): string
{
    return function_exists('auth_app_device_id')
        ? auth_app_device_id($body, 'ANDROID_REGISTER')
        : trim((string)($body['device_id'] ?? 'ANDROID_REGISTER'));
}

function reg_app_device_name(array $body): string
{
    return function_exists('auth_app_device_name')
        ? auth_app_device_name($body, 'Android App')
        : trim((string)($body['device_name'] ?? 'Android App'));
}

function reg_app_market_decision(array $body, string $phoneCountry): array
{
    if (!function_exists('market_registration_decision')) {
        return [
            'ok' => true,
            'pricing_country' => $phoneCountry ?: 'MY',
            'market_country' => $phoneCountry ?: 'MY',
            'currency' => auth_country_currency($phoneCountry ?: 'MY'),
            'account_status' => 'REVIEW',
            'review_required' => true,
            'requires_admin_review' => true,
            'account_review_reason' => 'MARKET_HELPER_MISSING',
            'ip_country' => 'UNKNOWN',
            'ip_source' => 'UNKNOWN',
            'created_ip' => function_exists('client_ip') ? client_ip() : '',
        ];
    }

    return market_registration_decision($body, $phoneCountry);
}

function reg_app_common_market_fields(array $decision): array
{
    return [
        'pricing_country' => (string)($decision['pricing_country'] ?? ''),
        'market_country' => (string)($decision['market_country'] ?? $decision['pricing_country'] ?? ''),
        'service_country' => (string)($decision['service_country'] ?? $decision['pricing_country'] ?? ''),
        'currency' => (string)($decision['currency'] ?? auth_country_currency((string)($decision['pricing_country'] ?? 'MY'))),
        'gps_lat' => (float)($decision['gps_lat'] ?? 0),
        'gps_lng' => (float)($decision['gps_lng'] ?? 0),
        'gps_accuracy' => (float)($decision['gps_accuracy'] ?? 0),
        'gps_country' => (string)($decision['gps_country'] ?? ''),
        'ip_country' => (string)($decision['ip_country'] ?? 'UNKNOWN'),
        'ip_source' => (string)($decision['ip_source'] ?? 'UNKNOWN'),
        'created_ip' => (string)($decision['created_ip'] ?? ''),
        'country_mismatch' => (bool)($decision['country_mismatch'] ?? false),
        'vpn_suspected' => (bool)($decision['vpn_suspected'] ?? false),
        'market_detection_source' => (string)($decision['market_detection_source'] ?? ''),
        'account_status' => strtoupper(trim((string)($decision['account_status'] ?? 'REVIEW'))),
        'review_required' => (bool)($decision['review_required'] ?? true),
        'requires_admin_review' => (bool)($decision['requires_admin_review'] ?? true),
        'account_review_reason' => (string)($decision['account_review_reason'] ?? ''),
        'ip_risk_type' => (string)($decision['ip_risk_type'] ?? ''),
        'ip_risk_score' => (int)($decision['ip_risk_score'] ?? 0),
        'ip_risk_source' => (string)($decision['ip_risk_source'] ?? ''),
        'ip_risk_reason' => (string)($decision['ip_risk_reason'] ?? ''),
    ];
}

function reg_app_send_register_sms(string $country, string $phone, string $otpCode, string $otpRequestId): array
{
    $message = 'Z-Pay Swift register OTP is ' . $otpCode . '. Valid for 5 minutes. Do not share this code.';

    return auth_send_otp_sms_by_country(
        $country,
        $phone,
        $message,
        $otpRequestId,
        'USER_REGISTER',
        $otpCode
    );
}

function reg_app_otp_attempt_error(string $otpRequestId, array $otpRow, int $now): void
{
    $failedState = auth_otp_record_failed_attempt($otpRequestId, $otpRow, $now);
    if (!empty($failedState['locked'])) {
        api_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded. Please request a new OTP.', [
            'attempts_left' => 0,
        ], 423);
    }

    api_response(false, 'OTP_INVALID', 'Wrong OTP. Please try again.', [
        'attempts_left' => (int)($failedState['attempts_left'] ?? 0),
    ], 400);
}

function reg_app_password_valid(string $password): bool
{
    return strlen($password) >= 6;
}

function reg_app_pin_valid(string $pin): bool
{
    return preg_match('/^\d{4,8}$/', $pin) === 1;
}
