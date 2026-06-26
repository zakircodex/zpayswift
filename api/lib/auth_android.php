<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function auth_app_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtoupper(trim((string)$value)), ['1', 'TRUE', 'YES', 'ON'], true);
}

function auth_app_phone_country(array $body): string
{
    $country = auth_normalize_country_code((string)($body['phone_country'] ?? $body['country'] ?? $body['country_code'] ?? ''));
    if ($country !== '') {
        return $country;
    }

    return detect_phone_country((string)($body['phone'] ?? '')) ?: 'BD';
}

function auth_app_mask_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone)) ?? '';
    $len = strlen($phone);

    if ($len <= 4) {
        return $phone;
    }

    if ($len <= 7) {
        return substr($phone, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($phone, -2);
    }

    return substr($phone, 0, 3) . str_repeat('*', max(1, $len - 6)) . substr($phone, -3);
}

function auth_app_mask_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $first = mb_substr($name, 0, 1);
        return $first . str_repeat('*', max(1, min(6, mb_strlen($name) - 1)));
    }

    return substr($name, 0, 1) . str_repeat('*', max(1, min(6, strlen($name) - 1)));
}

function auth_app_allowed_role(string $role): bool
{
    return in_array(auth_status_value($role), ['USER', 'RETAILER'], true);
}

function auth_app_lookup_user_by_body(array $body): array
{
    $phoneCountry = auth_app_phone_country($body);
    $phone = normalize_phone_by_country((string)($body['phone'] ?? ''), $phoneCountry);

    if ($phone === '') {
        api_response(false, 'VALIDATION_ERROR', auth_phone_validation_message($phoneCountry), [], 422);
    }

    $uid = auth_find_uid_by_phone_country($phone, $phoneCountry);
    if ($uid === '') {
        api_response(false, 'ACCOUNT_NOT_FOUND', 'এই নাম্বারে কোনো অ্যাকাউন্ট পাওয়া যায়নি।', [], 404);
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        api_response(false, 'ACCOUNT_NOT_FOUND', 'এই নাম্বারে কোনো অ্যাকাউন্ট পাওয়া যায়নি।', [], 404);
    }

    $storedPhoneCountry = auth_phone_country_from_user($user);
    if ($storedPhoneCountry !== $phoneCountry) {
        api_response(false, 'ACCOUNT_NOT_FOUND', 'এই নাম্বারে কোনো অ্যাকাউন্ট পাওয়া যায়নি।', [], 404);
    }

    return [
        'uid' => $uid,
        'phone' => normalize_phone_by_country((string)($user['phone'] ?? $phone), $storedPhoneCountry) ?: $phone,
        'phone_country' => $storedPhoneCountry,
        'pricing_country' => auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: [])),
        'user' => $user,
    ];
}

function auth_app_guard_user_login(array $user): void
{
    $status = auth_status_value($user['status'] ?? '');
    $accountStatus = auth_status_value($user['account_status'] ?? $status);
    $role = auth_status_value($user['role'] ?? '');

    if ($accountStatus === 'REVIEW' || $status === 'REVIEW') {
        api_response(false, 'ACCOUNT_REVIEW_REQUIRED', 'Account is pending admin review', [], 403);
    }

    if ($accountStatus === 'BLOCKED' || $status === 'BLOCKED') {
        api_response(false, 'ACCOUNT_BLOCKED', 'Account is blocked', [], 403);
    }

    if ($accountStatus === 'REJECTED' || $status === 'REJECTED') {
        api_response(false, 'ACCOUNT_REJECTED', 'Account registration was rejected', [], 403);
    }

    if ($status !== 'ACTIVE') {
        api_response(false, 'FORBIDDEN', 'User account is not active', [], 403);
    }

    if (!auth_app_allowed_role($role)) {
        api_response(false, 'FORBIDDEN', 'User app access required', [], 403);
    }
}

function auth_app_public_user(string $uid, array $user): array
{
    return [
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'masked_name' => auth_app_mask_name((string)($user['name'] ?? '')),
        'phone' => (string)($user['phone'] ?? ''),
        'account_status' => (string)($user['account_status'] ?? $user['status'] ?? ''),
        'kyc_status' => (string)($user['kyc_status'] ?? $user['KYC']['status'] ?? ''),
        'role' => auth_status_value($user['role'] ?? ''),
    ];
}

