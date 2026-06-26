<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();

api_response(false, 'REGISTER_COMPLETE_NOT_SEPARATE', 'Registration is completed by register_verify_otp.php after OTP verification.', [
    'start_endpoint' => '/api/auth/register_start.php',
    'verify_endpoint' => '/api/auth/register_verify_otp.php',
], 409);
