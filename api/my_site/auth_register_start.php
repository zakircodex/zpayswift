<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

function zb_start_bd_local(string $phone): string {
    $d = preg_replace('/\D+/', '', trim($phone)) ?? '';
    if (str_starts_with($d, '880')) { $d = '0' . substr($d, 3); }
    if (strlen($d) === 10 && str_starts_with($d, '1')) { $d = '0' . $d; }
    return $d;
}
function zb_start_valid_bd(string $phone): bool { return preg_match('/^01[3-9]\d{8}$/', zb_start_bd_local($phone)) === 1; }
function zb_start_phone_hash(string $e164): string { return hash('sha256', $e164); }

$body = api_read_json_body();
$name = trim((string)($body['name'] ?? ''));
$phoneLocal = zb_start_bd_local((string)($body['phone'] ?? $body['number'] ?? ''));
$phoneE164 = $phoneLocal !== '' ? '88' . $phoneLocal : '';
$email = zb_owner_email((string)($body['email'] ?? ''));
$dob = trim((string)($body['dob'] ?? $body['date_of_birth'] ?? ''));
$address = trim((string)($body['address'] ?? ''));

if (strlen($name) < 2 || strlen($name) > 80) { api_response(false, 'INVALID_NAME', 'Name must be 2 to 80 characters', [], 422); }
if (!zb_start_valid_bd($phoneLocal)) { api_response(false, 'INVALID_BD_NUMBER', 'Valid Bangladesh number is required', [], 422); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { api_response(false, 'INVALID_EMAIL', 'Valid email is required', [], 422); }
if ($dob === '') { api_response(false, 'DOB_REQUIRED', 'Date of birth is required', [], 422); }
if (strlen($address) < 4 || strlen($address) > 250) { api_response(false, 'INVALID_ADDRESS', 'Address must be 4 to 250 characters', [], 422); }

$phoneHash = zb_start_phone_hash($phoneE164);
$emailHash = zb_email_hash($email);

if (fb_get('Z_BUILDER_OWNER_PHONES/' . $phoneHash) !== null) { api_response(false, 'PHONE_ALREADY_REGISTERED', 'Phone already registered', [], 409); }
if (fb_get('Z_BUILDER_OWNER_EMAILS/' . $emailHash) !== null) { api_response(false, 'EMAIL_ALREADY_REGISTERED', 'Email already registered', [], 409); }

$now = time();
$preToken = 'ZBPRE_' . random_token(24);
$preHash = zb_token_hash($preToken);
$otpId = 'ZBOTP_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$otp = (string)random_int(100000, 999999);

$preAuth = [
    'name' => $name,
    'phone_local' => $phoneLocal,
    'phone_e164' => $phoneE164,
    'phone_hash' => $phoneHash,
    'email' => $email,
    'email_hash' => $emailHash,
    'dob' => $dob,
    'address' => $address,
    'status' => 'OTP_PENDING',
    'otp_id' => $otpId,
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (10 * 60)),
    'ip' => client_ip(),
];

$otpRow = [
    'otp_id' => $otpId,
    'pre_auth_hash' => $preHash,
    'phone_hash' => $phoneHash,
    'code_hash' => password_hash($otp, PASSWORD_DEFAULT),
    'status' => 'SENT',
    'attempts' => 0,
    'max_attempts' => 5,
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (5 * 60)),
];

$ok = fb_put('Z_BUILDER_OWNER_PREAUTH/' . $preHash, $preAuth)
    && fb_put('Z_BUILDER_OWNER_OTPS/' . $otpId, $otpRow);
if (!$ok) { api_response(false, 'REGISTER_START_FAILED', 'Failed to start registration', [], 500); }

$message = 'Z Builder OTP: ' . $otp . '. Valid for 5 minutes.';
$sms = auth_send_bd_sms($phoneE164, $message, $otpId);
fb_patch('Z_BUILDER_OWNER_OTPS/' . $otpId, [
    'sms_ok' => !empty($sms['ok']),
    'sms_gateway' => (string)($sms['gateway'] ?? ''),
    'sms_code' => (string)($sms['code'] ?? ''),
    'sms_message' => substr((string)($sms['message'] ?? ''), 0, 200),
]);

api_response(true, 'OTP_SENT', 'OTP sent', [
    'pre_auth_token' => $preToken,
    'otp_id' => $otpId,
    'phone' => $phoneLocal,
    'expires_in' => 300,
    'sms_ok' => !empty($sms['ok']),
]);