function auth_app_create_preauth(string $uid, string $phone, array $body, array $extra = []): string
{
    $token = 'ULPA' . strtoupper(bin2hex(random_bytes(16)));
    $now = now_ts();
    $expiresAt = $now + 600;

    $row = [
        'pre_auth_token' => $token,
        'uid' => $uid,
        'phone' => $phone,
        'phone_country' => (string)($extra['phone_country'] ?? ''),
        'pricing_country' => (string)($extra['pricing_country'] ?? ''),
        'password_verified' => !empty($extra['password_verified']),
        'pin_verified' => !empty($extra['pin_verified']),
        'otp_verified' => false,
        'status' => (string)($extra['status'] ?? 'PASSWORD_VERIFIED'),
        'device_id' => trim((string)($body['device_id'] ?? 'ANDROID_APP')),
        'device_name' => trim((string)($body['device_name'] ?? 'Android App')),
        'app_version' => trim((string)($body['app_version'] ?? '')),
        'created_ip' => auth_request_ip($body),
        'ip_country' => auth_request_ip_country($body),
        'user_agent' => auth_request_user_agent($body),
        'browser_timezone' => auth_request_browser_timezone($body),
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $expiresAt,
    ];

    if (!fb_put('AUTH_LOGIN_PREAUTH/' . $token, $row)) {
        api_response(false, 'SERVER_ERROR', 'Failed to create login verification state', [], 500);
    }

    return $token;
}

function auth_app_get_valid_preauth(string $preAuthToken): array
{
    $preAuthToken = trim($preAuthToken);
    if ($preAuthToken === '') {
        api_response(false, 'VALIDATION_ERROR', 'pre_auth_token is required', [], 422);
    }

    $row = fb_get('AUTH_LOGIN_PREAUTH/' . $preAuthToken);
    if (!is_array($row)) {
        api_response(false, 'PREAUTH_NOT_FOUND', 'Login verification session expired.', [], 404);
    }

    if ((int)($row['expires_at'] ?? 0) <= now_ts()) {
        @fb_patch('AUTH_LOGIN_PREAUTH/' . $preAuthToken, [
            'status' => 'EXPIRED',
            'updated_at' => now_ts(),
        ]);
        api_response(false, 'PREAUTH_EXPIRED', 'Login verification session expired.', [], 410);
    }

    $row['pre_auth_token'] = $preAuthToken;
    return $row;
}

function auth_app_issue_session(array $user, string $uid, string $deviceId, string $deviceName, array $meta = []): array
{
    $token = random_token(32);
    $hash = session_hash($token);
    $now = now_ts();

    $session = [
        'session_id' => make_session_id(),
        'uid' => $uid,
        'phone' => (string)($user['phone'] ?? ''),
        'token_last8' => substr($token, -8),
        'device_name' => $deviceName,
        'device_id' => $deviceId,
        'status' => 'ACTIVE',
        'ip' => client_ip(),
        'created_at' => $now,
        'expires_at' => $now + SESSION_TTL_SECONDS,
        'last_seen_at' => $now,
    ];

    if (!fb_put('USER_SESSIONS/' . $hash, $session)) {
        api_response(false, 'SERVER_ERROR', 'Failed to create session', [], 500);
    }

    @fb_patch('USERS/' . $uid, [
        'last_login_at' => $now,
        'last_login_ip' => (string)($meta['created_ip'] ?? auth_request_ip([])),
        'last_login_ip_country' => (string)($meta['ip_country'] ?? auth_request_ip_country([])),
        'last_login_user_agent' => (string)($meta['user_agent'] ?? ''),
        'browser_timezone' => (string)($meta['browser_timezone'] ?? ''),
        'updated_at' => $now,
    ]);

    auth_activate_user_device($uid, $deviceId, $deviceName, (string)($meta['app_version'] ?? ''), $hash);

    return [
        'session_token' => $token,
        'session_hash' => $hash,
    ];
}

function auth_app_user_payload(string $uid, array $user): array
{
    return [
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => auth_status_value($user['role'] ?? ''),
        'status' => auth_status_value($user['status'] ?? ''),
        'account_status' => auth_status_value($user['account_status'] ?? $user['status'] ?? ''),
        'phone_country' => auth_phone_country_from_user($user),
        'pricing_country' => auth_pricing_country_from_user($user, (array)(fb_get('USER_WALLETS/' . $uid) ?: [])),
    ];
}

function auth_app_password_ok(array $user, string $password): bool
{
    $hash = trim((string)($user['password_hash'] ?? ''));
    return $hash !== '' && $password !== '' && password_verify($password, $hash);
}

function auth_app_pin_ok(array $user, string $pin): bool
{
    $hash = trim((string)($user['pin_hash'] ?? ''));
    return $hash !== '' && $pin !== '' && password_verify($pin, $hash);
}

function auth_app_device_id(array $body, string $fallback = 'ANDROID_APP'): string
{
    $deviceId = auth_clean_string($body['device_id'] ?? '');
    return $deviceId !== '' ? $deviceId : $fallback;
}

