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
    'phone' => trim((string)($body['phone'] ?? '')),
    'phone_country' => auth_normalize_country_code((string)($body['phone_country'] ?? '')),
    'password' => (string)($body['password'] ?? ''),
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'trust_device' => !array_key_exists('trust_device', $body) || !empty($body['trust_device']),
    'trusted_device_cookie' => trim((string)($body['trusted_device_cookie'] ?? '')),
    'client_ip' => security_client_ip(),
    'ip_country' => auth_request_ip_country(),
    'user_agent' => security_user_agent(),
    'browser_timezone' => trim((string)($body['browser_timezone'] ?? '')),
];

if ($payload['phone'] === '' || $payload['password'] === '') {
    api_response(false, 'VALIDATION_ERROR', 'Phone and password are required.', [], 422);
}

$result = admin_mobile_internal_request('POST', 'auth/admin_login_start.php', $payload);
if (empty($result['ok'])) {
    admin_mobile_emit_internal($result);
}

$json = (array)($result['json'] ?? []);
$data = (array)($json['data'] ?? []);
if (!empty($data['require_otp'])) {
    api_response(true, 'OTP_REQUIRED', (string)($json['message'] ?? 'OTP verification required.'), $data);
}

$finalized = admin_mobile_finalize_session(
    (string)($data['session_token'] ?? ''),
    $deviceId,
    $deviceName
);
if (empty($finalized['ok'])) {
    api_response(false, (string)($finalized['code'] ?? 'LOGIN_FAILED'), (string)($finalized['message'] ?? 'Login failed.'), [], 403);
}

api_response(true, 'ADMIN_MOBILE_LOGIN_OK', 'Admin login successful.', array_merge($data, $finalized));
