<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function admin_mobile_enabled(): bool
{
    return function_exists('auth_admin_mobile_feature_enabled')
        && auth_admin_mobile_feature_enabled();
}

function admin_mobile_session_ttl_seconds(): int
{
    $ttl = defined('ADMIN_MOBILE_SESSION_TTL_SECONDS')
        ? (int)constant('ADMIN_MOBILE_SESSION_TTL_SECONDS')
        : 7200;

    return max(900, min(28800, $ttl));
}

function admin_mobile_header(string $name): string
{
    return trim((string)(api_get_header($name) ?? ''));
}

function admin_mobile_device_id(bool $required = true): string
{
    $deviceId = admin_mobile_header('X-ADMIN-DEVICE-ID');
    if ($deviceId !== '' && preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $deviceId) !== 1) {
        api_response(false, 'INVALID_ADMIN_DEVICE', 'Admin device identifier is invalid.', [], 422);
    }
    if ($required && $deviceId === '') {
        api_response(false, 'INVALID_ADMIN_DEVICE', 'Admin device identifier is required.', [], 422);
    }

    return $deviceId;
}

function admin_mobile_version_code(): int
{
    return max(0, (int)admin_mobile_header('X-ADMIN-APP-VERSION-CODE'));
}

function admin_mobile_device_name(array $body = []): string
{
    $name = trim((string)($body['device_name'] ?? admin_mobile_header('X-ADMIN-DEVICE-NAME')));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    return substr($name !== '' ? $name : 'Z-Pay Swift Admin', 0, 80);
}

function admin_mobile_require_available(): void
{
    api_require_app_key();

    if (!admin_mobile_enabled()) {
        api_response(false, 'ADMIN_MOBILE_DISABLED', 'Admin mobile access is disabled.', [], 503);
    }

    $versionCode = admin_mobile_version_code();
    $minimumVersion = function_exists('auth_admin_mobile_min_version_code')
        ? auth_admin_mobile_min_version_code()
        : 1;
    if ($versionCode < $minimumVersion) {
        api_response(false, 'ADMIN_MOBILE_UPDATE_REQUIRED', 'Please update the admin app to continue.', [
            'minimum_version_code' => $minimumVersion,
        ], 426);
    }
}

function admin_mobile_require_session(bool $touch = true): array
{
    admin_mobile_require_available();
    admin_mobile_device_id(true);
    return auth_require_admin_session($touch);
}

function admin_mobile_api_base_url(): string
{
    if (function_exists('app_api_url')) {
        return rtrim(app_api_url(), '/');
    }

    $origin = defined('APP_PUBLIC_ORIGIN') ? trim((string)constant('APP_PUBLIC_ORIGIN')) : '';
    return rtrim($origin !== '' ? $origin : 'https://zpayswift.com', '/') . '/api';
}

