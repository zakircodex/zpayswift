<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

function email_to_index_key(string $email): string
{
    $email = strtolower(trim($email));

    return str_replace(
        ['.', '#', '$', '[', ']', '/'],
        [',', '_', '_', '(', ')', '_'],
        $email
    );
}

function normalize_admin_role(?string $role): string
{
    return normalize_role($role, 'USER');
}

function normalize_admin_status(?string $status): string
{
    $status = strtoupper(trim((string)$status));
    return in_array($status, ['ACTIVE', 'INACTIVE'], true) ? $status : 'ACTIVE';
}

function admin_bool_or_null(mixed $value): ?bool
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value;
    }

    $v = strtolower(trim((string)$value));

    if ($v === '') {
        return null;
    }

    if (in_array($v, ['1', 'true', 'yes', 'on', 'enabled', 'active'], true)) {
        return true;
    }

    if (in_array($v, ['0', 'false', 'no', 'off', 'disabled', 'inactive'], true)) {
        return false;
    }

    return (bool)$value;
}

function normalize_admin_country(?string $country): string
{
    $country = strtoupper(trim((string)$country));
    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',
        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
    ];

    return $map[$country] ?? '';
}

api_require_method('POST');

$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];

$body = api_read_json_body();

$uid = trim((string)($body['uid'] ?? ''));

$nameProvided = array_key_exists('name', $body);
$emailProvided = array_key_exists('email', $body);
$roleProvided = array_key_exists('role', $body);
$statusProvided = array_key_exists('status', $body);
$countryProvided = array_key_exists('country', $body) || array_key_exists('country_code', $body);

$commissionProvided = array_key_exists('commission_per_1000', $body);
$apiEnabledProvided = array_key_exists('api_enabled', $body);
$topupEnabledProvided = array_key_exists('topup_enabled', $body);
$bundleEnabledProvided = array_key_exists('bundle_enabled', $body);
$minAmountProvided = array_key_exists('min_amount', $body);
$maxAmountProvided = array_key_exists('max_amount', $body);

