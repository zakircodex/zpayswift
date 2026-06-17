<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

function zb_login_bd_local(string $phone): string {
    $d = preg_replace('/\D+/', '', trim($phone)) ?? '';
    if (str_starts_with($d, '880')) { $d = '0' . substr($d, 3); }
    if (strlen($d) === 10 && str_starts_with($d, '1')) { $d = '0' . $d; }
    return $d;
}
function zb_login_valid_bd(string $phone): bool { return preg_match('/^01[3-9]\d{8}$/', zb_login_bd_local($phone)) === 1; }

$body = api_read_json_body();
$phoneLocal = zb_login_bd_local((string)($body['phone'] ?? $body['number'] ?? ''));
$phoneE164 = $phoneLocal !== '' ? '88' . $phoneLocal : '';

if (!zb_login_valid_bd($phoneLocal)) {
    api_response(false, 'INVALID_BD_NUMBER', 'Valid Bangladesh number is required', [], 422);
}

$phoneHash = hash('sha256', $phoneE164);
$mapped = fb_get('Z_BUILDER_OWNER_PHONES/' . $phoneHash);
$ownerId = is_array($mapped) ? (string)($mapped['owner_id'] ?? '') : '';
$owner = $ownerId !== '' ? fb_get('Z_BUILDER_OWNERS/' . $ownerId) : null;

if (!is_array($owner)) { api_response(false, 'OWNER_NOT_FOUND', 'Account not found', [], 404); }
if (($owner['status'] ?? '') === 'BLOCKED') { api_response(false, 'OWNER_BLOCKED', 'Account blocked', [], 403); }

$now = time();
$loginToken = 'ZBLOGIN_' . random_token(24);
$loginHash = zb_token_hash($loginToken);
$otpId = 'ZBLOGINOTP_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$otp = (string)random_int(100000, 999999);

$loginRow = [
    'owner_id' => $ownerId,
    'phone_e164' => $phoneE164,
    'phone_hash' => $phoneHash,
    'status' => 'OTP_PENDING',
    'otp_id' => $otpId,
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (10 * 60)),
    'ip' => client_ip(),
];
$otpRow = [
    'owner_id' => $ownerId,
    'login_hash' => $loginHash,
    'phone_hash' => $phoneHash,
    'code_hash' => password_hash($otp, PASSWORD_DEFAULT),
    'status' => 'SENT',
    'attempts' => 0,
    'max_attempts' => 5,
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (5 * 60)),
];

fb_put('Z_BUILDER_OWNER_LOGIN/' . $loginHash, $loginRow);
fb_put('Z_BUILDER_OWNER_OTPS/' . $otpId, $otpRow);
$message = 'Z Builder login OTP: ' . $otp . '. Valid for 5 minutes.';
$sms = auth_send_bd_sms($phoneE164, $message, $otpId);
fb_patch('Z_BUILDER_OWNER_OTPS/' . $otpId, ['sms_ok' => !empty($sms['ok']), 'sms_gateway' => (string)($sms['gateway'] ?? ''), 'sms_code' => (string)($sms['code'] ?? '')]);

api_response(true, 'OTP_SENT', 'OTP sent', [
    'login_token' => $loginToken,
    'otp_id' => $otpId,
    'phone' => $phoneLocal,
    'expires_in' => 300,
    'sms_ok' => !empty($sms['ok']),
]);
