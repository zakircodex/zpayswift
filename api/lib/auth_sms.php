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

function auth_send_otp_sms(string $phone, string $message): bool
{
    $country = detect_phone_country($phone);
    if ($country === '') {
        $country = 'BD';
    }

    $result = auth_send_otp_sms_by_country($country, $phone, $message, 'OTP' . strtoupper(bin2hex(random_bytes(4))));
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
    string $referenceId
): array {
    $country = auth_normalize_country_code($country);

    if ($country === 'MY') {
        return auth_send_my_sms360($phone, $message, $referenceId);
    }

    if ($country === 'BD') {
        return auth_send_bd_sms($phone, $message, $referenceId);
    }

    return [
        'ok' => false,
        'gateway' => '',
        'code' => 'UNSUPPORTED_COUNTRY',
        'message' => 'Unsupported phone country',
        'reference_id' => $referenceId,
    ];
}

function auth_sms_result_log_fields(array $result): array
{
    return [
        'sms_gateway' => (string)($result['gateway'] ?? ''),
        'sms_reference_id' => (string)($result['reference_id'] ?? ''),
        'sms_status_code' => (string)($result['code'] ?? ''),
        'sms_status_msg' => substr((string)($result['message'] ?? ''), 0, 300),
    ];
}
