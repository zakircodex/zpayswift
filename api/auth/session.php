<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];
$session = $auth['session'];
$role = auth_status_value($user['role'] ?? '');
$deviceId = (string)($session['device_id'] ?? '');
$deviceName = (string)($session['device_name'] ?? '');
$client = strtoupper(trim((string)(api_get_header('X-ZPAY-CLIENT') ?? api_get_header('X-CLIENT') ?? '')));
$mobileSession = str_starts_with($deviceId, 'zpa-')
    || stripos($deviceName, 'Android') !== false
    || in_array($client, ['ANDROID', 'ZPAY_ANDROID', 'MOBILE_APP'], true);

if ($mobileSession && !zpay_dash_allowed_mobile_role($role)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
}

api_response(true, 'SESSION_OK', 'Session valid', [
    'uid' => (string)$user['uid'],
    'name' => (string)$user['name'],
    'phone' => (string)$user['phone'],
    'email' => (string)($user['email'] ?? ''),
    'status' => (string)$user['status'],
    'role' => $role,
    'phone_country' => auth_phone_country_from_user($user),
    'pricing_country' => auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . (string)$user['uid']) ?: [])),
    'device_id' => (string)($session['device_id'] ?? ''),
    'device_trusted' => auth_device_is_trusted((string)$user['uid'], (string)($session['device_id'] ?? '')),
]);
