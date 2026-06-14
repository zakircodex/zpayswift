<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function otp_my_template_map(): array
{
    return [
        'USER_LOGIN' => 'RM0 Z-Pay Swift login OTP is %%. Valid for 5 minutes. Do not share this code.',
        'USER_REGISTER' => 'RM0 Z-Pay Swift registration OTP is %%. Valid for 5 minutes. Do not share this code.',
        'USER_RESET' => 'RM0 Z-Pay Swift account reset OTP is %%. Valid for 5 minutes. Do not share this code.',
        'ADMIN_LOGIN' => 'RM0 Z-Pay Swift admin login OTP is %%. Valid for 5 minutes. Do not share this code.',
        'ADMIN_RESET' => 'RM0 Z-Pay Swift admin reset OTP is %%. Valid for 5 minutes. Do not share this code.',
        'SUBADMIN_LOGIN' => 'RM0 Z-Pay Swift subadmin login OTP is %%. Valid for 5 minutes. Do not share this code.',
        'SUBADMIN_RESET' => 'RM0 Z-Pay Swift subadmin reset OTP is %%. Valid for 5 minutes. Do not share this code.',
        'PIN_VERIFY' => 'RM0 Z-Pay Swift PIN verification OTP is %%. Valid for 5 minutes. Do not share this code.',
        'BALANCE_DEDUCT' => 'RM0 Z-Pay Swift balance deduction OTP is %%. Valid for 5 minutes. Do not share this code.',
    ];
}

function otp_normalize_template_key(string $templateKey): string
{
    return strtoupper(trim($templateKey));
}

function otp_my_build_message(string $templateKey, string $otpCode): string
{
    $templateKey = otp_normalize_template_key($templateKey);
    $templates = otp_my_template_map();

    if (!isset($templates[$templateKey]) || preg_match('/^\d{6}$/', $otpCode) !== 1) {
        return '';
    }

    return str_replace('%%', $otpCode, $templates[$templateKey]);
}

function otp_my_message_is_approved(string $message): bool
{
    $message = trim($message);

    foreach (otp_my_template_map() as $template) {
        $pattern = str_replace('%%', '\d{6}', preg_quote($template, '/'));
        if (preg_match('/^' . $pattern . '$/D', $message) === 1) {
            return true;
        }
    }

    return false;
}
