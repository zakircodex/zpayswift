<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function fcm_now(): int
{
    return function_exists('now_ts') ? now_ts() : time();
}

function fcm_clean_text($value, int $max = 500): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

function fcm_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function fcm_valid_registration_token(string $token): bool
{
    $token = trim($token);
    if (strlen($token) < 80 || strlen($token) > 4096) {
        return false;
    }
    return preg_match('/^\S+$/', $token) === 1;
}

function fcm_private_base_dir(): string
{
    if (function_exists('app_private_config_path')) {
        return dirname(app_private_config_path());
    }
    return dirname(__DIR__, 2);
}

function fcm_service_account_path(): string
{
    $path = defined('FIREBASE_FCM_SERVICE_ACCOUNT_PATH')
        ? trim((string)FIREBASE_FCM_SERVICE_ACCOUNT_PATH)
        : '';
    if ($path === '') {
        return '';
    }
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
        return $path;
    }
    return rtrim(fcm_private_base_dir(), '/\\') . DIRECTORY_SEPARATOR . $path;
}

function fcm_load_service_account(): array
{
    $path = fcm_service_account_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }
    $json = json_decode((string)file_get_contents($path), true);
    return is_array($json) ? $json : [];
}

function fcm_project_id(array $serviceAccount = []): string
{
    $configured = defined('FIREBASE_FCM_PROJECT_ID') ? trim((string)FIREBASE_FCM_PROJECT_ID) : '';
    if ($configured !== '') {
        return $configured;
    }
    return trim((string)($serviceAccount['project_id'] ?? ''));
}

function fcm_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function fcm_access_token(array $serviceAccount): array
{
    $clientEmail = trim((string)($serviceAccount['client_email'] ?? ''));
    $privateKey = (string)($serviceAccount['private_key'] ?? '');
    if ($clientEmail === '' || $privateKey === '') {
        return ['ok' => false, 'code' => 'FCM_CREDENTIALS_MISSING', 'message' => 'FCM service account is not configured.'];
    }

    $now = time();
    $header = fcm_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $claims = fcm_base64url(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return ['ok' => false, 'code' => 'FCM_JWT_SIGN_FAILED', 'message' => 'FCM authentication failed.'];
    }
    $jwt = $signingInput . '.' . fcm_base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'code' => 'FCM_OAUTH_CURL_FAILED', 'message' => $err ?: 'FCM OAuth request failed.'];
    }
    $json = json_decode((string)$raw, true);
    $token = is_array($json) ? (string)($json['access_token'] ?? '') : '';
    if ($status >= 200 && $status < 300 && $token !== '') {
        return ['ok' => true, 'token' => $token];
    }
    return ['ok' => false, 'code' => 'FCM_OAUTH_FAILED', 'message' => 'FCM OAuth request failed.'];
}

function fcm_register_device_token(string $uid, string $token, string $deviceId, string $appVersion = ''): array
{
    $uid = trim($uid);
    $token = trim($token);
    $deviceId = fcm_clean_text($deviceId, 120);
    if ($uid === '') {
        return ['ok' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Login required.', 'status' => 401];
    }
    if (!fcm_valid_registration_token($token)) {
        return ['ok' => false, 'code' => 'FCM_TOKEN_INVALID', 'message' => 'Device notification token is invalid.', 'status' => 422];
    }

    $hash = fcm_token_hash($token);
    $path = 'USER_DEVICE_TOKENS/' . $uid . '/' . $hash;
    $existing = fb_get($path);
    $now = fcm_now();
    $row = [
        'token' => $token,
        'token_hash' => $hash,
        'device_id' => $deviceId,
        'platform' => 'ANDROID',
        'app_version' => fcm_clean_text($appVersion, 40),
        'active' => true,
        'created_at' => is_array($existing) && isset($existing['created_at']) ? (int)$existing['created_at'] : $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ];
    if (!fb_put($path, $row)) {
        return ['ok' => false, 'code' => 'FCM_TOKEN_SAVE_FAILED', 'message' => 'Notification token could not be saved.', 'status' => 500];
    }
    if ($deviceId !== '') {
        fb_put('USER_DEVICE_TOKEN_INDEX/' . $uid . '/' . hash('sha256', $deviceId) . '/' . $hash, [
            'token_hash' => $hash,
            'device_id' => $deviceId,
            'active' => true,
            'updated_at' => $now,
        ]);
    }
    return ['ok' => true, 'token_hash' => $hash];
}

function fcm_deactivate_user_token(string $uid, string $token): void
{
    $uid = trim($uid);
    $token = trim($token);
    if ($uid === '' || $token === '') {
        return;
    }
    fcm_deactivate_user_token_hash($uid, fcm_token_hash($token));
}

function fcm_deactivate_user_token_hash(string $uid, string $hash): void
{
    $uid = trim($uid);
    $hash = preg_replace('/[^a-f0-9]/i', '', trim($hash)) ?? '';
    if ($uid === '' || $hash === '') {
        return;
    }
    fb_patch('USER_DEVICE_TOKENS/' . $uid . '/' . $hash, [
        'active' => false,
        'deactivated_at' => fcm_now(),
        'updated_at' => fcm_now(),
    ]);
}

function fcm_deactivate_user_device_tokens(string $uid, string $deviceId): void
{
    $uid = trim($uid);
    $deviceId = fcm_clean_text($deviceId, 120);
    if ($uid === '' || $deviceId === '') {
        return;
    }
    $rows = fb_get('USER_DEVICE_TOKENS/' . $uid);
    if (!is_array($rows)) {
        return;
    }
    $now = fcm_now();
    foreach ($rows as $hash => $row) {
        if (is_array($row) && (string)($row['device_id'] ?? '') === $deviceId && !empty($row['active'])) {
            fb_patch('USER_DEVICE_TOKENS/' . $uid . '/' . $hash, [
                'active' => false,
                'deactivated_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

function fcm_active_user_tokens(string $uid): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }
    $rows = fb_get('USER_DEVICE_TOKENS/' . $uid);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $hash => $row) {
        if (!is_array($row) || empty($row['active'])) {
            continue;
        }
        $token = trim((string)($row['token'] ?? ''));
        if (!fcm_valid_registration_token($token)) {
            fcm_deactivate_user_token_hash($uid, (string)$hash);
            continue;
        }
        $out[] = [
            'token' => $token,
            'token_hash' => (string)($row['token_hash'] ?? $hash),
        ];
    }
    return $out;
}

function fcm_send_one(string $projectId, string $accessToken, string $token, string $title, string $body, array $data): array
{
    $payloadData = [];
    foreach ($data as $key => $value) {
        $key = preg_replace('/[^A-Za-z0-9_]+/', '_', trim((string)$key)) ?? '';
        if ($key !== '') {
            $payloadData[$key] = (string)$value;
        }
    }
    $payload = [
        'message' => [
            'token' => $token,
            'data' => $payloadData,
            'android' => [
                'priority' => 'HIGH',
            ],
        ],
    ];

    $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'code' => 'FCM_CURL_FAILED', 'message' => $err ?: 'FCM send failed.'];
    }
    $json = json_decode((string)$raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && isset($json['name'])) {
        return ['ok' => true, 'status' => $status, 'code' => 'FCM_SENT'];
    }
    $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
    $code = (string)($error['status'] ?? $error['message'] ?? 'FCM_SEND_FAILED');
    return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => 'FCM send failed.'];
}

