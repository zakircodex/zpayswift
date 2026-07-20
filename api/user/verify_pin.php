<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$role = auth_status_value($user['role'] ?? '');

if ($uid === '') {
    api_response(false, 'AUTH_REQUIRED', 'Authentication required.', [], 401);
}

if (!zpay_dash_allowed_mobile_role($role)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
}

$body = api_read_json_body();
$pin = trim((string)($body['pin'] ?? ''));

if ($pin === '') {
    api_response(false, 'VALIDATION_ERROR', 'PIN is required.', [], 422);
}

$pinHash = trim((string)($user['pin_hash'] ?? ''));
if ($pinHash === '' || !password_verify($pin, $pinHash)) {
    api_response(false, 'WRONG_PIN', 'Incorrect PIN. Please try again.', [], 422);
}

api_response(true, 'PIN_VERIFIED', 'PIN verified.', [
    'pin_verified' => true,
]);
