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
$currentPin = trim((string)($body['current_pin'] ?? ''));
$newPin = trim((string)($body['new_pin'] ?? ''));
$confirmPin = trim((string)($body['confirm_pin'] ?? ''));

if ($currentPin === '' || $newPin === '' || $confirmPin === '') {
    api_response(false, 'VALIDATION_ERROR', 'Current PIN and new PIN are required.', [], 422);
}

$pinHash = trim((string)($user['pin_hash'] ?? ''));
if ($pinHash === '' || !password_verify($currentPin, $pinHash)) {
    api_response(false, 'WRONG_PIN', 'Current PIN is incorrect.', [], 422);
}

if (!is_valid_user_pin($newPin)) {
    api_response(false, 'INVALID_PIN', 'PIN must be exactly ' . USER_PIN_LENGTH . ' digits.', [], 422);
}

if ($newPin !== $confirmPin) {
    api_response(false, 'PIN_MISMATCH', 'Confirm PIN does not match.', [], 422);
}

if (password_verify($newPin, $pinHash)) {
    api_response(false, 'PIN_UNCHANGED', 'Choose a different new PIN.', [], 422);
}

$updates = [
    'pin_hash' => password_hash($newPin, PASSWORD_DEFAULT),
    'updated_at' => now_ts(),
    'pin_changed_at' => now_ts(),
];

if (!fb_patch('USERS/' . $uid, $updates)) {
    api_response(false, 'PIN_UPDATE_FAILED', 'Unable to update PIN. Please try again.', [], 500);
}

system_log('USER_PIN_CHANGED', $uid, 'User PIN changed from profile security.', [
    'uid' => $uid,
]);

api_response(true, 'PIN_UPDATED', 'PIN updated successfully.', [
    'pin_updated' => true,
]);
