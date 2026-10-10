<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

if (!function_exists('app_private_sms_bridge_path')) {
    $appPathsFile = __DIR__ . '/app_paths.php';
    if (is_file($appPathsFile)) {
        require_once $appPathsFile;
    }
}

require_once __DIR__ . '/phone_country.php';
require_once __DIR__ . '/otp_templates.php';
require_once __DIR__ . '/sms_bulksmsbd.php';
require_once __DIR__ . '/sms_smss360.php';

function auth_sms_bridge_file(): string
{
    if (function_exists('app_private_sms_bridge_path')) {
        return app_private_sms_bridge_path();
    }

    $primary = '/home/zedpayhe/private/zpayswift/auth_sms_bridge.php';
    $legacy = '/home/zedpayhe/private/zawtopup/auth_sms_bridge.php';

    if (is_file($primary)) {
        return $primary;
    }

    if (is_file($legacy)) {
        return $legacy;
    }

    return $primary;
}

function auth_sms_load_bridge(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $file = auth_sms_bridge_file();
    if (is_file($file)) {
        require_once $file;
    }

    $loaded = true;
}

function auth_sms_normalize_bd_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';

    if ($phone === '') {
        return '';
    }

    if (strpos($phone, '880') === 0) {
        return $phone;
    }

    if (strpos($phone, '01') === 0) {
        return '88' . $phone;
    }

    if (strpos($phone, '1') === 0 && strlen($phone) === 10) {
        return '880' . $phone;
    }

    return $phone;
}

function auth_sms_stage_preview_enabled(): bool
{
    return defined('APP_ENVIRONMENT')
        && strtolower(trim((string)constant('APP_ENVIRONMENT'))) === 'stage'
        && defined('APP_PUBLIC_ORIGIN')
        && rtrim(strtolower(trim((string)constant('APP_PUBLIC_ORIGIN'))), '/') === 'https://stage.zpayswift.com'
        && defined('STAGE_AUTH_OTP_PREVIEW_ENABLED')
        && constant('STAGE_AUTH_OTP_PREVIEW_ENABLED') === true
        && function_exists('app_is_stage_host')
        && app_is_stage_host();
}

function auth_sms_stage_preview_allowed(string $country, string $phone, string $templateKey): bool
{
    if (!auth_sms_stage_preview_enabled()) {
        return false;
    }

    $country = auth_normalize_country_code($country);
    $phone = normalize_phone_by_country($phone, $country);
    $templateKey = otp_normalize_template_key($templateKey);
    $allowedPhones = defined('STAGE_AUTH_OTP_PREVIEW_PHONES')
        ? constant('STAGE_AUTH_OTP_PREVIEW_PHONES')
        : [];
    $allowedPurposes = defined('STAGE_AUTH_OTP_PREVIEW_PURPOSES')
        ? constant('STAGE_AUTH_OTP_PREVIEW_PURPOSES')
        : [];

    if ($phone === '' || !is_array($allowedPhones) || !is_array($allowedPurposes)) {
        return false;
    }

    $normalizedPhones = [];
    foreach ($allowedPhones as $allowedPhone) {
        $normalized = preg_replace('/\D+/', '', trim((string)$allowedPhone)) ?? '';
        if ($normalized !== '') {
            $normalizedPhones[] = $normalized;
        }
    }
    $normalizedPurposes = array_map(
        static fn($purpose): string => otp_normalize_template_key((string)$purpose),
        $allowedPurposes
    );

    return in_array($phone, $normalizedPhones, true)
        && in_array($templateKey, $normalizedPurposes, true);
}

function auth_sms_prepare_otp_code(
    string $country,
    string $phone,
    string $templateKey,
    string $referenceId,
    string $fallbackCode
): string {
    if (!auth_sms_stage_preview_allowed($country, $phone, $templateKey)) {
        return $fallbackCode;
    }

    $appKey = defined('APP_KEY') ? trim((string)constant('APP_KEY')) : '';
    $phone = normalize_phone_by_country($phone, auth_normalize_country_code($country));
    $referenceId = trim($referenceId);
    if ($appKey === '' || $phone === '' || $referenceId === '') {
        return $fallbackCode;
    }

    $seed = otp_normalize_template_key($templateKey) . '|' . $phone . '|' . $referenceId;
    $bucket = hexdec(substr(hash_hmac('sha256', $seed, $appKey), 0, 7));
    return (string)(100000 + ((int)$bucket % 900000));
}

function auth_sms_stage_preview_result(
    string $country,
    string $phone,
    string $referenceId,
    string $templateKey,
    string $otpCode
): ?array {
    if (!auth_sms_stage_preview_enabled()) {
        return null;
    }

    if (!auth_sms_stage_preview_allowed($country, $phone, $templateKey)) {
        return [
            'ok' => false,
            'gateway' => 'STAGE_PREVIEW',
            'code' => 'STAGE_PREVIEW_NOT_ALLOWED',
            'message' => 'Stage OTP preview is not enabled for this request',
            'reference_id' => $referenceId,
            'template_key' => otp_normalize_template_key($templateKey),
        ];
    }

    $expectedCode = auth_sms_prepare_otp_code($country, $phone, $templateKey, $referenceId, '');
    if ($expectedCode === '' || !hash_equals($expectedCode, $otpCode)) {
        return [
            'ok' => false,
            'gateway' => 'STAGE_PREVIEW',
            'code' => 'STAGE_PREVIEW_CODE_MISMATCH',
            'message' => 'Stage OTP preview code mismatch',
            'reference_id' => $referenceId,
            'template_key' => otp_normalize_template_key($templateKey),
        ];
    }

    return [
        'ok' => true,
        'gateway' => 'STAGE_PREVIEW',
        'code' => 'STAGE_PREVIEW_READY',
        'message' => 'Stage OTP preview prepared without external delivery',
        'reference_id' => $referenceId,
        'template_key' => otp_normalize_template_key($templateKey),
    ];
}

