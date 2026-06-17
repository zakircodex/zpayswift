<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$loginToken = trim((string)($body['login_token'] ?? ''));
if ($loginToken === '') { api_response(false, 'LOGIN_REQUIRED', 'Login session required', [], 422); }

$loginHash = zb_token_hash($loginToken);
$loginPath = 'Z_BUILDER_OWNER_LOGIN/' . $loginHash;
$login = fb_get($loginPath);
if (!is_array($login) || ($login['status'] ?? '') !== 'OTP_PENDING') {
    api_response(false, 'INVALID_LOGIN', 'Invalid login session', [], 400);
}
if (strtotime((string)($login['expires_at'] ?? '')) < time()) {
    fb_patch($loginPath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'LOGIN_EXPIRED', 'Login session expired', [], 400);
}

$oldOtpId = (string)($login['otp_id'] ?? '');
if ($oldOtpId !== '') {
    $old = fb_get('Z_BUILDER_OWNER_OTPS/' . $oldOtpId);
    $created = strtotime((string)($old['created_at'] ?? ''));
    if ($created !== false && time() - $created < 60) {
        api_response(false, 'RESEND_TOO_SOON', 'Please wait before requesting another OTP', ['wait_seconds' => 60 - (time() - $created)], 429);
    }
}

$now = time();
$ownerId = (string)($login['owner_id'] ?? '');
$phoneE164 = (string)($login['phone_e164'] ?? '');
$phoneHash = (string)($login['phone_hash'] ?? '');
$otpId = 'ZBLOGINOTP_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$otp = (string)random_int(100000, 999999);

$row = [
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

fb_put('Z_BUILDER_OWNER_OTPS/' . $otpId, $row);
fb_patch($loginPath, ['otp_id' => $otpId, 'updated_at' => zb_now_iso($now)]);
if ($oldOtpId !== '') { fb_patch('Z_BUILDER_OWNER_OTPS/' . $oldOtpId, ['status' => 'REPLACED', 'updated_at' => zb_now_iso($now)]); }

$message = 'Z Builder login OTP: ' . $otp . '. Valid for 5 minutes.';
$sms = auth_send_bd_sms($phoneE164, $message, $otpId);
fb_patch('Z_BUILDER_OWNER_OTPS/' . $otpId, ['sms_ok' => !empty($sms['ok']), 'sms_gateway' => (string)($sms['gateway'] ?? ''), 'sms_code' => (string)($sms['code'] ?? '')]);

api_response(true, 'OTP_SENT', 'OTP resent', [
    'otp_id' => $otpId,
    'expires_in' => 300,
    'sms_ok' => !empty($sms['ok']),
]);
