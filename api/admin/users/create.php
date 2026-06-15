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

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];

$body = api_read_json_body();

$commissionProvided = array_key_exists('commission_per_1000', $body);
$apiEnabled = (bool)($body['api_enabled'] ?? false);
$topupEnabled = (bool)($body['topup_enabled'] ?? true);
$bundleEnabled = (bool)($body['bundle_enabled'] ?? true);
$minAmount = (float)($body['min_amount'] ?? 0);
$maxAmount = (float)($body['max_amount'] ?? 0);

$name = trim((string)($body['name'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
$pricingCountry = auth_normalize_country_code((string)(
    $body['pricing_country']
    ?? $body['market_country']
    ?? $body['service_country']
    ?? $body['country']
    ?? $body['country_code']
    ?? ''
));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}
if ($pricingCountry === '') {
    $pricingCountry = auth_normalize_country_code(
        defined('DEFAULT_USER_COUNTRY') ? (string)DEFAULT_USER_COUNTRY : 'BD'
    ) ?: 'BD';
}
$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$currency = auth_country_currency($pricingCountry);
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$pin = (string)($body['pin'] ?? '');
$role = normalize_admin_role($body['role'] ?? 'USER');
$status = normalize_admin_status($body['status'] ?? 'ACTIVE');
$commissionPer1000 = $commissionProvided
    ? (float)$body['commission_per_1000']
    : role_default_commission_per_1000($role);

if ($name === '') {
    api_response(false, 'VALIDATION_ERROR', 'Name is required', ['field' => 'name'], 422);
}

if ($phone === '' || strlen($phone) < 8) {
    api_response(false, 'VALIDATION_ERROR', 'Valid phone is required', ['field' => 'phone'], 422);
}

if (!is_valid_email_or_empty($email)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid email address', ['field' => 'email'], 422);
}

if (strlen($password) < MIN_PASSWORD_LENGTH) {
    api_response(false, 'VALIDATION_ERROR', 'Password is too short', ['field' => 'password'], 422);
}

if (!is_valid_user_pin($pin)) {
    api_response(false, 'VALIDATION_ERROR', 'PIN must be exactly ' . USER_PIN_LENGTH . ' digits', ['field' => 'pin'], 422);
}

/*
|--------------------------------------------------------------------------
| Check phone already exists
|--------------------------------------------------------------------------
*/
$existingUidByPhone = auth_find_uid_by_phone_country($phone, $phoneCountry);
if ($existingUidByPhone !== '') {
    api_response(false, 'PHONE_EXISTS', 'Phone already registered', [], 409);
}

/*
|--------------------------------------------------------------------------
| Check email already exists
|--------------------------------------------------------------------------
*/
$emailKey = '';
if ($email !== '') {
    $emailKey = email_to_index_key($email);
    $existingUidByEmail = fb_get('USER_INDEX/EMAIL/' . $emailKey);

    if (is_string($existingUidByEmail) && $existingUidByEmail !== '') {
        api_response(false, 'EMAIL_EXISTS', 'Email already registered', [], 409);
    }
}

$uid = make_uid();
$now = now_ts();

$user = [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'country_code' => $pricingCountry,
    'country' => $pricingCountry,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'country_mismatch' => $pricingCountry !== $phoneCountry,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
    'status' => $status,
    'role' => $role,
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => 0,
    'created_by_admin' => true,
    'created_by_admin_uid' => (string)($adminUser['uid'] ?? ''),
];

$wallet = [
    'available_balance' => 0,
    'hold_balance' => 0,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'service_country' => $pricingCountry,
    'total_topup_spent' => 0,
    'total_bundle_spent' => 0,
    'total_refund' => 0,
    'updated_at' => $now,
];

/*
|--------------------------------------------------------------------------
| Save user
|--------------------------------------------------------------------------
*/
if (!fb_put('USERS/' . $uid, $user)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save user', [], 500);
}

/*
|--------------------------------------------------------------------------
| Save phone index
|--------------------------------------------------------------------------
*/
$phoneIndexes = auth_phone_index_candidates($phone, $phoneCountry);
$phoneIndexOk = true;
$savedPhoneIndexes = [];
foreach ($phoneIndexes as $phoneIndex) {
    if (!fb_put('USER_INDEX/PHONE/' . $phoneIndex, $uid)) {
        $phoneIndexOk = false;
        break;
    }
    $savedPhoneIndexes[] = $phoneIndex;
}
if (!$phoneIndexOk) {
    foreach ($savedPhoneIndexes as $savedPhoneIndex) {
        fb_delete('USER_INDEX/PHONE/' . $savedPhoneIndex);
    }
    fb_delete('USERS/' . $uid);
    api_response(false, 'SERVER_ERROR', 'Failed to save phone index', [], 500);
}

/*
|--------------------------------------------------------------------------
| Save email index
|--------------------------------------------------------------------------
*/
if ($email !== '' && !fb_put('USER_INDEX/EMAIL/' . $emailKey, $uid)) {
    foreach ($phoneIndexes as $phoneIndex) {
        fb_delete('USER_INDEX/PHONE/' . $phoneIndex);
    }
    fb_delete('USERS/' . $uid);
    api_response(false, 'SERVER_ERROR', 'Failed to save email index', [], 500);
}

/*
|--------------------------------------------------------------------------
| Create wallet
|--------------------------------------------------------------------------
*/
if (!fb_put('USER_WALLETS/' . $uid, $wallet)) {
    if ($email !== '') {
        fb_delete('USER_INDEX/EMAIL/' . $emailKey);
    }

    foreach ($phoneIndexes as $phoneIndex) {
        fb_delete('USER_INDEX/PHONE/' . $phoneIndex);
    }
    fb_delete('USERS/' . $uid);

    api_response(false, 'SERVER_ERROR', 'Failed to create wallet', [], 500);
}


$roleSettings = normalize_role_settings([
    'commission_per_1000' => $commissionPer1000,
    'api_enabled' => $apiEnabled,
    'topup_enabled' => $topupEnabled,
    'bundle_enabled' => $bundleEnabled,
    'min_amount' => $minAmount,
    'max_amount' => $maxAmount,
], $role);

if (!fb_put('USER_ROLE_SETTINGS/' . $uid, $roleSettings)) {
    if ($email !== '') {
        fb_delete('USER_INDEX/EMAIL/' . $emailKey);
    }

    fb_delete('USER_WALLETS/' . $uid);
    foreach ($phoneIndexes as $phoneIndex) {
        fb_delete('USER_INDEX/PHONE/' . $phoneIndex);
    }
    fb_delete('USERS/' . $uid);

    api_response(false, 'SERVER_ERROR', 'Failed to create role settings', [], 500);
}


admin_action_log('CREATE_USER', $uid, 'Admin created user account', [
    'uid' => $uid,
    'phone' => $phone,
    'email' => $email,
    'role' => $role,
    'status' => $status,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

system_log('ADMIN_CREATE_USER', $uid, 'Admin created user account', [
    'uid' => $uid,
    'phone' => $phone,
    'email' => $email,
    'role' => $role,
    'status' => $status,
    'ip' => client_ip(),
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

api_response(true, 'SUCCESS', 'User account created successfully', [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'role' => $role,
    'status' => $status,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'market_country' => $pricingCountry,
    'currency' => $currency,
]);