$name = trim((string)($body['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$country = normalize_admin_country((string)($body['country_code'] ?? $body['country'] ?? ''));

$commissionPer1000 = $commissionProvided ? (float)$body['commission_per_1000'] : null;
$apiEnabled = $apiEnabledProvided ? admin_bool_or_null($body['api_enabled']) : null;
$topupEnabled = $topupEnabledProvided ? admin_bool_or_null($body['topup_enabled']) : null;
$bundleEnabled = $bundleEnabledProvided ? admin_bool_or_null($body['bundle_enabled']) : null;
$minAmount = $minAmountProvided ? (float)$body['min_amount'] : null;
$maxAmount = $maxAmountProvided ? (float)$body['max_amount'] : null;

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

if (
    !$nameProvided &&
    !$emailProvided &&
    !$roleProvided &&
    !$statusProvided &&
    !$countryProvided &&
    !$commissionProvided &&
    !$apiEnabledProvided &&
    !$topupEnabledProvided &&
    !$bundleEnabledProvided &&
    !$minAmountProvided &&
    !$maxAmountProvided
) {
    api_response(false, 'VALIDATION_ERROR', 'No update fields provided', [], 422);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$oldName = trim((string)($user['name'] ?? ''));
$oldEmail = strtolower(trim((string)($user['email'] ?? '')));
$oldRole = strtoupper(trim((string)($user['role'] ?? 'USER')));
$oldStatus = strtoupper(trim((string)($user['status'] ?? 'ACTIVE')));

$role = normalize_admin_role($body['role'] ?? $oldRole);
$status = normalize_admin_status($body['status'] ?? $oldStatus);

$updates = [
    'updated_at' => now_ts(),
];

if ($nameProvided) {
    if ($name === '') {
        api_response(false, 'VALIDATION_ERROR', 'Name is required', ['field' => 'name'], 422);
    }
    $updates['name'] = $name;
}

$emailChanged = false;
$newEmailKey = '';
$oldEmailKey = '';

if ($emailProvided) {
    if (!is_valid_email_or_empty($email)) {
        api_response(false, 'VALIDATION_ERROR', 'Invalid email address', ['field' => 'email'], 422);
    }

    $emailChanged = ($email !== $oldEmail);
    $updates['email'] = $email;

    if ($emailChanged && $email !== '') {
        $newEmailKey = email_to_index_key($email);
        $existingUidByEmail = fb_get('USER_INDEX/EMAIL/' . $newEmailKey);

        if (is_string($existingUidByEmail) && $existingUidByEmail !== '' && $existingUidByEmail !== $uid) {
            api_response(false, 'EMAIL_EXISTS', 'Email already registered', [], 409);
        }
    }

    if ($oldEmail !== '') {
        $oldEmailKey = email_to_index_key($oldEmail);
    }
}

if ($roleProvided) {
    $updates['role'] = $role;
}

if ($statusProvided) {
    $updates['status'] = $status;
}

if ($countryProvided) {
    if ($country === '') {
        api_response(false, 'VALIDATION_ERROR', 'Country must be BD or MY', ['field' => 'country'], 422);
    }

    $updates['country_code'] = $country;
    $updates['country'] = $country;
}

/*
|--------------------------------------------------------------------------
| Reserve new email index first if email changed to non-empty
|--------------------------------------------------------------------------
*/
if ($emailProvided && $emailChanged && $email !== '') {
    if (!fb_put('USER_INDEX/EMAIL/' . $newEmailKey, $uid)) {
        api_response(false, 'SERVER_ERROR', 'Failed to save new email index', [], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Update user profile
|--------------------------------------------------------------------------
*/
if (!fb_patch('USERS/' . $uid, $updates)) {
    if ($emailProvided && $emailChanged && $email !== '') {
        fb_delete('USER_INDEX/EMAIL/' . $newEmailKey);
    }

    api_response(false, 'SERVER_ERROR', 'Failed to update user', [], 500);
}

/*
|--------------------------------------------------------------------------
| Clean old email index if needed
|--------------------------------------------------------------------------
*/
if ($emailProvided && $emailChanged) {
    if ($oldEmail !== '' && $oldEmailKey !== '' && $oldEmailKey !== $newEmailKey) {
        if (!fb_delete('USER_INDEX/EMAIL/' . $oldEmailKey)) {
            system_log('ADMIN_UPDATE_USER_WARNING', $uid, 'Failed to delete old email index', [
                'uid' => $uid,
                'old_email' => $oldEmail,
                'old_email_key' => $oldEmailKey,
            ]);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Update role settings
|--------------------------------------------------------------------------
*/
$currentRoleSettings = fb_get('USER_ROLE_SETTINGS/' . $uid);
if (!is_array($currentRoleSettings)) {
    $currentRoleSettings = role_default_settings($role);
} elseif (function_exists('role_settings_with_defaults')) {
    $currentRoleSettings = role_settings_with_defaults($currentRoleSettings, $role);
}

$roleSettings = normalize_role_settings([
    'commission_per_1000' => $commissionPer1000 ?? ($currentRoleSettings['commission_per_1000'] ?? 0),
    'api_enabled' => $apiEnabled ?? ($currentRoleSettings['api_enabled'] ?? false),
    'topup_enabled' => $topupEnabled ?? ($currentRoleSettings['topup_enabled'] ?? true),
    'bundle_enabled' => $bundleEnabled ?? ($currentRoleSettings['bundle_enabled'] ?? true),
    'min_amount' => $minAmount ?? ($currentRoleSettings['min_amount'] ?? 0),
    'max_amount' => $maxAmount ?? ($currentRoleSettings['max_amount'] ?? 0),
], $role);

if (!fb_put('USER_ROLE_SETTINGS/' . $uid, $roleSettings)) {
    api_response(false, 'SERVER_ERROR', 'Failed to update role settings', [], 500);
}

$finalUser = fb_get('USERS/' . $uid);
if (!is_array($finalUser)) {
    api_response(false, 'SERVER_ERROR', 'User updated but reload failed', [], 500);
}

admin_action_log('UPDATE_USER', $uid, 'Admin updated user account', [
    'uid' => $uid,
    'old_name' => $oldName,
    'new_name' => (string)($finalUser['name'] ?? ''),
    'old_email' => $oldEmail,
    'new_email' => (string)($finalUser['email'] ?? ''),
    'old_role' => $oldRole,
    'new_role' => (string)($finalUser['role'] ?? ''),
    'old_status' => $oldStatus,
    'new_status' => (string)($finalUser['status'] ?? ''),
    'country_code' => (string)($finalUser['country_code'] ?? $finalUser['country'] ?? ''),
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

system_log('ADMIN_UPDATE_USER', $uid, 'Admin updated user account', [
    'uid' => $uid,
    'old_name' => $oldName,
    'new_name' => (string)($finalUser['name'] ?? ''),
    'old_email' => $oldEmail,
    'new_email' => (string)($finalUser['email'] ?? ''),
    'old_role' => $oldRole,
    'new_role' => (string)($finalUser['role'] ?? ''),
    'old_status' => $oldStatus,
    'new_status' => (string)($finalUser['status'] ?? ''),
    'country_code' => (string)($finalUser['country_code'] ?? $finalUser['country'] ?? ''),
    'ip' => client_ip(),
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

api_response(true, 'SUCCESS', 'User account updated successfully', [
    'uid' => (string)($finalUser['uid'] ?? $uid),
    'name' => (string)($finalUser['name'] ?? ''),
    'phone' => (string)($finalUser['phone'] ?? ''),
    'email' => (string)($finalUser['email'] ?? ''),
    'role' => (string)($finalUser['role'] ?? ''),
    'status' => (string)($finalUser['status'] ?? ''),
    'country_code' => (string)($finalUser['country_code'] ?? $finalUser['country'] ?? ''),
    'country' => (string)($finalUser['country_code'] ?? $finalUser['country'] ?? ''),
    'updated_at' => (int)($finalUser['updated_at'] ?? now_ts()),
    'role_settings' => $roleSettings,
    'commission_per_1000' => (float)($roleSettings['commission_per_1000'] ?? 0),
    'api_enabled' => (bool)($roleSettings['api_enabled'] ?? false),
    'topup_enabled' => (bool)($roleSettings['topup_enabled'] ?? true),
    'bundle_enabled' => (bool)($roleSettings['bundle_enabled'] ?? true),
    'min_amount' => (float)($roleSettings['min_amount'] ?? 0),
    'max_amount' => (float)($roleSettings['max_amount'] ?? 0),
]);
