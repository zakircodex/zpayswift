<?php
declare(strict_types=1);

$assertions = 0;
$phoneOccupied = false;
$emailOccupied = false;
$identityOccupied = false;
$identityUnavailable = false;
$checkedEmail = '';
function expect_precheck(bool $value, string $message): void {
    global $assertions;
    $assertions++;
    if (!$value) { throw new RuntimeException($message); }
}
function auth_normalize_country_code(string $country): string { return strtoupper(trim($country)); }
function normalize_phone_by_country(string $phone, string $country): string {
    return $phone === 'valid-phone' ? ($country === 'BD' ? '8801300000000' : '60100000000') : '';
}
function auth_phone_validation_message(string $country): string { return 'Invalid ' . $country . ' phone'; }
function reg_app_phone_uid(string $phone, string $country): string { return $GLOBALS['phoneOccupied'] ? 'U1' : ''; }
function reg_app_email_uid(string $email): string {
    $GLOBALS['checkedEmail'] = $email;
    return $GLOBALS['emailOccupied'] ? 'U1' : '';
}
function auth_app_identity_number(array $body): string { return trim((string)($body['identity_number'] ?? '')); }
function auth_app_identity_hash(string $number): string { return $number === '' ? '' : hash('sha256', $number); }
function user_web_registration_identity_hashes(string $number): array { return [hash('sha256', $number)]; }
function user_web_registration_identity_lookup(array $hashes, string $type): array {
    return ['ok' => !$GLOBALS['identityUnavailable'], 'occupied' => $GLOBALS['identityOccupied']];
}
function fb_put(string $path, mixed $value): bool { throw new RuntimeException('Prechecks must not create an account.'); }
require dirname(__DIR__) . '/api/lib/user_web_credentials.php';
require dirname(__DIR__) . '/api/lib/user_registration_precheck.php';

foreach (['BD', 'MY'] as $country) {
    $body = ['stage' => 'PHONE', 'phone_country' => $country, 'phone' => 'valid-phone'];
    $result = user_registration_precheck($body);
    expect_precheck($result['ok'] && $result['data']['phone_country'] === $country, 'Supported phone must pass.');
    $phoneOccupied = true;
    $result = user_registration_precheck($body);
    expect_precheck(!$result['ok'] && $result['code'] === 'PHONE_ALREADY_REGISTERED' && $result['http_status'] === 409, 'Duplicate phone must fail.');
    $phoneOccupied = false;
}
expect_precheck(!user_registration_precheck(['stage' => 'PHONE', 'phone_country' => 'US'])['ok'], 'Unsupported phone country must fail.');
expect_precheck(!user_registration_precheck(['stage' => 'PHONE', 'phone_country' => 'MY', 'phone' => 'invalid'])['ok'], 'Invalid phone must fail.');
$personal = ['stage' => 'PERSONAL', 'name' => 'Test User', 'email' => 'TEST@example.test'];
expect_precheck(user_registration_precheck($personal)['ok'] && $checkedEmail === 'test@example.test', 'Personal data must use a normalized email.');
$emailOccupied = true;
expect_precheck(user_registration_precheck($personal)['code'] === 'EMAIL_ALREADY_REGISTERED', 'Duplicate email must fail.');
$emailOccupied = false;
foreach (['', str_repeat('N', 101)] as $name) {
    expect_precheck(!user_registration_precheck(array_replace($personal, ['name' => $name]))['ok'], 'Missing or overlong name must fail.');
}
expect_precheck(!user_registration_precheck(array_replace($personal, ['email' => 'invalid']))['ok'], 'Invalid email must fail.');
foreach (['NID', 'PASSPORT'] as $type) {
    $body = ['stage' => 'IDENTITY', 'identity_type' => $type, 'identity_number' => 'TEST123'];
    expect_precheck(user_registration_precheck($body)['ok'], 'Available identity must pass.');
    $identityOccupied = true;
    expect_precheck(user_registration_precheck($body)['code'] === $type . '_ALREADY_REGISTERED', 'Duplicate identity must fail.');
    $identityOccupied = false;
    $identityUnavailable = true;
    expect_precheck(user_registration_precheck($body)['http_status'] === 503, 'Unavailable identity checks must fail closed.');
    $identityUnavailable = false;
}
expect_precheck(!user_registration_precheck(['stage' => 'IDENTITY', 'identity_type' => 'NID'])['ok'], 'Missing identity must fail.');
foreach (['12345', '1234567', 'abc123', "123456\n"] as $password) {
    expect_precheck(!user_registration_precheck(['stage' => 'PASSWORD', 'password' => $password, 'confirm_password' => $password])['ok'], 'Invalid password must fail.');
}
expect_precheck(user_registration_precheck(['stage' => 'PASSWORD', 'password' => '123456', 'confirm_password' => '123456'])['ok'], 'Canonical password must pass.');
expect_precheck(!user_registration_precheck(['stage' => 'PASSWORD', 'password' => '123456', 'confirm_password' => '654321'])['ok'], 'Password mismatch must fail.');
foreach (['123', '12345', 'abcd'] as $pin) {
    expect_precheck(!user_registration_precheck(['stage' => 'PIN', 'pin' => $pin, 'confirm_pin' => $pin])['ok'], 'Invalid PIN must fail.');
}
expect_precheck(user_registration_precheck(['stage' => 'PIN', 'pin' => '1234', 'confirm_pin' => '1234'])['ok'], 'Canonical PIN must pass.');
expect_precheck(!user_registration_precheck(['stage' => 'PIN', 'pin' => '1234', 'confirm_pin' => '4321'])['ok'], 'PIN mismatch must fail.');
expect_precheck(!user_registration_precheck(['stage' => 'UNKNOWN'])['ok'], 'Unknown stage must fail.');
echo "Shared registration precheck tests passed ({$assertions} assertions).\n";
