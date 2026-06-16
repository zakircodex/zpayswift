<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/auth_sms.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$body = api_read_json_body();
$preToken = trim((string)($body['pre_auth_token'] ?? ''));
if ($preToken === '') { api_response(false, 'PREAUTH_REQUIRED', 'Registration session required', [], 422); }

$preHash = zb_token_hash($preToken);
$prePath = 'Z_BUILDER_OWNER_PREAUTH/' . $preHash;
$pre = fb_get($prePath);
if (!is_array($pre) || ($pre['status'] ?? '') !== 'OTP_PENDING') {
    api_response(false, 'INVALID_PREAUTH', 'Invalid registration session', [], 400);
}

if (strtotime((string)($pre['expires_at'] ?? '')) < time()) {
    fb_patch($prePath, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
    api_response(false, 'PREAUTH_EXPIRED', 'Registration session expired', [], 400);
}

$oldOtpId = (string)($pre['otp_id'] ?? '');
if ($oldOtpId !== '') {
    $old = fb_get('Z_BUILDER_OWNER_OTPS/' . $oldOtpId);
    $created = strtotime((string)($old['created_at'] ?? ''));
    if ($created !== false && time() - $created < 60) {
        api_response(false, 'RESEND_TOO_SOON', 'Please wait before requesting another OTP', ['wait_seconds' => 60 - (time() - $created)], 429);
    }
}

$now = time();
$otpId = 'ZBOTP_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$otp = (string)random_int(100000, 999999);
$row = [
    'otp_id' => $otpId,
    'pre_auth_hash' => $preHash,
    'phone_hash' => (string)$pre['phone_hash'],
    'code_hash' => password_hash($otp, PASSWORD_DEFAULT),
    'status' => 'SENT',
    'attempts' => 0,
    'max_attempts' => 5,
    'created_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($now + (5 * 60)),
];

fb_put('Z_BUILDER_OWNER_OTPS/' . $otpId, $row);
fb_patch($prePath, ['otp_id' => $otpId, 'updated_at' => zb_now_iso($now)]);
if ($oldOtpId !== '') { fb_patch('Z_BUILDER_OWNER_OTPS/' . $oldOtpId, ['status' => 'REPLACED', 'updated_at' => zb_now_iso($now)]); }

$message = 'Z Builder OTP: ' . $otp . '. Valid for 5 minutes.';
$sms = auth_send_bd_sms((string)$pre['phone_e164'], $message, $otpId);
fb_patch('Z_BUILDER_OWNER_OTPS/' . $otpId, [
    'sms_ok' => !empty($sms['ok']),
    'sms_gateway' => (string)($sms['gateway'] ?? ''),
    'sms_code' => (string)($sms['code'] ?? ''),
]);

api_response(true, 'OTP_SENT', 'OTP resent', [
    'otp_id' => $otpId,
    'expires_in' => 300,
    'sms_ok' => !empty($sms['ok']),
]);