function auth_sms_stage_preview_response_fields(
    string $country,
    string $phone,
    string $templateKey,
    string $referenceId
): array {
    if (!auth_sms_stage_preview_allowed($country, $phone, $templateKey)) {
        return [];
    }

    $otpCode = auth_sms_prepare_otp_code($country, $phone, $templateKey, $referenceId, '');
    if (preg_match('/^\d{6}$/D', $otpCode) !== 1) {
        return [];
    }

    return [
        'stage_otp_preview' => true,
        'stage_otp' => $otpCode,
    ];
}

function auth_send_otp_sms(string $phone, string $message): bool
{
    $country = detect_phone_country($phone);
    if ($country === '') {
        $country = 'BD';
    }

    if ($country === 'MY') {
        return false;
    }

    $result = auth_send_otp_sms_by_country(
        $country,
        $phone,
        $message,
        'OTP' . strtoupper(bin2hex(random_bytes(4)))
    );
    return !empty($result['ok']);
}

function auth_send_bd_sms(string $phone, string $message, string $referenceId = ''): array
{
    auth_sms_load_bridge();

    $phone = normalize_phone_by_country($phone, 'BD');
    $message = trim($message);

    if ($phone === '' || $message === '') {
        return [
            'ok' => false,
            'gateway' => 'BULKSMSBD',
            'code' => 'LOCAL_INVALID_INPUT',
            'message' => 'Invalid Bangladesh SMS input',
            'reference_id' => $referenceId,
        ];
    }

    if (function_exists('private_auth_send_otp_sms')) {
        try {
            $ok = (bool)private_auth_send_otp_sms($phone, $message);
            return [
                'ok' => $ok,
                'gateway' => 'BULKSMSBD',
                'code' => $ok ? 'BRIDGE_ACCEPTED' : 'BRIDGE_FAILED',
                'message' => $ok ? 'SMS accepted by private bridge' : 'Private SMS bridge rejected request',
                'reference_id' => $referenceId,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'gateway' => 'BULKSMSBD',
                'code' => 'BRIDGE_ERROR',
                'message' => 'Private SMS bridge error',
                'reference_id' => $referenceId,
            ];
        }
    }

    $result = bulksmsbd_send_sms($phone, $message);
    return [
        'ok' => !empty($result['ok']),
        'gateway' => 'BULKSMSBD',
        'code' => (string)($result['code'] ?? ''),
        'message' => (string)($result['message'] ?? ''),
        'reference_id' => $referenceId,
    ];
}

function auth_send_my_sms360(string $phone, string $message, string $referenceId): array
{
    $result = smss360_send_sms($phone, $message, $referenceId);
    return [
        'ok' => !empty($result['ok']),
        'gateway' => 'SMS360',
        'code' => (string)($result['code'] ?? ''),
        'message' => (string)($result['message'] ?? ''),
        'reference_id' => (string)($result['reference_id'] ?? $referenceId),
    ];
}

function auth_send_otp_sms_by_country(
    string $country,
    string $phone,
    string $message,
    string $referenceId,
    string $templateKey = '',
    string $otpCode = ''
): array {
    $country = auth_normalize_country_code($country);
    $templateKey = otp_normalize_template_key($templateKey);
    $stagePreview = auth_sms_stage_preview_result(
        $country,
        $phone,
        $referenceId,
        $templateKey,
        $otpCode
    );
    if (is_array($stagePreview)) {
        return $stagePreview;
    }

    if ($country === 'MY') {
        $approvedMessage = otp_my_build_message($templateKey, $otpCode);
        if ($approvedMessage === '') {
            return [
                'ok' => false,
                'gateway' => 'SMS360',
                'code' => 'MY_TEMPLATE_REQUIRED',
                'message' => 'Approved Malaysia OTP template is required',
                'reference_id' => $referenceId,
                'template_key' => $templateKey,
            ];
        }

        return auth_send_my_sms360($phone, $approvedMessage, $referenceId) + [
            'template_key' => $templateKey,
        ];
    }

    if ($country === 'BD') {
        return auth_send_bd_sms($phone, $message, $referenceId) + [
            'template_key' => $templateKey,
        ];
    }

    return [
        'ok' => false,
        'gateway' => '',
        'code' => 'UNSUPPORTED_COUNTRY',
        'message' => 'Unsupported phone country',
        'reference_id' => $referenceId,
        'template_key' => $templateKey,
    ];
}

function auth_sms_result_log_fields(array $result): array
{
    return [
        'sms_gateway' => (string)($result['gateway'] ?? ''),
        'sms_reference_id' => (string)($result['reference_id'] ?? ''),
        'sms_status_code' => (string)($result['code'] ?? ''),
        'sms_status_msg' => substr((string)($result['message'] ?? ''), 0, 300),
        'sms_template_key' => (string)($result['template_key'] ?? ''),
    ];
}
