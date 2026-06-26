<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

api_response(false, 'LOGIN_FLOW_REQUIRED', 'Please use secure staged login flow.', [
    'deprecated' => true,
    'flow' => [
        'check_number' => '/api/auth/check_number.php',
        'verify_password' => '/api/auth/verify_password.php',
        'verify_pin' => '/api/auth/verify_pin.php',
        'login_send_otp' => '/api/auth/login_send_otp.php',
        'login_verify_otp' => '/api/auth/user_login_verify_otp.php',
        'pin_login' => '/api/auth/pin_login.php',
    ],
], 409);
