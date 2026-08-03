<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/user/includes/auth-guard.php';
require_once dirname(__DIR__, 2) . '/lib/auth_android.php';
require_once dirname(__DIR__) . '/lib/domain.php';

api_require_method('POST');
api_require_app_key();
$body = api_read_json_body();
$code = trim((string)($body['code'] ?? ''));
if ($code === '' || strlen($code) > 128 || preg_match('/[^A-Za-z0-9_-]/', $code) === 1) {
    api_response(false, 'ZNEWS_HANDOFF_INVALID', 'Z Sky 24 access link is invalid.', [], 422);
}

$path = znews_handoff_path($code);
$snapshot = fb_get_with_etag($path);
$handoff = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : null;
$etag = trim((string)($snapshot['etag'] ?? ''));
if (!is_array($handoff) || $etag === '') {
    api_response(false, 'ZNEWS_HANDOFF_REQUIRED', 'Open Z Sky 24 from your Z-Pay dashboard.', [], 401);
}

$expiresAt = (int)($handoff['expires_at'] ?? 0);
$storedHash = trim((string)($handoff['code_hash'] ?? ''));
if (!empty($handoff['used']) || $expiresAt <= now_ts() || $storedHash === '') {
    if ($expiresAt <= now_ts() && empty($handoff['used'])) {
        fb_delete_if_match($path, $etag);
    }
    api_response(false, 'ZNEWS_HANDOFF_EXPIRED', 'Z Sky 24 access link expired. Open it again from Z-Pay.', [], 401);
}

if (!hash_equals($storedHash, hash('sha256', $code))) {
    api_response(false, 'ZNEWS_HANDOFF_INVALID', 'Z Sky 24 access link is invalid.', [], 401);
}

$requestHost = znews_normalize_target_host(znews_request_host());
$targetHost = znews_normalize_target_host((string)($handoff['target_host'] ?? ''));
if ($requestHost !== $targetHost || $targetHost !== znews_handoff_target_host()) {
    api_response(false, 'ZNEWS_HANDOFF_HOST_INVALID', 'This access link is not valid for this host.', [], 403);
}

$context = znews_handoff_context();
if (!hash_equals((string)($handoff['ip_hash'] ?? ''), $context['ip_hash'])
    || !hash_equals((string)($handoff['user_agent_hash'] ?? ''), $context['user_agent_hash'])) {
    api_response(false, 'ZNEWS_HANDOFF_CONTEXT_INVALID', 'This access link is not valid on this device.', [], 403);
}

$sessionToken = znews_handoff_decrypt_token($handoff);
$expectedSessionHash = trim((string)($handoff['session_hash'] ?? ''));
if ($sessionToken === ''
    || $expectedSessionHash === ''
    || !hash_equals($expectedSessionHash, session_hash($sessionToken))) {
    api_response(false, 'SESSION_EXPIRED', 'Your Z-Pay session has expired.', [], 401);
}

$session = get_session_by_token($sessionToken);
if (!is_array($session)
    || auth_status_value($session['status'] ?? '') !== 'ACTIVE'
    || ((int)($session['expires_at'] ?? 0) > 0 && (int)$session['expires_at'] <= now_ts())) {
    api_response(false, 'SESSION_EXPIRED', 'Your Z-Pay session has expired.', [], 401);
}

$uid = trim((string)($session['uid'] ?? ''));
if ($uid === '' || !hash_equals(trim((string)($handoff['uid'] ?? '')), $uid)) {
    api_response(false, 'ZNEWS_HANDOFF_INVALID', 'Z Sky 24 access link is invalid.', [], 401);
}

if (!hash_equals(
    trim((string)($handoff['auth_session_epoch'] ?? '')),
    trim((string)($session['auth_session_epoch'] ?? ''))
) || !hash_equals(
    trim((string)($handoff['device_id'] ?? '')),
    trim((string)($session['device_id'] ?? ''))
)) {
    api_response(false, 'SESSION_REVOKED', 'Your Z-Pay session is no longer active.', [], 401);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
}

auth_app_guard_user_login($user);
$handoff['used'] = true;
$handoff['used_at'] = now_ts();
$handoff['token_ciphertext'] = '';
$handoff['token_iv'] = '';
$handoff['token_tag'] = '';
$claim = fb_put_if_match($path, $handoff, $etag);
if (($claim['status'] ?? 0) < 200 || ($claim['status'] ?? 0) >= 300) {
    api_response(false, 'ZNEWS_HANDOFF_REPLAYED', 'This access link was already used.', [], 409);
}

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

api_response(true, 'ZNEWS_HANDOFF_ACCEPTED', 'Z Sky 24 creator access granted.', [
    'session_token' => $sessionToken,
    'user' => $profile,
    'access_mode' => 'CREATOR',
]);
