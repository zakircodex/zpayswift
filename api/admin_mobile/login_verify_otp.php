<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';

api_require_method('POST');
admin_mobile_require_available();

$body = api_read_json_body();
$deviceId = admin_mobile_device_id(true);
$deviceName = admin_mobile_device_name($body);
$payload = [
    'pre_auth_token' => trim((string)($body['pre_auth_token'] ?? '')),
    'otp_request_id' => trim((string)($body['otp_request_id'] ?? '')),
    'otp' => trim((string)($body['otp'] ?? '')),
    'trust_device' => !array_key_exists('trust_device', $body) || !empty($body['trust_device']),
    'device_id' => $deviceId,
    'device_name' => $deviceName,
];

if ($payload['pre_auth_token'] === '' || $payload['otp_request_id'] === '' || $payload['otp'] === '') {
    api_response(false, 'VALIDATION_ERROR', 'OTP verification details are required.', [], 422);
}

$result = admin_mobile_internal_request('POST', 'auth/admin_login_verify_otp.php', $payload);
if (empty($result['ok'])) {
    admin_mobile_emit_internal($result);
}

$json = (array)($result['json'] ?? []);
$data = (array)($json['data'] ?? []);
$finalized = admin_mobile_finalize_session(
    (string)($data['session_token'] ?? ''),
    $deviceId,
    $deviceName
);
if (empty($finalized['ok'])) {
    api_response(false, (string)($finalized['code'] ?? 'LOGIN_FAILED'), (string)($finalized['message'] ?? 'Login failed.'), [], 403);
}

api_response(true, 'ADMIN_MOBILE_LOGIN_OK', 'OTP verified successfully.', array_merge($data, $finalized));