function auth_app_device_name(array $body, string $fallback = 'Android App'): string
{
    $deviceName = trim((string)($body['device_name'] ?? ''));
    return $deviceName !== '' ? $deviceName : $fallback;
}

function auth_app_preauth_user(array $preAuthRow): array
{
    $uid = trim((string)($preAuthRow['uid'] ?? ''));
    if ($uid === '') {
        api_response(false, 'PREAUTH_INVALID', 'Login verification session is invalid.', [], 400);
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        api_response(false, 'ACCOUNT_NOT_FOUND', 'Account not found.', [], 404);
    }

    auth_app_guard_user_login($user);

    return [
        'uid' => $uid,
        'user' => $user,
    ];
}

function auth_app_trusted_login_allowed(string $uid, string $deviceId): bool
{
    $deviceId = auth_clean_string($deviceId);
    if ($uid === '' || $deviceId === '') {
        return false;
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        return false;
    }

    $activeDeviceId = auth_clean_string($user['active_device_id'] ?? $user['ACTIVE_DEVICE_ID'] ?? '');
    if ($activeDeviceId === '' || $activeDeviceId !== $deviceId) {
        return false;
    }

    return auth_device_is_trusted($uid, $deviceId);
}

function auth_app_revoke_user_sessions_and_trust(string $uid): void
{
    $uid = auth_clean_string($uid);
    if ($uid === '') {
        return;
    }

    $sessions = fb_get('USER_SESSIONS');
    if (is_array($sessions)) {
        foreach ($sessions as $hash => $session) {
            if (is_array($session) && (string)($session['uid'] ?? '') === $uid) {
                @fb_patch('USER_SESSIONS/' . $hash, [
                    'status' => 'REVOKED',
                    'updated_at' => now_ts(),
                ]);
            }
        }
    }

    $devices = fb_get('AUTH_DEVICE_TRUST/' . $uid);
    if (is_array($devices)) {
        foreach ($devices as $deviceKey => $row) {
            @fb_patch('AUTH_DEVICE_TRUST/' . $uid . '/' . (string)$deviceKey, [
                'trusted' => false,
                'manual_logout' => true,
                'revoked' => true,
                'status' => 'RESET_REVOKED',
                'updated_at' => now_ts(),
            ]);
        }
    }

    $legacyDevices = fb_get('AUTH_TRUSTED_DEVICES/' . $uid);
    if (is_array($legacyDevices)) {
        foreach ($legacyDevices as $selector => $row) {
            @fb_patch('AUTH_TRUSTED_DEVICES/' . $uid . '/' . (string)$selector, [
                'status' => 'REVOKED',
                'revoked' => true,
                'manual_logout' => true,
                'updated_at' => now_ts(),
            ]);
        }
    }

    @fb_patch('USERS/' . $uid, [
        'active_device_id' => '',
        'ACTIVE_DEVICE_ID' => '',
        'updated_at' => now_ts(),
    ]);
}

function auth_app_identity_number(array $body): string
{
    return trim((string)(
        $body['nid_or_passport_number']
        ?? $body['identity_number']
        ?? $body['nid_or_passport']
        ?? $body['identity']
        ?? $body['nid']
        ?? $body['passport']
        ?? ''
    ));
}

function auth_app_identity_type(array $body): string
{
    $type = strtoupper(trim((string)(
        $body['nid_or_passport_type']
        ?? $body['identity_type']
        ?? ''
    )));

    return in_array($type, ['NID', 'PASSPORT'], true) ? $type : 'NID';
}

function auth_app_identity_hash(string $number): string
{
    $clean = strtoupper(preg_replace('/\s+/', '', trim($number)) ?? '');
    return $clean === '' ? '' : hash('sha256', $clean);
}

function auth_app_identity_last4(string $number): string
{
    $clean = preg_replace('/\s+/', '', trim($number)) ?? '';
    return $clean === '' ? '' : substr($clean, -4);
}

function auth_app_identity_match_state(array $user, string $identityNumber): array
{
    $hash = auth_app_identity_hash($identityNumber);
    if ($hash === '') {
        return ['configured' => false, 'match' => false];
    }

    $candidates = [
        (string)($user['identity_number_hash'] ?? ''),
        (string)($user['nid_or_passport_hash'] ?? ''),
        (string)($user['nid_hash'] ?? ''),
        (string)($user['passport_hash'] ?? ''),
        (string)($user['KYC']['identity_number_hash'] ?? ''),
        (string)($user['KYC']['nid_or_passport_hash'] ?? ''),
    ];

    $configured = false;
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        $configured = true;
        if (hash_equals($candidate, $hash)) {
            return ['configured' => true, 'match' => true];
        }
    }

    return ['configured' => $configured, 'match' => false];
}
