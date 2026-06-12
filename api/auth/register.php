<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Firebase path key-এ raw email রাখা যায় না।
 * তাই email safe key বানাচ্ছি।
 */
function email_to_index_key(string $email): string
{
    $email = strtolower(trim($email));

    return str_replace(
        ['.', '#', '$', '[', ']', '/'],
        [',', '_', '_', '(', ')', '_'],
        $email
    );
}

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();

$name = trim((string)($body['name'] ?? ''));
$phoneCountry = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
if ($phoneCountry === '') {
    $phoneCountry = detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}
$ipCountry = auth_request_ip_country($body);
$pricingCountry = auth_registration_pricing_country($phoneCountry, $ipCountry);
$currency = auth_country_currency($pricingCountry);
$phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$pin = (string)($body['pin'] ?? '');

if ($name === '') {
    api_response(false, 'VALIDATION_ERROR', 'Name is required', ['field' => 'name'], 422);
}

if ($phone === '') {
    api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), ['field' => 'phone'], 422);
}

if (!is_valid_email_or_empty($email)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid email address', ['field' => 'email'], 422);
}

if (strlen($password) < MIN_PASSWORD_LENGTH) {
    api_response(false, 'VALIDATION_ERROR', 'Password is too short', ['field' => 'password'], 422);
}

if (!is_valid_user_pin($pin)) {
    api_response(false, 'VALIDATION_ERROR', 'PIN must be exactly 4 digits', ['field' => 'pin'], 422);
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
| Check email already exists (safe Firebase key)
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
    'service_country' => $pricingCountry,
    'country_code' => $pricingCountry,
    'country' => $pricingCountry,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'ip_country' => $ipCountry,
    'country_mismatch' => $ipCountry !== '' && $ipCountry !== $phoneCountry,
    'registration_ip' => auth_request_ip($body),
    'created_ip' => auth_request_ip($body),
    'last_login_ip' => '',
    'browser_timezone' => auth_request_browser_timezone($body),
    'user_agent' => auth_request_user_agent($body),
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
    'status' => 'ACTIVE',
    'role' => 'USER',
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => 0,
];

$wallet = [
    'available_balance' => 0,
    'hold_balance' => 0,
    'currency' => $currency,
    'wallet_currency' => $currency,
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
$savedPhoneIndexes = [];
foreach ($phoneIndexes as $phoneIndex) {
    if (!fb_put('USER_INDEX/PHONE/' . $phoneIndex, $uid)) {
        foreach ($savedPhoneIndexes as $savedPhoneIndex) {
            fb_delete('USER_INDEX/PHONE/' . $savedPhoneIndex);
        }
        fb_delete('USERS/' . $uid);
        api_response(false, 'SERVER_ERROR', 'Failed to save phone index', [], 500);
    }
    $savedPhoneIndexes[] = $phoneIndex;
}
if (!$phoneIndexes) {
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


$roleSettings = role_default_settings('USER');

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


/*
|--------------------------------------------------------------------------
| System log
|--------------------------------------------------------------------------
*/
system_log('REGISTER', $uid, 'User registered successfully', [
    'uid' => $uid,
    'phone' => $phone,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'email' => $email,
    'ip' => client_ip(),
]);

api_response(true, 'SUCCESS', 'Registration successful', [
    'uid' => $uid,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'phone_country' => $phoneCountry,
    'pricing_country' => $pricingCountry,
    'currency' => $currency,
]);
