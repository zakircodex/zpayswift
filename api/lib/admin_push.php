<?php
declare(strict_types=1);

$adminPushScriptFilename = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($adminPushScriptFilename !== false && $adminPushScriptFilename === realpath(__FILE__)) {
    http_response_code(404);
    exit('Not Found');
}
unset($adminPushScriptFilename);

function admin_push_load_fcm_helper(): bool
{
    $required = [
        'fcm_valid_registration_token',
        'fcm_token_hash',
        'fcm_clean_text',
        'fcm_load_service_account',
        'fcm_project_id',
        'fcm_access_token',
        'fcm_send_one',
        'fcm_error_means_unregister',
    ];
    $missing = array_filter($required, static fn(string $name): bool => !function_exists($name));
    if ($missing === []) {
        return true;
    }
    foreach ((array)(get_defined_functions()['user'] ?? []) as $functionName) {
        if (str_starts_with((string)$functionName, 'fcm_')) {
            return false;
        }
    }
    require_once __DIR__ . '/fcm.php';
    return array_filter($required, static fn(string $name): bool => !function_exists($name)) === [];
}

function admin_push_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function admin_push_clean_code($value, int $max = 60): string
{
    $code = strtoupper(trim((string)$value));
    $code = preg_replace('/[^A-Z0-9_]+/', '_', $code) ?? '';
    return substr(trim($code, '_'), 0, $max);
}