function admin_mobile_internal_request(
    string $method,
    string $relativePath,
    ?array $body = null,
    string $sessionToken = '',
    string $deviceId = ''
): array {
    $url = admin_mobile_api_base_url() . '/' . ltrim($relativePath, '/');
    $headers = [
        'Accept: application/json',
        'X-APP-KEY: ' . APP_KEY,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($sessionToken !== '') {
        $headers[] = 'X-SESSION-TOKEN: ' . $sessionToken;
        $headers[] = 'Authorization: Bearer ' . $sessionToken;
    }
    if ($deviceId !== '') {
        $headers[] = 'X-ADMIN-DEVICE-ID: ' . $deviceId;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($json)) {
        return [
            'ok' => false,
            'status' => $status > 0 ? $status : 502,
            'json' => [
                'ok' => false,
                'code' => 'UPSTREAM_UNAVAILABLE',
                'message' => 'Admin service is temporarily unavailable.',
                'data' => [],
            ],
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
    ];
}

function admin_mobile_emit_internal(array $result): void
{
    $json = is_array($result['json'] ?? null) ? $result['json'] : [];
    $status = (int)($result['status'] ?? 500);
    api_response(
        !empty($result['ok']),
        (string)($json['code'] ?? 'SERVER_ERROR'),
        (string)($json['message'] ?? 'Admin request failed.'),
        (array)($json['data'] ?? []),
        $status > 0 ? $status : 500
    );
}

function admin_mobile_finalize_session(string $sessionToken, string $deviceId, string $deviceName): array
{
    $sessionToken = trim($sessionToken);
    if ($sessionToken === '') {
        return ['ok' => false, 'code' => 'SESSION_MISSING', 'message' => 'Admin session was not created.'];
    }

    $session = get_session_by_token($sessionToken);
    $uid = is_array($session) ? trim((string)($session['uid'] ?? '')) : '';
    $user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
    $role = is_array($user) ? strtoupper(trim((string)($user['role'] ?? ''))) : '';
    $status = is_array($user) ? strtoupper(trim((string)($user['status'] ?? ''))) : '';
    if (!is_array($session) || !is_array($user) || $role !== 'ADMIN' || $status !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Admin access is unavailable.'];
    }

    $sessionDeviceId = trim((string)($session['device_id'] ?? ''));
    if ($sessionDeviceId === '' || !hash_equals($sessionDeviceId, $deviceId)) {
        return ['ok' => false, 'code' => 'ADMIN_DEVICE_MISMATCH', 'message' => 'Admin session device mismatch.'];
    }

    $sessionHash = trim((string)($session['_session_hash'] ?? session_hash($sessionToken)));
    $deviceKey = auth_admin_mobile_device_key($deviceId);
    $devicePath = 'ADMIN_MOBILE_DEVICES/' . $uid . '/' . $deviceKey;
    $existingDevice = fb_get($devicePath);
    if (is_array($existingDevice) && strtoupper(trim((string)($existingDevice['status'] ?? ''))) === 'REVOKED') {
        fb_patch('USER_SESSIONS/' . $sessionHash, [
            'status' => 'DEVICE_REVOKED',
            'updated_at' => now_ts(),
        ]);
        return ['ok' => false, 'code' => 'ADMIN_DEVICE_REVOKED', 'message' => 'This admin device has been revoked.'];
    }

    $now = now_ts();
    $expiresAt = $now + admin_mobile_session_ttl_seconds();
    $versionCode = admin_mobile_version_code();
    $versionName = substr(admin_mobile_header('X-ADMIN-APP-VERSION-NAME'), 0, 40);
    $firstSeenAt = is_array($existingDevice) ? max(0, (int)($existingDevice['first_seen_at'] ?? 0)) : 0;
    if ($firstSeenAt <= 0) {
        $firstSeenAt = $now;
    }

    $updates = [
        'USER_SESSIONS/' . $sessionHash . '/channel' => 'ADMIN_MOBILE',
        'USER_SESSIONS/' . $sessionHash . '/admin_mobile_device_key' => $deviceKey,
        'USER_SESSIONS/' . $sessionHash . '/admin_mobile_version_code' => $versionCode,
        'USER_SESSIONS/' . $sessionHash . '/admin_mobile_version_name' => $versionName,
        'USER_SESSIONS/' . $sessionHash . '/expires_at' => $expiresAt,
        'USER_SESSIONS/' . $sessionHash . '/updated_at' => $now,
        $devicePath . '/device_key' => $deviceKey,
        $devicePath . '/device_id' => $deviceId,
        $devicePath . '/device_name' => $deviceName,
        $devicePath . '/status' => 'ACTIVE',
        $devicePath . '/first_seen_at' => $firstSeenAt,
        $devicePath . '/last_seen_at' => $now,
        $devicePath . '/app_version_code' => $versionCode,
        $devicePath . '/app_version_name' => $versionName,
        $devicePath . '/last_ip_hash' => function_exists('security_ip_hash')
            ? security_ip_hash(function_exists('security_client_ip') ? security_client_ip() : '')
            : '',
    ];

    if (!fb_patch('', $updates)) {
        fb_patch('USER_SESSIONS/' . $sessionHash, ['status' => 'SETUP_FAILED', 'updated_at' => $now]);
        return ['ok' => false, 'code' => 'DEVICE_REGISTRATION_FAILED', 'message' => 'Admin device could not be registered.'];
    }

    if (function_exists('admin_action_log')) {
        admin_action_log('ADMIN_MOBILE_LOGIN', $uid, 'Admin mobile login completed', [
            'admin_uid' => $uid,
            'device_key' => $deviceKey,
            'app_version_code' => $versionCode,
        ]);
    }

    return [
        'ok' => true,
        'session_token' => $sessionToken,
        'session_expires_at' => $expiresAt,
        'user' => [
            'uid' => $uid,
            'name' => trim((string)($user['name'] ?? '')),
            'phone' => trim((string)($user['phone'] ?? '')),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ],
    ];
}

function admin_mobile_current_token(): string
{
    return function_exists('auth_get_session_token_from_request')
        ? auth_get_session_token_from_request()
        : '';
}

function admin_mobile_count_page(array $page): array
{
    $items = is_array($page['items'] ?? null) ? $page['items'] : [];
    $pagination = is_array($page['pagination'] ?? null) ? $page['pagination'] : [];

    return [
        'count' => count($items),
        'has_more' => !empty($pagination['has_more']),
    ];
}
