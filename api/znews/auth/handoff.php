<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/user/includes/auth-guard.php';
require_once dirname(__DIR__, 2) . '/lib/auth_android.php';

api_require_method('POST');
api_require_app_key();
user_page_start_session();

$body = api_read_json_body();
$code = trim((string)($body['code'] ?? ''));
if ($code === '' || strlen($code) > 128 || preg_match('/[^A-Za-z0-9_-]/', $code) === 1) {
    api_response(false, 'ZNEWS_HANDOFF_INVALID', 'Z News access link is invalid.', [], 422);
}

$handoff = $_SESSION['znews_handoff'] ?? null;
if (!is_array($handoff)) {
    api_response(false, 'ZNEWS_HANDOFF_REQUIRED', 'Open Z News from your Z-Pay dashboard.', [], 401);
}

$expiresAt = (int)($handoff['expires_at'] ?? 0);
$storedHash = trim((string)($handoff['code_hash'] ?? ''));
if (!empty($handoff['used']) || $expiresAt <= now_ts() || $storedHash === '') {
    unset($_SESSION['znews_handoff']);
    api_response(false, 'ZNEWS_HANDOFF_EXPIRED', 'Z News access link expired. Open it again from Z-Pay.', [], 401);
}

if (!hash_equals($storedHash, hash('sha256', $code))) {
    api_response(false, 'ZNEWS_HANDOFF_INVALID', 'Z News access link is invalid.', [], 401);
}

$sessionToken = trim((string)($_SESSION['user_session_token'] ?? ''));
$expectedSessionHash = trim((string)($handoff['session_hash'] ?? ''));
if ($sessionToken === ''
    || $expectedSessionHash === ''
    || !hash_equals($expectedSessionHash, session_hash($sessionToken))) {
    unset($_SESSION['znews_handoff']);
    api_response(false, 'SESSION_EXPIRED', 'Your Z-Pay session has expired.', [], 401);
}

$session = get_session_by_token($sessionToken);
if (!is_array($session)
    || auth_status_value($session['status'] ?? '') !== 'ACTIVE'
    || ((int)($session['expires_at'] ?? 0) > 0 && (int)$session['expires_at'] <= now_ts())) {
    unset($_SESSION['znews_handoff'], $_SESSION['user_session_token']);
    api_response(false, 'SESSION_EXPIRED', 'Your Z-Pay session has expired.', [], 401);
}

$uid = trim((string)($session['uid'] ?? ''));
if ($uid === '' || !hash_equals(trim((string)($handoff['uid'] ?? '')), $uid)) {
    unset($_SESSION['znews_handoff']);
    api_response(false, 'ZNEWS_HANDOFF_INVALID', 'Z News access link is invalid.', [], 401);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    unset($_SESSION['znews_handoff']);
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

auth_app_guard_user_login($user);
$user['uid'] = $uid;
$creator = znews_public_creator_snapshot($user);
$profilePhoto = trim((string)($creator['profile_photo_url'] ?? ''));
if ($profilePhoto === '') {
    $profilePhoto = trim((string)($user['PROFILE'] ?? $user['profile'] ?? ''));
}
$profile = array_merge(auth_app_user_payload($uid, $user), [
    'profile_photo_url' => $profilePhoto,
    'PROFILE' => $profilePhoto,
]);

$_SESSION['znews_handoff']['used'] = true;
$_SESSION['znews_handoff']['used_at'] = now_ts();
session_write_close();

api_response(true, 'ZNEWS_HANDOFF_ACCEPTED', 'Z News creator access granted.', [
    'session_token' => $sessionToken,
    'user' => $profile,
    'access_mode' => 'CREATOR',
]);