function admin_push_register_device_token(
    string $uid,
    string $deviceId,
    string $token,
    string $appVersion = ''
): array {
    $uid = trim($uid);
    $deviceId = trim($deviceId);
    $token = trim($token);
    if (!admin_push_load_fcm_helper()) {
        return ['ok' => false, 'code' => 'FCM_HELPER_UNAVAILABLE', 'message' => 'Notification service is unavailable.', 'status' => 503];
    }
    if ($uid === '' || $deviceId === '') {
        return ['ok' => false, 'code' => 'ADMIN_PUSH_DEVICE_INVALID', 'message' => 'Admin device is invalid.', 'status' => 422];
    }
    if (!fcm_valid_registration_token($token)) {
        return ['ok' => false, 'code' => 'FCM_TOKEN_INVALID', 'message' => 'Notification token is invalid.', 'status' => 422];
    }

    $deviceKey = function_exists('auth_admin_mobile_device_key')
        ? auth_admin_mobile_device_key($deviceId)
        : hash('sha256', $deviceId);
    $tokenHash = fcm_token_hash($token);
    $path = 'ADMIN_MOBILE_PUSH_TOKENS/' . $uid . '/' . $deviceKey . '/' . $tokenHash;
    $existing = fb_get($path);
    $now = admin_push_now();
    $saved = fb_put($path, [
        'uid' => $uid,
        'device_key' => $deviceKey,
        'device_id' => $deviceId,
        'token' => $token,
        'token_hash' => $tokenHash,
        'platform' => 'ANDROID',
        'app_version' => fcm_clean_text($appVersion, 40),
        'active' => true,
        'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]);

    return $saved
        ? ['ok' => true, 'code' => 'ADMIN_PUSH_REGISTERED', 'message' => 'Admin notifications enabled.', 'token_hash' => $tokenHash]
        : ['ok' => false, 'code' => 'ADMIN_PUSH_SAVE_FAILED', 'message' => 'Notification token could not be saved.', 'status' => 500];
}

function admin_push_deactivate_device(string $uid, string $deviceId): int
{
    $uid = trim($uid);
    $deviceId = trim($deviceId);
    if ($uid === '' || $deviceId === '') {
        return 0;
    }
    $deviceKey = function_exists('auth_admin_mobile_device_key')
        ? auth_admin_mobile_device_key($deviceId)
        : hash('sha256', $deviceId);
    $rows = fb_get('ADMIN_MOBILE_PUSH_TOKENS/' . $uid . '/' . $deviceKey);
    if (!is_array($rows)) {
        return 0;
    }
    $count = 0;
    $now = admin_push_now();
    foreach ($rows as $tokenHash => $row) {
        if (!is_array($row) || empty($row['active'])) {
            continue;
        }
        if (fb_patch('ADMIN_MOBILE_PUSH_TOKENS/' . $uid . '/' . $deviceKey . '/' . $tokenHash, [
            'active' => false,
            'deactivated_at' => $now,
            'updated_at' => $now,
        ])) {
            $count++;
        }
    }
    return $count;
}

function admin_push_active_tokens(): array
{
    if (!admin_push_load_fcm_helper()) {
        return [];
    }
    $admins = fb_get('ADMIN_MOBILE_PUSH_TOKENS');
    if (!is_array($admins)) {
        return [];
    }
    $tokens = [];
    $seen = [];
    foreach ($admins as $uid => $devices) {
        if (!is_array($devices)) {
            continue;
        }
        $user = fb_get('USERS/' . $uid);
        if (!is_array($user)) {
            continue;
        }
        $role = strtoupper(trim((string)($user['role'] ?? '')));
        $status = strtoupper(trim((string)($user['account_status'] ?? $user['status'] ?? '')));
        if ($role !== 'ADMIN' || $status !== 'ACTIVE') {
            continue;
        }
        foreach ($devices as $deviceKey => $deviceTokens) {
            if (!is_array($deviceTokens)) {
                continue;
            }
            $device = fb_get('ADMIN_MOBILE_DEVICES/' . $uid . '/' . $deviceKey);
            if (!is_array($device) || strtoupper(trim((string)($device['status'] ?? ''))) !== 'ACTIVE') {
                continue;
            }
            foreach ($deviceTokens as $tokenHash => $row) {
                if (!is_array($row) || empty($row['active'])) {
                    continue;
                }
                $token = trim((string)($row['token'] ?? ''));
                if (!fcm_valid_registration_token($token)) {
                    fb_patch('ADMIN_MOBILE_PUSH_TOKENS/' . $uid . '/' . $deviceKey . '/' . $tokenHash, [
                        'active' => false,
                        'deactivated_at' => admin_push_now(),
                        'updated_at' => admin_push_now(),
                    ]);
                    continue;
                }
                $hash = fcm_token_hash($token);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                $tokens[] = [
                    'uid' => (string)$uid,
                    'device_key' => (string)$deviceKey,
                    'token_hash' => (string)$tokenHash,
                    'token' => $token,
                ];
            }
        }
    }
    return $tokens;
}

function admin_push_request_label(string $type): string
{
    return match ($type) {
        'ADD_MONEY' => 'Add Money',
        'BKASH' => 'bKash',
        'NAGAD' => 'Nagad',
        'TOPUP' => 'Topup',
        'BUNDLE' => 'Bundle',
        'ACCOUNT_REVIEW' => 'Account review',
        'SUPPORT' => 'Support',
        default => 'New',
    };
}

function admin_push_notify_request(string $type, string $eventId, array $row = []): array
{
    try {
        if (!admin_push_load_fcm_helper()) {
            return ['ok' => false, 'code' => 'FCM_HELPER_UNAVAILABLE', 'sent' => 0];
        }
        $type = admin_push_clean_code($type);
        $eventId = trim($eventId);
        $requestId = trim((string)($row['request_id'] ?? $row['ticket_id'] ?? $row['uid'] ?? $eventId));
        if ($type === '' || $eventId === '' || $requestId === '') {
            return ['ok' => false, 'code' => 'ADMIN_PUSH_EVENT_INVALID', 'sent' => 0];
        }
        $dedupeHash = hash('sha256', $type . '|' . $eventId);
        if (is_array(fb_get('ADMIN_MOBILE_PUSH_DEDUPE/' . $dedupeHash))) {
            return ['ok' => true, 'code' => 'ADMIN_PUSH_DUPLICATE_SKIPPED', 'sent' => 0, 'duplicate' => true];
        }
        $tokens = admin_push_active_tokens();
        if ($tokens === []) {
            return ['ok' => true, 'code' => 'ADMIN_PUSH_NO_ACTIVE_TOKENS', 'sent' => 0];
        }
        $serviceAccount = fcm_load_service_account();
        $projectId = fcm_project_id($serviceAccount);
        if ($serviceAccount === [] || $projectId === '') {
            return ['ok' => false, 'code' => 'FCM_CONFIG_MISSING', 'sent' => 0];
        }
        $access = fcm_access_token($serviceAccount, 8, 3);
        if (empty($access['ok'])) {
            return ['ok' => false, 'code' => (string)($access['code'] ?? 'FCM_AUTH_FAILED'), 'sent' => 0];
        }

        $label = admin_push_request_label($type);
        $title = 'New ' . $label . ' request';
        $body = $label . ' needs your review.';
        $payload = [
            'type' => 'ADMIN_NEW_REQUEST',
            'request_type' => $type,
            'request_id' => $requestId,
            'title' => $title,
            'body' => $body,
            'created_at' => (string)admin_push_now(),
        ];
        $sent = 0;
        $failed = 0;
        foreach ($tokens as $tokenRow) {
            $result = fcm_send_one(
                $projectId,
                (string)$access['token'],
                (string)$tokenRow['token'],
                $title,
                $body,
                $payload,
                8,
                3
            );
            if (!empty($result['ok'])) {
                $sent++;
                continue;
            }
            $failed++;
            if (fcm_error_means_unregister($result)) {
                fb_patch(
                    'ADMIN_MOBILE_PUSH_TOKENS/' . $tokenRow['uid'] . '/' . $tokenRow['device_key'] . '/' . $tokenRow['token_hash'],
                    ['active' => false, 'deactivated_at' => admin_push_now(), 'updated_at' => admin_push_now()]
                );
            }
        }
        if ($sent > 0) {
            fb_put('ADMIN_MOBILE_PUSH_DEDUPE/' . $dedupeHash, [
                'event_type' => $type,
                'event_id_hash' => hash('sha256', $eventId),
                'request_id' => $requestId,
                'sent_count' => $sent,
                'created_at' => admin_push_now(),
            ]);
        }
        return ['ok' => $sent > 0 || $failed === 0, 'code' => 'ADMIN_PUSH_SEND_COMPLETE', 'sent' => $sent, 'failed' => $failed];
    } catch (Throwable $exception) {
        return ['ok' => false, 'code' => 'ADMIN_PUSH_FAILED', 'sent' => 0];
    }
}
