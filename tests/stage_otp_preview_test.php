<?php
declare(strict_types=1);

function stage_otp_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

define('APP_ENVIRONMENT', 'stage');
define('APP_PUBLIC_ORIGIN', 'https://stage.zpayswift.com');
define('APP_KEY', str_repeat('a', 64));
define('STAGE_AUTH_OTP_PREVIEW_ENABLED', true);
define('STAGE_AUTH_OTP_PREVIEW_PHONES', ['60123456789']);
define('STAGE_AUTH_OTP_PREVIEW_PURPOSES', ['USER_LOGIN']);
$_SERVER['HTTP_HOST'] = 'stage.zpayswift.com';

require_once dirname(__DIR__) . '/api/lib/auth_sms.php';

$referenceId = 'OTPABC123456789';
$fallback = '987654';
$prepared = auth_sms_prepare_otp_code('MY', '60123456789', 'USER_LOGIN', $referenceId, $fallback);
stage_otp_expect(preg_match('/^\d{6}$/D', $prepared) === 1, 'preview OTP must contain six digits');
stage_otp_expect($prepared !== $fallback, 'preview OTP must be derived independently of the random fallback');
stage_otp_expect(
    $prepared === auth_sms_prepare_otp_code('MY', '60123456789', 'USER_LOGIN', $referenceId, '123456'),
    'preview OTP must be retry-safe for the same request'
);

$result = auth_sms_stage_preview_result('MY', '60123456789', $referenceId, 'USER_LOGIN', $prepared);
stage_otp_expect(is_array($result) && !empty($result['ok']), 'allowlisted stage login OTP was not captured');
stage_otp_expect(($result['gateway'] ?? '') === 'STAGE_PREVIEW', 'stage preview must not identify a live SMS gateway');
$dispatched = auth_send_otp_sms_by_country(
    'MY',
    '60123456789',
    'unused in stage preview',
    $referenceId,
    'USER_LOGIN',
    $prepared
);
stage_otp_expect(!empty($dispatched['ok']), 'stage OTP dispatch did not use the preview path');
stage_otp_expect(($dispatched['code'] ?? '') === 'STAGE_PREVIEW_READY', 'stage OTP dispatch reached a live provider');

$fields = auth_sms_stage_preview_response_fields('MY', '60123456789', 'USER_LOGIN', $referenceId);
stage_otp_expect(($fields['stage_otp_preview'] ?? false) === true, 'stage preview marker is missing');
stage_otp_expect(($fields['stage_otp'] ?? '') === $prepared, 'stage preview response did not return the request OTP');

$blockedPhone = auth_sms_stage_preview_result('MY', '60111111111', $referenceId, 'USER_LOGIN', '123456');
stage_otp_expect(is_array($blockedPhone) && empty($blockedPhone['ok']), 'a non-allowlisted phone reached stage preview');
stage_otp_expect(($blockedPhone['code'] ?? '') === 'STAGE_PREVIEW_NOT_ALLOWED', 'blocked phone failure code changed');
stage_otp_expect(
    auth_sms_stage_preview_response_fields('MY', '60111111111', 'USER_LOGIN', $referenceId) === [],
    'a non-allowlisted phone received an OTP preview'
);

$blockedPurpose = auth_sms_stage_preview_result('MY', '60123456789', $referenceId, 'USER_REGISTER', '123456');
stage_otp_expect(is_array($blockedPurpose) && empty($blockedPurpose['ok']), 'a non-login purpose reached stage preview');

$root = dirname(__DIR__);
$combinedSource = (string)file_get_contents($root . '/api/lib/auth_sms.php')
    . (string)file_get_contents($root . '/api/auth/login_send_otp.php')
    . (string)file_get_contents($root . '/api/auth/user_login_resend_otp.php')
    . (string)file_get_contents($root . '/api/user/assets/pages/login-page.js');
stage_otp_expect(!str_contains($combinedSource, '60123456789'), 'test phone must not leak into application source');

echo "stage OTP preview tests passed\n";
