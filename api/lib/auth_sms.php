<?php
declare(strict_types=1);

if (!function_exists('app_private_sms_bridge_path')) {
    $appPathsFile = __DIR__ . '/app_paths.php';
    if (is_file($appPathsFile)) {
        require_once $appPathsFile;
    }
}

function auth_sms_bridge_file(): string
{
    if (function_exists('app_private_sms_bridge_path')) {
        return app_private_sms_bridge_path();
    }

    return '/home/zedpayhe/private/zawtopup/auth_sms_bridge.php';
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
    auth_sms_load_bridge();

    $phone = auth_sms_normalize_bd_phone($phone);
    $message = trim($message);

    if ($phone === '' || $message === '') {
        return false;
    }

    if (function_exists('private_auth_send_otp_sms')) {
        try {
            return (bool) private_auth_send_otp_sms($phone, $message);
        } catch (\Throwable $e) {
            return false;
        }
    }

    return false;
}