function fcm_error_means_unregister(array $result): bool
{
    $code = strtoupper((string)($result['code'] ?? ''));
    $status = (int)($result['status'] ?? 0);
    return str_contains($code, 'UNREGISTERED')
        || str_contains($code, 'NOT_FOUND')
        || ($status === 404)
        || ($status === 400 && str_contains($code, 'INVALID_ARGUMENT'));
}

function fcm_send_to_user(string $uid, string $title, string $body, array $data, string $dedupeKey = ''): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return ['ok' => false, 'code' => 'FCM_UID_MISSING', 'sent' => 0];
    }
    $dedupeHash = $dedupeKey === '' ? '' : hash('sha256', $dedupeKey);
    if ($dedupeHash !== '' && is_array(fb_get('USER_PUSH_DEDUPE/' . $uid . '/' . $dedupeHash))) {
        return ['ok' => true, 'code' => 'FCM_DUPLICATE_SKIPPED', 'sent' => 0, 'duplicate' => true];
    }

    $tokens = fcm_active_user_tokens($uid);
    if ($tokens === []) {
        return ['ok' => true, 'code' => 'FCM_NO_ACTIVE_TOKENS', 'sent' => 0];
    }
    $serviceAccount = fcm_load_service_account();
    $projectId = fcm_project_id($serviceAccount);
    if ($projectId === '' || $serviceAccount === []) {
        return ['ok' => false, 'code' => 'FCM_CONFIG_MISSING', 'sent' => 0];
    }
    $access = fcm_access_token($serviceAccount);
    if (empty($access['ok'])) {
        return ['ok' => false, 'code' => (string)($access['code'] ?? 'FCM_AUTH_FAILED'), 'sent' => 0];
    }

    $sent = 0;
    $failed = 0;
    foreach ($tokens as $row) {
        $result = fcm_send_one($projectId, (string)$access['token'], (string)$row['token'], $title, $body, $data);
        if (!empty($result['ok'])) {
            $sent++;
            continue;
        }
        $failed++;
        if (fcm_error_means_unregister($result)) {
            fcm_deactivate_user_token_hash($uid, (string)$row['token_hash']);
        }
    }
    if ($dedupeHash !== '' && $sent > 0) {
        fb_put('USER_PUSH_DEDUPE/' . $uid . '/' . $dedupeHash, [
            'dedupe_key_hash' => $dedupeHash,
            'type' => fcm_clean_text($data['type'] ?? '', 80),
            'ticket_id' => fcm_clean_text($data['ticket_id'] ?? '', 80),
            'message_id' => fcm_clean_text($data['message_id'] ?? '', 80),
            'sent_count' => $sent,
            'created_at' => fcm_now(),
        ]);
    }
    return ['ok' => $sent > 0 || $failed === 0, 'code' => 'FCM_SEND_COMPLETE', 'sent' => $sent, 'failed' => $failed];
}
