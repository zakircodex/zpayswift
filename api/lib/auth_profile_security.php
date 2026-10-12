<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function auth_app_change_profile_credential(array $auth, string $field, string $newHash): array
{
    $error = static fn(string $code, int $status): array => [
        'ok' => false, 'code' => $code, 'http_status' => $status,
    ];
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $session = is_array($auth['session'] ?? null) ? $auth['session'] : [];
    $uid = auth_clean_string($user['uid'] ?? '');
    $deviceId = auth_clean_string($session['device_id'] ?? '');
    $oldHash = auth_clean_string($auth['session_hash'] ?? '');
    if ($uid === '' || $deviceId === '' || $oldHash === ''
        || (string)($session['uid'] ?? '') !== $uid
        || !in_array($field, ['password_hash', 'pin_hash'], true) || $newHash === '') {
        return $error('SESSION_EXPIRED', 401);
    }

    $path = 'USERS/' . $uid;
    $snapshot = fb_get_with_etag($path);
    $latest = $snapshot['value'] ?? null;
    $etag = (string)($snapshot['etag'] ?? '');
    if (empty($snapshot['ok']) || !is_array($latest) || $etag === '') {
        return $error('SECURITY_UPDATE_UNAVAILABLE', 503);
    }
    if (auth_status_value($latest['status'] ?? '') !== 'ACTIVE'
        || auth_status_value($latest['account_status'] ?? $latest['status'] ?? '') !== 'ACTIVE'
        || !hash_equals((string)($user[$field] ?? ''), (string)($latest[$field] ?? ''))
        || auth_session_epoch_from_user($latest)
            !== auth_clean_string($session['auth_session_epoch'] ?? $session['session_epoch'] ?? '')
        || auth_clean_string($latest['active_device_id'] ?? $latest['ACTIVE_DEVICE_ID'] ?? '') !== $deviceId) {
        return $error('SESSION_EXPIRED', 401);
    }

    $now = now_ts();
    $epoch = auth_new_session_epoch();
    $token = random_token(32);
    $hash = session_hash($token);
    $deviceName = auth_clean_string($session['device_name'] ?? 'Android App');
    $appVersion = (string)($latest['active_device_app_version'] ?? '');
    $replacement = [
        'session_id' => make_session_id(),
        'uid' => $uid,
        'phone' => (string)($latest['phone'] ?? ''),
        'token_last8' => substr($token, -8),
        'device_id' => $deviceId,
        'device_name' => $deviceName,
        'status' => 'ACTIVE',
        'ip' => client_ip(),
        'created_at' => $now,
        'last_seen_at' => $now,
        'expires_at' => $now + SESSION_TTL_SECONDS,
        'auth_session_epoch' => $epoch,
    ];

    // The prepared token cannot authenticate until the user epoch is committed.
    if (!fb_put('USER_SESSIONS/' . $hash, $replacement)) {
        return $error('SECURITY_UPDATE_UNAVAILABLE', 503);
    }
    if (!auth_mark_device_trusted($uid, $deviceId, $deviceName, $appVersion)) {
        @fb_delete('USER_SESSIONS/' . $hash);
        return $error('SECURITY_UPDATE_UNAVAILABLE', 503);
    }

    $updates = [
        $field => $newHash,
        'auth_session_epoch' => $epoch,
        'session_epoch' => $epoch,
        'credentials_revoked_at' => $now,
        'updated_at' => $now,
        $field === 'pin_hash' ? 'pin_changed_at' : 'password_changed_at' => $now,
    ];
    $result = fb_put_if_match($path, array_replace($latest, $updates), $etag);
    if (empty($result['ok'])) {
        @fb_delete('USER_SESSIONS/' . $hash);
        return $error((int)($result['status'] ?? 0) === 412
            ? 'SECURITY_UPDATE_CONFLICT' : 'SECURITY_UPDATE_UNAVAILABLE',
            (int)($result['status'] ?? 0) === 412 ? 409 : 503);
    }

    // All old epochs are invalid, even if a best-effort cleanup write fails.
    auth_app_revoke_user_trust_records($uid, $now, $deviceId);
    @fb_patch('USER_SESSIONS/' . $oldHash, ['status' => 'RESET_REVOKED', 'updated_at' => $now]);
    $trusted = auth_issue_trusted_device_cookie($uid, $deviceId, $deviceName, $epoch);
    $cookie = empty($trusted['ok']) ? null : [
        'uid' => $uid,
        'selector' => (string)($trusted['selector'] ?? ''),
        'token' => (string)($trusted['token'] ?? ''),
        'expires_at' => (int)($trusted['expires_at'] ?? 0),
    ];
    return [
        'ok' => true,
        'data' => [
            'session_token' => $token,
            'trusted_device_cookie' => $cookie,
            'session_preserved' => true,
            'reauth_required' => false,
        ],
    ];
}
