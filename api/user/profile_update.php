<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';

function profile_update_email_index_keys(string $email): array
{
    if (function_exists('auth_email_index_keys')) {
        return auth_email_index_keys($email);
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $legacyKey = md5($email);
    $safeKey = str_replace(
        ['.', '#', '$', '[', ']', '/'],
        [',', '_', '_', '(', ')', '_'],
        $email
    );

    return array_values(array_unique(array_filter([$legacyKey, $safeKey])));
}

function profile_update_uid_from_index($row): string
{
    if (is_string($row)) {
        return trim($row);
    }
    if (is_array($row)) {
        return trim((string)($row['uid'] ?? $row['value'] ?? ''));
    }
    return '';
}

function profile_update_user_payload(string $uid, array $user, array $wallet): array
{
    $pricingCountry = auth_pricing_country_from_user($user, $wallet);
    $walletCurrency = function_exists('wallet_account_currency')
        ? wallet_account_currency($user, $wallet)
        : strtoupper((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? ($pricingCountry === 'MY' ? 'MYR' : 'BDT')));

    return [
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
        'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
        'role' => auth_status_value($user['role'] ?? ''),
        'phone_country' => auth_phone_country_from_user($user),
        'pricing_country' => $pricingCountry,
        'wallet_currency' => $walletCurrency !== '' ? $walletCurrency : 'BDT',
        'created_at' => (int)($user['created_at'] ?? 0),
        'last_login_at' => (int)($user['last_login_at'] ?? 0),
        'profile_photo_url' => (string)($user['profile_photo_url'] ?? $user['profile_photo'] ?? $user['photo_url'] ?? ''),
    ];
}

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

foreach ([
    'uid',
    'user_id',
    'role',
    'status',
    'account_status',
    'phone',
    'phone_country',
    'pricing_country',
    'market_country',
    'service_country',
    'currency',
    'wallet_currency',
] as $forbiddenField) {
    if (array_key_exists($forbiddenField, $body)) {
        api_response(false, 'FIELD_NOT_ALLOWED', 'This profile field cannot be changed from the app.', [], 422);
    }
}

$nameProvided = array_key_exists('name', $body);
$emailProvided = array_key_exists('email', $body);

if (!$nameProvided && !$emailProvided) {
    api_response(false, 'VALIDATION_ERROR', 'No profile changes were provided.', [], 422);
}

$updates = [];
$changedFields = [];
$now = now_ts();

if ($nameProvided) {
    $name = trim((string)($body['name'] ?? ''));
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($name === '' || $nameLength < 2 || $nameLength > 80) {
        api_response(false, 'INVALID_NAME', 'Enter a valid full name.', [], 422);
    }
    if ($name !== (string)($user['name'] ?? '')) {
        $updates['name'] = $name;
        $changedFields[] = 'name';
    }
}

$oldEmail = strtolower(trim((string)($user['email'] ?? '')));
$newEmail = $oldEmail;
$emailChanged = false;
$newEmailKeys = [];
$oldEmailKeys = [];

if ($emailProvided) {
    $newEmail = strtolower(trim((string)($body['email'] ?? '')));
    if ($newEmail === '' || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false || strlen($newEmail) > 120) {
        api_response(false, 'INVALID_EMAIL', 'Enter a valid email address.', [], 422);
    }

    $emailChanged = $newEmail !== $oldEmail;
    if ($emailChanged) {
        $newEmailKeys = profile_update_email_index_keys($newEmail);
        foreach ($newEmailKeys as $emailKey) {
            $existingUid = profile_update_uid_from_index(fb_get('USER_INDEX/EMAIL/' . $emailKey));
            if ($existingUid !== '' && $existingUid !== $uid) {
                api_response(false, 'EMAIL_ALREADY_USED', 'This email is already in use.', [], 409);
            }
        }

        $updates['email'] = $newEmail;
        $changedFields[] = 'email';
        $oldEmailKeys = profile_update_email_index_keys($oldEmail);
    }
}

if ($updates === []) {
    $wallet = fb_get('USER_WALLETS/' . $uid);
    api_response(true, 'PROFILE_UNCHANGED', 'Profile already up to date.', profile_update_user_payload(
        $uid,
        $user,
        is_array($wallet) ? $wallet : []
    ));
}

if ($emailChanged) {
    $claimedEmailIndexPaths = [];
    foreach ($newEmailKeys as $emailKey) {
        $path = 'USER_INDEX/EMAIL/' . $emailKey;
        $claim = auth_index_claim($path, $uid, $uid);
        if (empty($claim['ok'])) {
            foreach (array_reverse($claimedEmailIndexPaths) as $claimedPath) {
                @auth_index_release($claimedPath, $uid);
            }
            api_response(
                false,
                !empty($claim['conflict']) ? 'EMAIL_ALREADY_USED' : 'PROFILE_UPDATE_FAILED',
                !empty($claim['conflict']) ? 'This email is already in use.' : 'Unable to update profile. Please try again.',
                [],
                !empty($claim['conflict']) ? 409 : 500
            );
        }
        if (!empty($claim['claimed'])) {
            $claimedEmailIndexPaths[] = $path;
        }
    }
}

$updates['updated_at'] = $now;

if (!fb_patch('USERS/' . $uid, $updates)) {
    if ($emailChanged) {
        foreach (array_reverse($claimedEmailIndexPaths ?? []) as $path) {
            @auth_index_release($path, $uid);
        }
    }
    api_response(false, 'PROFILE_UPDATE_FAILED', 'Unable to update profile. Please try again.', [], 500);
}

if ($emailChanged) {
    foreach ($oldEmailKeys as $emailKey) {
        if (!in_array($emailKey, $newEmailKeys, true)) {
            @auth_index_release('USER_INDEX/EMAIL/' . $emailKey, $uid);
        }
    }
}

$freshUser = fb_get('USERS/' . $uid);
$freshUser = is_array($freshUser) ? $freshUser : array_merge($user, $updates);
$wallet = fb_get('USER_WALLETS/' . $uid);
$wallet = is_array($wallet) ? $wallet : [];

system_log('USER_PROFILE_UPDATE', $uid, 'User profile updated', [
    'uid' => $uid,
    'changed_fields' => $changedFields,
]);

api_response(true, 'PROFILE_UPDATED', 'Profile updated successfully.', profile_update_user_payload($uid, $freshUser, $wallet));
