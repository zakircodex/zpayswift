<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function user_registration_precheck(array $body): array
{
    $reply = static fn(bool $ok, string $code, string $message, array $data = [], int $status = 200): array => [
        'ok' => $ok, 'code' => $code, 'message' => $message, 'data' => $data, 'http_status' => $status,
    ];
    $stage = strtoupper(trim((string)($body['stage'] ?? '')));
    if ($stage === 'PHONE') {
        $country = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
        if (!in_array($country, ['BD', 'MY'], true)) {
            return $reply(false, 'VALIDATION_ERROR', 'This phone country is not supported.', ['field' => 'phone_country'], 422);
        }
        $phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $country);
        if ($phone === '') {
            return $reply(false, 'VALIDATION_ERROR', auth_phone_validation_message($country), ['field' => 'phone'], 422);
        }
        if (reg_app_phone_uid($phone, $country) !== '') {
            return $reply(false, 'PHONE_ALREADY_REGISTERED', 'This phone number is already registered.', [], 409);
        }
        return $reply(true, 'REGISTER_PHONE_AVAILABLE', 'Phone number is available.', [
            'phone_country' => $country, 'phone_available' => true,
        ]);
    }
    if ($stage === 'PERSONAL') {
        $name = trim((string)($body['name'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $length > 100) {
            return $reply(false, 'VALIDATION_ERROR', 'Please enter your full name.', ['field' => 'name'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $reply(false, 'VALIDATION_ERROR', 'Please enter a valid email address.', ['field' => 'email'], 422);
        }
        if (reg_app_email_uid($email) !== '') {
            return $reply(false, 'EMAIL_ALREADY_REGISTERED', 'This email is already registered.', [], 409);
        }
        return $reply(true, 'REGISTER_PERSONAL_AVAILABLE', 'Registration details are available.', ['email_available' => true]);
    }
    if ($stage === 'IDENTITY') {
        $type = strtoupper(trim((string)($body['identity_type'] ?? '')));
        $number = auth_app_identity_number($body);
        if (!in_array($type, ['NID', 'PASSPORT'], true)) {
            return $reply(false, 'VALIDATION_ERROR', 'Select a valid identity type.', ['field' => 'identity_type'], 422);
        }
        if (auth_app_identity_hash($number) === '') {
            return $reply(false, 'IDENTITY_REQUIRED', $type === 'PASSPORT'
                ? 'Passport number is required.' : 'NID number is required.', ['field' => 'identity_number'], 422);
        }
        $lookup = user_web_registration_identity_lookup(user_web_registration_identity_hashes($number), $type);
        if (empty($lookup['ok'])) {
            return $reply(false, 'IDENTITY_CHECK_UNAVAILABLE', 'Identity availability could not be checked. Please try again.', [], 503);
        }
        if (!empty($lookup['occupied'])) {
            return $reply(false, $type === 'PASSPORT' ? 'PASSPORT_ALREADY_REGISTERED' : 'NID_ALREADY_REGISTERED',
                $type === 'PASSPORT' ? 'This Passport is already registered.' : 'This NID is already registered.', [], 409);
        }
        return $reply(true, 'REGISTER_IDENTITY_VALID', 'Identity details are valid.', ['identity_type' => $type]);
    }
    if ($stage === 'PASSWORD') {
        $password = (string)($body['password'] ?? '');
        if (!user_web_password_valid($password)) {
            return $reply(false, 'VALIDATION_ERROR', 'Password must be exactly 6 digits.', ['field' => 'password'], 422);
        }
        if ($password !== (string)($body['confirm_password'] ?? '')) {
            return $reply(false, 'VALIDATION_ERROR', 'Password confirmation does not match.', ['field' => 'confirm_password'], 422);
        }
        return $reply(true, 'REGISTER_PASSWORD_VALID', 'Password is valid.');
    }
    if ($stage === 'PIN') {
        $pin = trim((string)($body['pin'] ?? ''));
        if (!user_web_transaction_pin_valid($pin)) {
            return $reply(false, 'VALIDATION_ERROR', 'PIN must be exactly 4 digits.', ['field' => 'pin'], 422);
        }
        if ($pin !== trim((string)($body['confirm_pin'] ?? ''))) {
            return $reply(false, 'VALIDATION_ERROR', 'PIN confirmation does not match.', ['field' => 'confirm_pin'], 422);
        }
        return $reply(true, 'REGISTER_PIN_VALID', 'PIN is valid.');
    }
    return $reply(false, 'VALIDATION_ERROR', 'Invalid registration precheck stage.', [], 422);
}
