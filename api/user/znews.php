<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-guard.php';
user_page_require_auth();

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth_android.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$sessionToken = trim((string)($_SESSION['user_session_token'] ?? ''));
$session = $sessionToken !== '' ? get_session_by_token($sessionToken) : null;

if (!is_array($session)
    || auth_status_value($session['status'] ?? '') !== 'ACTIVE'
    || ((int)($session['expires_at'] ?? 0) > 0 && (int)$session['expires_at'] <= now_ts())) {
    unset($_SESSION['user_session_token'], $_SESSION['znews_handoff']);
    session_write_close();
    header('Location: /user/?reason=session_expired', true, 302);
    exit;
}

$uid = trim((string)($session['uid'] ?? ''));
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
if (!is_array($user)) {
    unset($_SESSION['user_session_token'], $_SESSION['znews_handoff']);
    session_write_close();
    header('Location: /user/?reason=account_not_found', true, 302);
    exit;
}

auth_app_guard_user_login($user);

$code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$now = now_ts();
$_SESSION['znews_handoff'] = [
    'code_hash' => hash('sha256', $code),
    'uid' => $uid,
    'session_hash' => session_hash($sessionToken),
    'created_at' => $now,
    'expires_at' => $now + 90,
    'used' => false,
];

session_write_close();
header('Location: /znews/#handoff=' . rawurlencode($code), true, 302);
exit;
