<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

api_response(false, 'REGISTER_OTP_REQUIRED', 'Direct registration is disabled. Please start registration with OTP verification.', [
    'deprecated' => true,
    'replacement' => 'auth/user_register_send_otp.php',
    'confirm_endpoint' => 'auth/user_register_confirm.php',
    'requires_gps' => true,
    'requires_otp' => true,
], 410);
