<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/auth_android.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$purpose = strtoupper(trim((string)($body['purpose'] ?? '')));
$pin = trim((string)($body['pin'] ?? ''));

if (in_array($purpose, ['TOPUP', 'ZPAY_TRANSFER', 'BUNDLE'], true)) {
    if ($pin === '') {
        api_response(false, 'VALIDATION_ERROR', 'PIN is required.', [], 422);
    }

    $auth = auth_require_user(true);
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $pinHash = (string)($user['pin_hash'] ?? '');

    if ($pinHash === '' || !password_verify($pin, $pinHash)) {
        api_response(false, 'WRONG_PIN', 'Incorrect PIN. Please try again.', [], 422);
    }

    api_response(true, 'PIN_VERIFIED', 'PIN verified.', [
        'purpose' => $purpose,
        'pin_verified' => true,
        'verification_method' => 'PIN',
    ]);
}

$preAuthToken = trim((string)($body['pre_auth_token'] ?? ''));
$deviceId = auth_app_device_id($body, (string)($body['device_id'] ?? 'ANDROID_APP'));
$deviceName = auth_app_device_name($body);

if ($pin === '') {
    api_response(false, 'VALIDATION_ERROR', 'PIN is required.', [], 422);
}

$preAuthRow = auth_app_get_valid_preauth($preAuthToken);

if (empty($preAuthRow['password_verified'])) {
    api_response(false, 'PASSWORD_REQUIRED', 'Password verification required first.', [], 400);
}

if ($deviceId !== '' && (string)($preAuthRow['device_id'] ?? '') !== '' && (string)($preAuthRow['device_id'] ?? '') !== $deviceId) {
    api_response(false, 'DEVICE_MISMATCH', 'Device mismatch for this login verification.', [], 400);
}

$account = auth_app_preauth_user($preAuthRow);
$uid = (string)$account['uid'];
$user = (array)$account['user'];

if (!auth_app_pin_ok($user, $pin)) {
    api_response(false, 'WRONG_PIN', 'PIN ভুল হয়েছে।', [], 401);
}

$trusted = auth_app_trusted_login_allowed($uid, $deviceId);
$now = now_ts();
$patch = [
    'pin_verified' => true,
    'status' => $trusted ? 'VERIFIED' : 'PIN_VERIFIED',
    'updated_at' => $now,
];

$data = [
    'pre_auth_token' => $preAuthToken,
    'otp_required' => !$trusted,
    'device_trusted' => $trusted,
];

if ($trusted) {
    $session = auth_app_issue_session($user, $uid, $deviceId, $deviceName, $preAuthRow);
    $patch['verified_at'] = $now;
    $data['session_token'] = (string)$session['session_token'];
    $data['user'] = auth_app_user_payload($uid, $user);
}

@fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, $patch);

api_response(true, 'PIN_VERIFIED', 'PIN verified.', $data);
