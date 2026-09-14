<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';

api_require_method('POST');
admin_mobile_require_available();
admin_mobile_device_id(true);

$body = api_read_json_body();
$payload = [
    'pre_auth_token' => trim((string)($body['pre_auth_token'] ?? '')),
    'otp_request_id' => trim((string)($body['otp_request_id'] ?? '')),
];
if ($payload['pre_auth_token'] === '' || $payload['otp_request_id'] === '') {
    api_response(false, 'VALIDATION_ERROR', 'OTP request details are required.', [], 422);
}

$result = admin_mobile_internal_request('POST', 'auth/admin_login_resend_otp.php', $payload);
admin_mobile_emit_internal($result);
