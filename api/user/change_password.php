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
$currentPassword = (string)($body['current_password'] ?? '');
$newPassword = (string)($body['new_password'] ?? '');
$confirmPassword = (string)($body['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    api_response(false, 'VALIDATION_ERROR', 'Current password and new password are required.', [], 422);
}

$passwordHash = trim((string)($user['password_hash'] ?? ''));
if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) {
    api_response(false, 'WRONG_PASSWORD', 'Current password is incorrect.', [], 422);
}

if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
    api_response(false, 'INVALID_PASSWORD', 'Password is too short.', [], 422);
}

if ($newPassword !== $confirmPassword) {
    api_response(false, 'PASSWORD_MISMATCH', 'Confirm password does not match.', [], 422);
}

if (password_verify($newPassword, $passwordHash)) {
    api_response(false, 'PASSWORD_UNCHANGED', 'Choose a different new password.', [], 422);
}

$updates = [
    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    'updated_at' => now_ts(),
    'password_changed_at' => now_ts(),
];

if (!fb_patch('USERS/' . $uid, $updates)) {
    api_response(false, 'PASSWORD_UPDATE_FAILED', 'Unable to update password. Please try again.', [], 500);
}

system_log('USER_PASSWORD_CHANGED', $uid, 'User password changed from profile security.', [
    'uid' => $uid,
]);

api_response(true, 'PASSWORD_UPDATED', 'Password updated successfully.', [
    'password_updated' => true,
]);
