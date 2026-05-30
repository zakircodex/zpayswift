<?php
declare(strict_types=1);

require_once '/home/zedpayhe/private/zawtopup/config.php';
require_once '/home/zedpayhe/public_html/zawtopup/api/bootstrap.php';
require_once '/home/zedpayhe/public_html/zawtopup/api/lib/subadmin_api.php';
require_once '/home/zedpayhe/public_html/zawtopup/api/lib/bundle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('zawtopup_subadmin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function sub_proxy_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sub_proxy_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        sub_proxy_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function sub_proxy_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        sub_proxy_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function sub_proxy_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function sub_proxy_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function sub_proxy_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/zawtopup/api/subadmin/proxy.php';
    $apiPath = dirname(dirname($script));
    return rtrim(sub_proxy_scheme() . '://' . sub_proxy_host() . $apiPath, '/');
}

function sub_proxy_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = sub_proxy_api_base_url() . '/' . ltrim($relativePath, '/');

    $ch = curl_init();
    $finalHeaders = ['Accept: application/json'];

    foreach ($headers as $k => $v) {
        $finalHeaders[] = $k . ': ' . $v;
    }

    if ($body !== null) {
        $finalHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $finalHeaders,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 0,
            'json' => null,
            'error' => $err ?: 'Unknown cURL error',
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'status' => $status,
            'json' => null,
            'error' => 'Invalid JSON response from internal API',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
        'error' => null,
    ];
}

function sub_proxy_store_session(string $sessionToken, array $user): void
{
    session_regenerate_id(true);

    $_SESSION['subadmin_session_token'] = $sessionToken;
    $_SESSION['subadmin_user'] = [
        'uid' => (string) ($user['uid'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'phone' => (string) ($user['phone'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'status' => (string) ($user['status'] ?? ''),
    ];
    $_SESSION['subadmin_csrf'] = bin2hex(random_bytes(32));
}

function sub_proxy_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $value = strtoupper(trim((string) $value));
    return in_array($value, ['1', 'TRUE', 'YES', 'ON'], true);
}

function sub_proxy_finalize_login_with_session_token(string $sessionToken): array
{
    if ($sessionToken === '') {
        sub_proxy_response(false, 'SERVER_ERROR', 'Session token missing after login', [], 500);
    }

    $sessionRes = sub_proxy_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$sessionRes['ok']) {
        sub_proxy_response(false, 'SESSION_EXPIRED', 'Failed to verify session', [], 401);
    }

    $user = (array) ($sessionRes['json']['data'] ?? []);
    $role = strtoupper(trim((string) ($user['role'] ?? '')));
    $status = strtoupper(trim((string) ($user['status'] ?? 'INACTIVE')));

    if (!sub_proxy_allowed_role($role)) {
        sub_proxy_internal_api_request('POST', 'auth/logout.php', [], [
            'X-APP-KEY' => APP_KEY,
            'X-SESSION-TOKEN' => $sessionToken,
        ]);
        sub_proxy_response(false, 'FORBIDDEN', 'Only SUBADMIN or ADMIN can access this panel', [], 403);
    }

    if ($status !== 'ACTIVE') {
        sub_proxy_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    sub_proxy_store_session($sessionToken, $user);

    return $user;
}

function sub_proxy_clear_session(): void
{
    unset($_SESSION['subadmin_session_token'], $_SESSION['subadmin_user'], $_SESSION['subadmin_csrf']);
}

function sub_proxy_get_session_token(): string
{
    return trim((string) ($_SESSION['subadmin_session_token'] ?? ''));
}

function sub_proxy_get_csrf(): string
{
    return trim((string) ($_SESSION['subadmin_csrf'] ?? ''));
}

function sub_proxy_set_trust_cookie(array $cookieData): void
{
    $selector = trim((string)($cookieData['selector'] ?? ''));
    $token = trim((string)($cookieData['token'] ?? ''));
    $expiresAt = (int)($cookieData['expires_at'] ?? 0);

    if ($selector === '' || $token === '' || $expiresAt <= time()) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    setcookie('zaw_subadmin_trust', $selector . ':' . $token, [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function sub_proxy_require_csrf(): void
{
    $incoming = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $stored = sub_proxy_get_csrf();

    if ($stored === '' || $incoming === '' || !hash_equals($stored, $incoming)) {
        sub_proxy_response(false, 'FORBIDDEN', 'Invalid CSRF token', [], 403);
    }
}

function sub_proxy_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['SUBADMIN', 'ADMIN'], true);
}

function sub_proxy_require_login(bool $touch = true): array
{
    $token = sub_proxy_get_session_token();
    if ($token === '') {
        sub_proxy_response(false, 'SESSION_EXPIRED', 'Subadmin session not found', [], 401);
    }

    $headers = [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $token,
    ];

    $res = sub_proxy_internal_api_request('GET', 'auth/session.php', null, $headers);

    if (!$res['ok']) {
        sub_proxy_clear_session();
        $msg = $res['json']['message'] ?? 'Session expired';
        sub_proxy_response(false, 'SESSION_EXPIRED', (string) $msg, [], 401);
    }

    $data = $res['json']['data'] ?? [];
    $role = strtoupper(trim((string) ($data['role'] ?? '')));
    $status = strtoupper(trim((string) ($data['status'] ?? 'INACTIVE')));

    if (!sub_proxy_allowed_role($role)) {
        if ($token !== '') {
            sub_proxy_internal_api_request('POST', 'auth/logout.php', [], $headers);
        }
        sub_proxy_clear_session();
        sub_proxy_response(false, 'FORBIDDEN', 'Subadmin access required', [], 403);
    }

    if ($status !== 'ACTIVE') {
        sub_proxy_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    if ($touch) {
        $_SESSION['subadmin_user'] = $data;
        if (sub_proxy_get_csrf() === '') {
            $_SESSION['subadmin_csrf'] = bin2hex(random_bytes(32));
        }
    }

    return $data;
}

function sub_proxy_forward_internal_post(string $relativePath, array $body, string $defaultCode, string $defaultMessage): void
{
    sub_proxy_require_login(true);
    $sessionToken = sub_proxy_get_session_token();

    $res = sub_proxy_internal_api_request(
        'POST',
        $relativePath,
        $body,
        [
            'X-APP-KEY' => APP_KEY,
            'X-SESSION-TOKEN' => $sessionToken,
        ]
    );

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        sub_proxy_response(
            false,
            (string) ($json['code'] ?? $defaultCode),
            (string) ($json['message'] ?? $defaultMessage),
            (array) ($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 400
        );
    }

    sub_proxy_response(
        true,
        'SUCCESS',
        (string) (($res['json']['message'] ?? '') ?: 'Success'),
        (array) ($res['json']['data'] ?? [])
    );
}

function sub_proxy_forward_internal_get(string $relativePath, string $defaultCode, string $defaultMessage): void
{
    sub_proxy_require_login(true);
    $sessionToken = sub_proxy_get_session_token();

    $res = sub_proxy_internal_api_request(
        'GET',
        $relativePath,
        null,
        [
            'X-APP-KEY' => APP_KEY,
            'X-SESSION-TOKEN' => $sessionToken,
        ]
    );

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        sub_proxy_response(
            false,
            (string) ($json['code'] ?? $defaultCode),
            (string) ($json['message'] ?? $defaultMessage),
            (array) ($json['data'] ?? []),
            $res['status'] > 0 ? $res['status'] : 400
        );
    }

    sub_proxy_response(
        true,
        'SUCCESS',
        (string) (($res['json']['message'] ?? '') ?: 'Success'),
        (array) ($res['json']['data'] ?? [])
    );
}


function sub_proxy_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function sub_proxy_make_id(string $prefix = 'BR'): string
{
    if ($prefix === 'BR' && function_exists('bundle_make_request_id')) {
        return (string)bundle_make_request_id();
    }

    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function sub_proxy_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function sub_proxy_load_user_row(string $uid): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }

    if (function_exists('subapi_load_user')) {
        $row = subapi_load_user($uid);
        return is_array($row) ? $row : [];
    }

    $row = fb_get('USERS/' . $uid);
    return is_array($row) ? $row : [];
}

function sub_proxy_load_wallet_row(string $uid): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }

    if (function_exists('subapi_load_wallet')) {
        $row = subapi_load_wallet($uid);
        return is_array($row) ? $row : [];
    }

    $row = fb_get('USER_WALLETS/' . $uid);
    return is_array($row) ? $row : [];
}

function sub_proxy_load_role_settings_row(string $uid): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }

    if (function_exists('subapi_load_role_settings')) {
        $row = subapi_load_role_settings($uid);
        return is_array($row) ? $row : [];
    }

    $row = fb_get('USER_ROLE_SETTINGS/' . $uid);
    return is_array($row) ? $row : [];
}

function sub_proxy_bundle_price(array $offer): float
{
    if (function_exists('bundle_offer_price')) {
        return bundle_round_money((float)bundle_offer_price($offer));
    }

    return round((float)(
        $offer['price_amount']
        ?? $offer['offer_price']
        ?? $offer['price']
        ?? $offer['amount']
        ?? 0
    ), 2);
}

function sub_proxy_round_money(float $amount): float
{
    if (function_exists('bundle_round_money')) {
        return bundle_round_money($amount);
    }

    return round($amount, 2);
}

function sub_proxy_hold_balance_for_bundle_payable(string $uid, float $payableAmount, string $requestId): array
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $payableAmount = sub_proxy_round_money($payableAmount);

    if ($uid === '' || $requestId === '' || $payableAmount <= 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid bundle hold data',
            'data' => [],
        ];
    }

    $wallet = sub_proxy_load_wallet_row($uid);
    $now = sub_proxy_now();

    $available = sub_proxy_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = sub_proxy_round_money((float)($wallet['hold_balance'] ?? 0));

    if ($available < $payableAmount) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient available balance',
            'data' => [
                'available_balance' => $available,
                'required_amount' => $payableAmount,
            ],
        ];
    }

    $newAvailable = sub_proxy_round_money($available - $payableAmount);
    $newHold = sub_proxy_round_money($hold + $payableAmount);

    $ok = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to hold bundle payable amount',
            'data' => [],
        ];
    }

    $ledgerId = sub_proxy_make_id('WLB');
    $month = date('Y-m', $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'SUBADMIN_PANEL_BUNDLE_HOLD',
        'direction' => 'HOLD',
        'amount' => $payableAmount,
        'currency' => 'BDT',
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => 'Balance held for subadmin panel bundle payable amount',
        'created_at' => $now,
        'created_by_uid' => $uid,
        'created_by_role' => 'SUBADMIN',
    ]);

    return [
        'ok' => true,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
    ];
}

function sub_proxy_release_bundle_hold_rollback(string $uid, float $payableAmount, string $requestId): void
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $payableAmount = sub_proxy_round_money($payableAmount);

    if ($uid === '' || $requestId === '' || $payableAmount <= 0) {
        return;
    }

    $wallet = sub_proxy_load_wallet_row($uid);
    $now = sub_proxy_now();

    $available = sub_proxy_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = sub_proxy_round_money((float)($wallet['hold_balance'] ?? 0));

    $newAvailable = sub_proxy_round_money($available + $payableAmount);
    $newHold = sub_proxy_round_money(max(0, $hold - $payableAmount));

    fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'updated_at' => $now,
    ]);

    $ledgerId = sub_proxy_make_id('WLB');
    $month = date('Y-m', $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'SUBADMIN_PANEL_BUNDLE_HOLD_ROLLBACK',
        'direction' => 'RELEASE_HOLD',
        'amount' => $payableAmount,
        'currency' => 'BDT',
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => 'Bundle hold rollback after request create failure',
        'created_at' => $now,
        'created_by_uid' => $uid,
        'created_by_role' => 'SUBADMIN',
    ]);
}

function sub_proxy_create_bundle_request_status(string $requestId, string $uid, string $status, string $message): void
{
    fb_put('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'type' => 'BUNDLE',
        'uid' => $uid,
        'status' => $status,
        'message' => $message,
        'updated_at' => sub_proxy_now(),
    ]);
}

function sub_proxy_log_panel_bundle_request(array $row): void
{
    $uid = trim((string)($row['uid'] ?? ''));
    $requestId = trim((string)($row['request_id'] ?? ''));

    if ($uid === '' || $requestId === '') {
        return;
    }

    $now = sub_proxy_now();

    fb_put('USER_API_REQUESTS/' . $uid . '/' . $requestId, [
        'request_id' => $requestId,
        'uid' => $uid,
        'key_id' => 'PANEL',
        'action' => 'BUNDLE_CREATE',
        'request_type' => 'BUNDLE',
        'source' => 'SUBADMIN_PANEL',
        'request_source' => 'SUBADMIN_PANEL',
        'status' => 'WAITING_ADMIN',
        'operator' => (string)($row['operator'] ?? ''),
        'bundle_number' => (string)($row['bundle_number'] ?? ''),
        'topup_number' => (string)($row['bundle_number'] ?? ''),
        'number' => (string)($row['bundle_number'] ?? ''),
        'offer_id' => (string)($row['offer_id'] ?? ''),
        'bundle_name' => (string)($row['bundle_name'] ?? ''),
        'amount' => (float)($row['amount'] ?? 0),
        'price_amount' => (float)($row['price_amount'] ?? $row['amount'] ?? 0),
        'offer_price' => (float)($row['offer_price'] ?? $row['amount'] ?? 0),
        'you_pay' => (float)($row['you_pay'] ?? $row['payable_amount'] ?? 0),
        'payable_amount' => (float)($row['payable_amount'] ?? $row['you_pay'] ?? 0),
        'wallet_hold_amount' => (float)($row['wallet_hold_amount'] ?? $row['payable_amount'] ?? 0),

        /*
         * customer_commission/user_discount is the discount given to customer.
         * user_commission is kept 0 in the request row so success does not credit it again.
         */
        'customer_commission' => (float)($row['customer_commission'] ?? 0),
        'user_discount' => (float)($row['user_discount'] ?? 0),
        'admin_commission' => (float)($row['admin_commission'] ?? 0),
        'user_commission' => (float)($row['customer_commission'] ?? 0),
        'credit_user_commission' => 0,
        'subadmin_profit' => (float)($row['subadmin_profit'] ?? 0),

        'commission_status' => 'PENDING',
        'message' => (string)($row['message'] ?? 'Bundle request created from subadmin panel'),
        'created_at' => (int)($row['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
}

function sub_proxy_create_panel_bundle_fixed(string $uid, string $offerId, string $bundleNumber, string $note = ''): array
{
    $uid = trim($uid);
    $offerId = trim($offerId);
    $bundleNumber = sub_proxy_normalize_phone($bundleNumber);
    $note = trim($note);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'SESSION_EXPIRED',
            'message' => 'Subadmin session not found',
            'data' => [],
        ];
    }

    if ($offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'offer_id is required',
            'data' => [],
        ];
    }

    if ($bundleNumber === '' || strlen($bundleNumber) < 10) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid bundle_number is required',
            'data' => [],
        ];
    }

    $userRow = sub_proxy_load_user_row($uid);
    if (!$userRow) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'Subadmin user not found',
            'data' => [],
        ];
    }

    $userRow['uid'] = (string)($userRow['uid'] ?? $uid);

    $role = strtoupper(trim((string)($userRow['role'] ?? '')));
    $status = strtoupper(trim((string)($userRow['status'] ?? 'INACTIVE')));

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!in_array($role, ['SUBADMIN', 'ADMIN'], true)) {
        return [
            'ok' => false,
            'code' => 'ROLE_NOT_ALLOWED',
            'message' => 'Only SUBADMIN or ADMIN can create panel bundle request',
            'data' => [],
        ];
    }

    $roleSettings = sub_proxy_load_role_settings_row($uid);
    if (!(bool)($roleSettings['bundle_enabled'] ?? true)) {
        return [
            'ok' => false,
            'code' => 'BUNDLE_DISABLED',
            'message' => 'Bundle service is disabled for this account',
            'data' => [
                'bundle_enabled' => false,
            ],
        ];
    }

    if (function_exists('bundle_expire_old_offers')) {
        bundle_expire_old_offers();
    }

    if (!function_exists('bundle_load_offer') || !function_exists('bundle_is_active_offer')) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Bundle helper not loaded',
            'data' => [],
        ];
    }

    $baseOffer = bundle_load_offer($offerId);
    if (!is_array($baseOffer) || empty($baseOffer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    $baseOffer['offer_id'] = (string)($baseOffer['offer_id'] ?? $offerId);

    if (!bundle_is_active_offer($baseOffer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_INACTIVE',
            'message' => 'Bundle offer is inactive or expired',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    $operator = function_exists('normalize_operator')
        ? (string)normalize_operator((string)($baseOffer['operator'] ?? ''))
        : strtoupper(trim((string)($baseOffer['operator'] ?? '')));

    $bundleName = trim((string)($baseOffer['bundle_name'] ?? $baseOffer['name'] ?? ''));
    $priceAmount = sub_proxy_bundle_price($baseOffer);
    $adminCommission = sub_proxy_round_money((float)($baseOffer['admin_commission'] ?? 0));

    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($priceAmount > 0 && $adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    if ($operator === '' || $bundleName === '' || $priceAmount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Bundle offer data is invalid',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    /*
     * Correct financial rule:
     *
     * Admin Offer Price = 800
     * Admin Commission Pool = 50
     *
     * Default:
     * Customer/User Commission = 50
     * User Pay = 750
     * Subadmin Profit = 0
     *
     * Custom:
     * Customer/User Commission = 30
     * User Pay = 770
     * Subadmin Profit = 20
     */
    $custom = [];
    if (function_exists('bundle_load_subadmin_custom_offer')) {
        $loadedCustom = bundle_load_subadmin_custom_offer($uid, $offerId);
        $custom = is_array($loadedCustom) ? $loadedCustom : [];
    }

    $customized = false;
    $customerCommission = $adminCommission;

    if (!empty($custom)) {
        $customActive = (bool)($custom['active'] ?? true);
        $customStatus = strtoupper(trim((string)($custom['status'] ?? 'ACTIVE')));

        if ($customActive && $customStatus === 'ACTIVE') {
            $customized = true;
            $customerCommission = sub_proxy_round_money((float)($custom['user_commission'] ?? $adminCommission));
        }
    }

    if ($customerCommission < 0) {
        $customerCommission = 0.0;
    }

    if ($customerCommission > $adminCommission) {
        $customerCommission = $adminCommission;
    }

    $subadminProfit = sub_proxy_round_money($adminCommission - $customerCommission);
    $payableAmount = sub_proxy_round_money($priceAmount - $customerCommission);

    if ($payableAmount < 0) {
        $payableAmount = 0.0;
    }

    if ($payableAmount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Bundle payable amount is invalid',
            'data' => [
                'offer_id' => $offerId,
                'price_amount' => $priceAmount,
                'customer_commission' => $customerCommission,
                'payable_amount' => $payableAmount,
            ],
        ];
    }

    $requestId = sub_proxy_make_id('BR');
    $now = sub_proxy_now();
    $userPhone = trim((string)($userRow['phone'] ?? ''));

    $hold = sub_proxy_hold_balance_for_bundle_payable($uid, $payableAmount, $requestId);
    if (!($hold['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($hold['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($hold['message'] ?? 'Failed to hold balance'),
            'data' => (array)($hold['data'] ?? []),
        ];
    }

    /*
     * Important:
     * user_commission is intentionally 0 in the saved request row,
     * because customer commission is already deducted from payable amount.
     * Otherwise success would credit user_commission again.
     */
    $extra = [
        'offer_id' => $offerId,
        'offer_source' => 'SUBADMIN_PANEL',
        'source' => 'SUBADMIN_PANEL',
        'request_source' => 'SUBADMIN_PANEL',
        'created_from_subadmin_panel' => true,
        'created_from_api' => false,
        'key_id' => 'PANEL',
        'source_key_id' => 'PANEL',

        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,

        'admin_commission' => $adminCommission,
        'user_commission' => 0,
        'customer_commission' => $customerCommission,
        'user_discount' => $customerCommission,
        'credit_user_commission' => false,

        'subadmin_profit' => $subadminProfit,
        'subadmin_uid' => $uid,
        'customized_by_subadmin' => $customized,

        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'wallet_hold_amount' => $payableAmount,
        'held_amount' => $payableAmount,

        'hold_settled_at' => 0,
        'hold_settlement_status' => 'PENDING',
    ];

    $requestSaved = false;

    if (function_exists('create_bundle_pending_request')) {
        $requestSaved = create_bundle_pending_request(
            $requestId,
            $uid,
            $userPhone,
            $bundleNumber,
            $operator,
            $bundleName,
            $priceAmount,
            $note !== '' ? $note : 'Bundle request created from subadmin panel',
            false,
            '',
            $extra
        );
    } else {
        $requestSaved = fb_put('BUNDLE_REQUESTS/PENDING/' . $requestId, [
            'request_id' => $requestId,
            'uid' => $uid,
            'user_phone' => $userPhone,
            'bundle_number' => $bundleNumber,
            'operator' => $operator,
            'bundle_name' => $bundleName,

            'amount' => $priceAmount,
            'price_amount' => $priceAmount,
            'offer_price' => $priceAmount,
            'you_pay' => $payableAmount,
            'payable_amount' => $payableAmount,

            'note' => $note !== '' ? $note : 'Bundle request created from subadmin panel',
            'wallet_hold_amount' => $payableAmount,
            'held_amount' => $payableAmount,
            'hold_settled_at' => 0,
            'hold_settlement_status' => 'PENDING',
            'status' => 'WAITING_ADMIN',
            'telegram_sent' => false,
            'telegram_queue_id' => '',

            'offer_id' => $offerId,
            'offer_source' => 'SUBADMIN_PANEL',
            'source' => 'SUBADMIN_PANEL',
            'request_source' => 'SUBADMIN_PANEL',
            'created_from_subadmin_panel' => true,
            'created_from_api' => false,
            'key_id' => 'PANEL',
            'source_key_id' => 'PANEL',

            'admin_commission' => $adminCommission,
            'user_commission' => 0,
            'customer_commission' => $customerCommission,
            'user_discount' => $customerCommission,
            'credit_user_commission' => false,
            'subadmin_profit' => $subadminProfit,
            'subadmin_uid' => $uid,
            'customized_by_subadmin' => $customized,

            'commission_status' => 'PENDING',
            'commission_credited_at' => 0,
            'user_commission_credited' => false,
            'subadmin_profit_credited' => false,

            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    if (!$requestSaved) {
        sub_proxy_release_bundle_hold_rollback($uid, $payableAmount, $requestId);

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create bundle request',
            'data' => [],
        ];
    }

    $logRow = [
        'request_id' => $requestId,
        'uid' => $uid,
        'operator' => $operator,
        'bundle_number' => $bundleNumber,
        'offer_id' => $offerId,
        'bundle_name' => $bundleName,
        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'wallet_hold_amount' => $payableAmount,
        'admin_commission' => $adminCommission,
        'customer_commission' => $customerCommission,
        'user_discount' => $customerCommission,
        'subadmin_profit' => $subadminProfit,
        'message' => 'Bundle request created from subadmin panel',
        'created_at' => $now,
    ];

    sub_proxy_create_bundle_request_status(
        $requestId,
        $uid,
        'WAITING_ADMIN',
        'Bundle request created from subadmin panel'
    );

    sub_proxy_log_panel_bundle_request($logRow);

    if (function_exists('system_log')) {
        system_log('SUBADMIN_PANEL_BUNDLE_CREATE', $requestId, 'Subadmin created bundle request from panel', [
            'uid' => $uid,
            'offer_id' => $offerId,
            'operator' => $operator,
            'bundle_number' => $bundleNumber,
            'bundle_name' => $bundleName,
            'price_amount' => $priceAmount,
            'customer_commission' => $customerCommission,
            'payable_amount' => $payableAmount,
            'subadmin_profit' => $subadminProfit,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle request created successfully',
        'data' => [
            'request_id' => $requestId,
            'uid' => $uid,
            'status' => 'WAITING_ADMIN',
            'offer_id' => $offerId,
            'operator' => $operator,
            'bundle_number' => $bundleNumber,
            'bundle_name' => $bundleName,

            'amount' => $priceAmount,
            'price_amount' => $priceAmount,
            'offer_price' => $priceAmount,

            'user_commission' => $customerCommission,
            'customer_commission' => $customerCommission,
            'user_discount' => $customerCommission,
            'admin_commission' => $adminCommission,
            'subadmin_profit' => $subadminProfit,

            'you_pay' => $payableAmount,
            'payable_amount' => $payableAmount,
            'wallet_hold_amount' => $payableAmount,
            'held_amount' => $payableAmount,

            'customized_by_subadmin' => $customized,
            'created_at' => $now,
            'wallet' => [
                'available_balance' => (float)($hold['after_available'] ?? 0),
                'hold_balance' => (float)($hold['after_hold'] ?? 0),
            ],
        ],
    ];
}



$action = trim((string) ($_GET['action'] ?? ''));

switch ($action) {
    case 'login':
        sub_proxy_require_method('POST');

        $body = sub_proxy_read_json_body();
        $phone = trim((string) ($body['phone'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $trustDevice = sub_proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string) ($body['device_id'] ?? 'SUBADMIN_WEB'));
        $deviceName = trim((string) ($body['device_name'] ?? 'Subadmin Panel'));

        if ($phone === '' || $password === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'Phone and password are required', [], 422);
        }

        $trustedDeviceCookie = trim((string)($_COOKIE['zaw_subadmin_trust'] ?? ''));

$loginRes = sub_proxy_internal_api_request('POST', 'auth/login_start.php', [
    'phone' => $phone,
    'password' => $password,
    'device_id' => $deviceId,
    'device_name' => $deviceName,
    'trust_device' => $trustDevice,
    'trusted_device_cookie' => $trustedDeviceCookie,
], [
    'X-APP-KEY' => APP_KEY,
]);

        if (!$loginRes['ok']) {
            $json = $loginRes['json'] ?? [];
            sub_proxy_response(
                false,
                (string) ($json['code'] ?? 'LOGIN_FAILED'),
                (string) ($json['message'] ?? 'Login failed'),
                (array) ($json['data'] ?? []),
                $loginRes['status'] > 0 ? $loginRes['status'] : 401
            );
        }

        $data = (array) ($loginRes['json']['data'] ?? []);

        if (!empty($data['require_otp'])) {
            sub_proxy_response(
                true,
                'OTP_REQUIRED',
                (string) ($loginRes['json']['message'] ?? 'OTP verification required'),
                [
                    'require_otp' => true,
                    'pre_auth_token' => (string) ($data['pre_auth_token'] ?? ''),
                    'otp_request_id' => (string) ($data['otp_request_id'] ?? ''),
                    'masked_phone' => (string) ($data['masked_phone'] ?? ''),
                    'expires_in_seconds' => (int) ($data['expires_in_seconds'] ?? 300),
                ]
            );
        }

        $sessionToken = trim((string) ($data['session_token'] ?? ''));
        sub_proxy_finalize_login_with_session_token($sessionToken);

        sub_proxy_response(true, 'SUCCESS', 'Login successful', [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
            'user' => $_SESSION['subadmin_user'],
            'csrf' => sub_proxy_get_csrf(),
        ]);
        break;

    case 'login_verify_otp':
        sub_proxy_require_method('POST');

        $body = sub_proxy_read_json_body();
        $preAuthToken = trim((string) ($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string) ($body['otp_request_id'] ?? ''));
        $otp = trim((string) ($body['otp'] ?? ''));
        $trustDevice = sub_proxy_bool_value($body['trust_device'] ?? true);
        $deviceId = trim((string) ($body['device_id'] ?? 'SUBADMIN_WEB'));
        $deviceName = trim((string) ($body['device_name'] ?? 'Subadmin Panel'));

        if ($preAuthToken === '' || $otpRequestId === '' || $otp === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token, otp_request_id and otp are required', [], 422);
        }

        $verifyRes = sub_proxy_internal_api_request('POST', 'auth/login_verify_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
            'otp' => $otp,
            'trust_device' => $trustDevice,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$verifyRes['ok']) {
            $json = $verifyRes['json'] ?? [];
            sub_proxy_response(
                false,
                (string) ($json['code'] ?? 'OTP_VERIFY_FAILED'),
                (string) ($json['message'] ?? 'OTP verification failed'),
                (array) ($json['data'] ?? []),
                $verifyRes['status'] > 0 ? $verifyRes['status'] : 400
            );
        }

        $data = (array) ($verifyRes['json']['data'] ?? []);
        $sessionToken = trim((string) ($data['session_token'] ?? ''));
        sub_proxy_finalize_login_with_session_token($sessionToken);

        if (!empty($data['trusted_device_cookie']) && is_array($data['trusted_device_cookie'])) {
            sub_proxy_set_trust_cookie($data['trusted_device_cookie']);
        }

        sub_proxy_response(true, 'SUCCESS', 'OTP verified successfully', [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
            'user' => $_SESSION['subadmin_user'],
            'csrf' => sub_proxy_get_csrf(),
        ]);
        break;

    case 'login_resend_otp':
        sub_proxy_require_method('POST');

        $body = sub_proxy_read_json_body();
        $preAuthToken = trim((string) ($body['pre_auth_token'] ?? ''));
        $otpRequestId = trim((string) ($body['otp_request_id'] ?? ''));

        if ($preAuthToken === '' || $otpRequestId === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'pre_auth_token and otp_request_id are required', [], 422);
        }

        $resendRes = sub_proxy_internal_api_request('POST', 'auth/login_resend_otp.php', [
            'pre_auth_token' => $preAuthToken,
            'otp_request_id' => $otpRequestId,
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$resendRes['ok']) {
            $json = $resendRes['json'] ?? [];
            sub_proxy_response(
                false,
                (string) ($json['code'] ?? 'OTP_RESEND_FAILED'),
                (string) ($json['message'] ?? 'Failed to resend OTP'),
                (array) ($json['data'] ?? []),
                $resendRes['status'] > 0 ? $resendRes['status'] : 400
            );
        }

        $data = (array) ($resendRes['json']['data'] ?? []);

        sub_proxy_response(true, 'SUCCESS', 'OTP resent successfully', [
            'require_otp' => true,
            'pre_auth_token' => (string) ($data['pre_auth_token'] ?? $preAuthToken),
            'otp_request_id' => (string) ($data['otp_request_id'] ?? $otpRequestId),
            'masked_phone' => (string) ($data['masked_phone'] ?? ''),
            'expires_in_seconds' => (int) ($data['expires_in_seconds'] ?? 300),
        ]);
        break;

    case 'forgot_send_otp':
        sub_proxy_require_method('POST');

        $body = sub_proxy_read_json_body();
        $phone = trim((string)($body['phone'] ?? ''));
        $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));

        if ($phone === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'Phone is required', [], 422);
        }

        if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
        }

        $forgotRes = sub_proxy_internal_api_request('POST', 'auth/forgot_send_otp.php', [
            'phone' => $phone,
            'reset_type' => $resetType,
        ], [
            'X-APP-KEY' => APP_KEY,
        ]);

        if (!$forgotRes['ok']) {
            $json = $forgotRes['json'] ?? [];
            sub_proxy_response(
                false,
                (string)($json['code'] ?? 'FORGOT_SEND_FAILED'),
                (string)($json['message'] ?? 'Failed to send OTP'),
                (array)($json['data'] ?? []),
                $forgotRes['status'] > 0 ? $forgotRes['status'] : 400
            );
        }

        $data = (array)($forgotRes['json']['data'] ?? []);

        $resetToken = trim((string)(
            $data['reset_token']
            ?? $data['forgot_token']
            ?? $data['token']
            ?? $data['reset_request_token']
            ?? ''
        ));

        $otpRequestId = trim((string)(
            $data['otp_request_id']
            ?? $data['request_id']
            ?? $data['otp_id']
            ?? ''
        ));

        $maskedPhone = trim((string)(
            $data['masked_phone']
            ?? $data['phone_mask']
            ?? $data['masked_number']
            ?? ''
        ));

        $expiresInSeconds = (int)(
            $data['expires_in_seconds']
            ?? $data['expires']
            ?? 300
        );

        if ($resetToken === '' || $otpRequestId === '') {
            sub_proxy_response(
                false,
                'INVALID_INTERNAL_RESPONSE',
                'OTP sent but reset token data missing from recovery API',
                $data,
                500
            );
        }

        sub_proxy_response(true, 'SUCCESS', 'OTP sent successfully', [
            'reset_token' => $resetToken,
            'forgot_token' => $resetToken,
            'otp_request_id' => $otpRequestId,
            'request_id' => $otpRequestId,
            'masked_phone' => $maskedPhone,
            'expires_in_seconds' => $expiresInSeconds,
            'reset_type' => $resetType,
        ]);
        break;

    case 'forgot_verify_otp':
        sub_proxy_require_method('POST');

        $body = sub_proxy_read_json_body();
        $resetType = strtoupper(trim((string)($body['reset_type'] ?? 'PASSWORD')));

        if (!in_array($resetType, ['PASSWORD', 'PIN'], true)) {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'Invalid reset type', [], 422);
        }

        $forwardBody = $body;

        if (empty($forwardBody['pre_auth_token']) && !empty($forwardBody['reset_token'])) {
            $forwardBody['pre_auth_token'] = $forwardBody['reset_token'];
        }

        if (empty($forwardBody['pre_auth_token']) && !empty($forwardBody['forgot_token'])) {
            $forwardBody['pre_auth_token'] = $forwardBody['forgot_token'];
        }

        if (empty($forwardBody['forgot_token']) && !empty($forwardBody['reset_token'])) {
            $forwardBody['forgot_token'] = $forwardBody['reset_token'];
        }

        if (empty($forwardBody['reset_token']) && !empty($forwardBody['forgot_token'])) {
            $forwardBody['reset_token'] = $forwardBody['forgot_token'];
        }

        if (empty($forwardBody['request_id']) && !empty($forwardBody['otp_request_id'])) {
            $forwardBody['request_id'] = $forwardBody['otp_request_id'];
        }

        if (empty($forwardBody['otp_request_id']) && !empty($forwardBody['request_id'])) {
            $forwardBody['otp_request_id'] = $forwardBody['request_id'];
        }

        $verifyForgotRes = sub_proxy_internal_api_request(
            'POST',
            'auth/forgot_verify_otp.php',
            $forwardBody,
            [
                'X-APP-KEY' => APP_KEY,
            ]
        );

        if (!$verifyForgotRes['ok']) {
            $json = $verifyForgotRes['json'] ?? [];
            sub_proxy_response(
                false,
                (string)($json['code'] ?? 'FORGOT_VERIFY_FAILED'),
                (string)($json['message'] ?? 'Failed to verify OTP'),
                (array)($json['data'] ?? []),
                $verifyForgotRes['status'] > 0 ? $verifyForgotRes['status'] : 400
            );
        }

        $data = (array)($verifyForgotRes['json']['data'] ?? []);
        sub_proxy_response(true, 'SUCCESS', 'Reset completed successfully', $data);
        break;

    case 'forgot_resend_otp':
        sub_proxy_require_method('POST');

        $body = sub_proxy_read_json_body();
        $forwardBody = $body;

        if (empty($forwardBody['pre_auth_token']) && !empty($forwardBody['reset_token'])) {
            $forwardBody['pre_auth_token'] = $forwardBody['reset_token'];
        }

        if (empty($forwardBody['pre_auth_token']) && !empty($forwardBody['forgot_token'])) {
            $forwardBody['pre_auth_token'] = $forwardBody['forgot_token'];
        }

        if (empty($forwardBody['forgot_token']) && !empty($forwardBody['reset_token'])) {
            $forwardBody['forgot_token'] = $forwardBody['reset_token'];
        }

        if (empty($forwardBody['reset_token']) && !empty($forwardBody['forgot_token'])) {
            $forwardBody['reset_token'] = $forwardBody['forgot_token'];
        }

        if (empty($forwardBody['request_id']) && !empty($forwardBody['otp_request_id'])) {
            $forwardBody['request_id'] = $forwardBody['otp_request_id'];
        }

        if (empty($forwardBody['otp_request_id']) && !empty($forwardBody['request_id'])) {
            $forwardBody['otp_request_id'] = $forwardBody['request_id'];
        }

        $resendForgotRes = sub_proxy_internal_api_request(
            'POST',
            'auth/forgot_resend_otp.php',
            $forwardBody,
            [
                'X-APP-KEY' => APP_KEY,
            ]
        );

        if (!$resendForgotRes['ok']) {
            $json = $resendForgotRes['json'] ?? [];
            sub_proxy_response(
                false,
                (string)($json['code'] ?? 'FORGOT_RESEND_FAILED'),
                (string)($json['message'] ?? 'Failed to resend OTP'),
                (array)($json['data'] ?? []),
                $resendForgotRes['status'] > 0 ? $resendForgotRes['status'] : 400
            );
        }

        $data = (array)($resendForgotRes['json']['data'] ?? []);

        $resetToken = trim((string)(
            $data['reset_token']
            ?? $data['forgot_token']
            ?? $data['pre_auth_token']
            ?? $data['token']
            ?? $forwardBody['pre_auth_token']
            ?? $forwardBody['reset_token']
            ?? $forwardBody['forgot_token']
            ?? ''
        ));

        $otpRequestId = trim((string)(
            $data['otp_request_id']
            ?? $data['request_id']
            ?? $data['otp_id']
            ?? $forwardBody['otp_request_id']
            ?? $forwardBody['request_id']
            ?? ''
        ));

        $maskedPhone = trim((string)(
            $data['masked_phone']
            ?? $data['phone_mask']
            ?? $data['masked_number']
            ?? ''
        ));

        $expiresInSeconds = (int)(
            $data['expires_in_seconds']
            ?? $data['expires']
            ?? 300
        );

        if ($resetToken === '' || $otpRequestId === '') {
            sub_proxy_response(
                false,
                'INVALID_INTERNAL_RESPONSE',
                'OTP resent but token data missing from recovery API',
                $data,
                500
            );
        }

        sub_proxy_response(true, 'SUCCESS', 'OTP resent successfully', [
            'reset_token' => $resetToken,
            'forgot_token' => $resetToken,
            'pre_auth_token' => $resetToken,
            'otp_request_id' => $otpRequestId,
            'request_id' => $otpRequestId,
            'masked_phone' => $maskedPhone,
            'expires_in_seconds' => $expiresInSeconds,
        ]);
        break;

    case 'logout':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $token = sub_proxy_get_session_token();
        if ($token !== '') {
            sub_proxy_internal_api_request('POST', 'auth/logout.php', [], [
                'X-APP-KEY' => APP_KEY,
                'X-SESSION-TOKEN' => $token,
            ]);
        }

        sub_proxy_clear_session();
        session_regenerate_id(true);

        sub_proxy_response(true, 'SUCCESS', 'Logout successful', []);
        break;

    case 'me':
        sub_proxy_require_method('GET');

        $user = sub_proxy_require_login(true);

        sub_proxy_response(true, 'SUCCESS', 'Session valid', [
            'user' => $user,
            'csrf' => sub_proxy_get_csrf(),
        ]);
        break;

    case 'wallet_summary':
        sub_proxy_require_method('GET');

        $user = sub_proxy_require_login(true);
        $uid = trim((string) ($user['uid'] ?? ''));

        $userRow = function_exists('subapi_load_user') ? subapi_load_user($uid) : null;
        $walletRow = function_exists('subapi_load_wallet') ? subapi_load_wallet($uid) : [];
        $roleSettingsRow = function_exists('subapi_load_role_settings') ? subapi_load_role_settings($uid) : [];

        if (!is_array($userRow)) {
            sub_proxy_response(false, 'NOT_FOUND', 'User not found', [], 404);
        }

        sub_proxy_response(true, 'SUCCESS', 'Wallet summary loaded', [
            'uid' => (string) ($userRow['uid'] ?? $uid),
            'name' => (string) ($userRow['name'] ?? ''),
            'phone' => (string) ($userRow['phone'] ?? ''),
            'email' => (string) ($userRow['email'] ?? ''),
            'status' => (string) ($userRow['status'] ?? ''),
            'role' => (string) ($userRow['role'] ?? ''),
            'created_at' => (int) ($userRow['created_at'] ?? 0),
            'last_login_at' => (int) ($userRow['last_login_at'] ?? 0),
            'wallet' => [
                'available_balance' => (float) ($walletRow['available_balance'] ?? 0),
                'hold_balance' => (float) ($walletRow['hold_balance'] ?? 0),
                'total_topup_spent' => (float) ($walletRow['total_topup_spent'] ?? 0),
                'total_bundle_spent' => (float) ($walletRow['total_bundle_spent'] ?? 0),
                'total_refund' => (float) ($walletRow['total_refund'] ?? 0),
                'updated_at' => (int) ($walletRow['updated_at'] ?? 0),
            ],
            'role_settings' => [
                'commission_per_1000' => (float) ($roleSettingsRow['commission_per_1000'] ?? 0),
                'api_enabled' => (bool) ($roleSettingsRow['api_enabled'] ?? false),
                'topup_enabled' => (bool) ($roleSettingsRow['topup_enabled'] ?? false),
                'bundle_enabled' => (bool) ($roleSettingsRow['bundle_enabled'] ?? false),
                'min_amount' => (float) ($roleSettingsRow['min_amount'] ?? 0),
                'max_amount' => (float) ($roleSettingsRow['max_amount'] ?? 0),
                'updated_at' => (int) ($roleSettingsRow['updated_at'] ?? 0),
            ],
        ]);
        break;

    case 'api_keys':
        sub_proxy_require_method('GET');

        $user = sub_proxy_require_login(true);
        $uid = trim((string) ($user['uid'] ?? ''));

        if (!function_exists('subapi_list_keys')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_list_keys helper', [], 500);
        }

        $items = subapi_list_keys($uid);
        $out = [];

        foreach ($items as $keyId => $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'key_id' => (string) ($row['key_id'] ?? $keyId),
                'uid' => (string) ($row['uid'] ?? $uid),
                'key_mask' => (string) ($row['key_mask'] ?? ''),
                'status' => (string) ($row['status'] ?? 'ACTIVE'),
                'last_used_at' => (int) ($row['last_used_at'] ?? 0),
                'created_at' => (int) ($row['created_at'] ?? 0),
                'updated_at' => (int) ($row['updated_at'] ?? 0),
                'created_by_uid' => (string) ($row['created_by_uid'] ?? ''),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0);
        });

        sub_proxy_response(true, 'SUCCESS', 'API key list loaded', [
            'uid' => $uid,
            'items' => array_values($out),
        ]);
        break;

    case 'api_key_create':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $user = sub_proxy_require_login(true);
        $uid = trim((string) ($user['uid'] ?? ''));
        $createdByUid = trim((string) ($user['uid'] ?? ''));

        if (!function_exists('subapi_create_key')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_create_key helper', [], 500);
        }

        $res = subapi_create_key($uid, $createdByUid);

        if (!($res['ok'] ?? false)) {
            sub_proxy_response(
                false,
                (string) ($res['code'] ?? 'SERVER_ERROR'),
                (string) ($res['message'] ?? 'Failed to create API key'),
                (array) ($res['data'] ?? []),
                500
            );
        }

        sub_proxy_response(
            true,
            (string) ($res['code'] ?? 'SUCCESS'),
            (string) ($res['message'] ?? 'API key created successfully'),
            (array) ($res['data'] ?? [])
        );
        break;

    case 'api_key_update_status':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $user = sub_proxy_require_login(true);
        $uid = trim((string) ($user['uid'] ?? ''));
        $body = sub_proxy_read_json_body();

        $keyId = trim((string) ($body['key_id'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        $updatedByUid = trim((string) ($user['uid'] ?? ''));

        if ($keyId === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'key_id is required', [], 422);
        }

        if (!function_exists('subapi_update_key_status')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_update_key_status helper', [], 500);
        }

        $res = subapi_update_key_status($uid, $keyId, $status, $updatedByUid);

        if (!($res['ok'] ?? false)) {
            sub_proxy_response(
                false,
                (string) ($res['code'] ?? 'SERVER_ERROR'),
                (string) ($res['message'] ?? 'Failed to update key status'),
                (array) ($res['data'] ?? []),
                500
            );
        }

        sub_proxy_response(
            true,
            (string) ($res['code'] ?? 'SUCCESS'),
            (string) ($res['message'] ?? 'API key status updated successfully'),
            (array) ($res['data'] ?? [])
        );
        break;

    case 'request_logs':
        sub_proxy_require_method('GET');

        $user = sub_proxy_require_login(true);
        $uid = trim((string) ($user['uid'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 100);

        if ($limit <= 0) $limit = 100;
        if ($limit > 500) $limit = 500;

        if (!function_exists('subapi_list_request_logs')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_list_request_logs helper', [], 500);
        }

        $items = subapi_list_request_logs($uid);
        $out = [];

        foreach ($items as $requestId => $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'request_id' => (string) ($row['request_id'] ?? $requestId),
                'uid' => (string) ($row['uid'] ?? $uid),
                'key_id' => (string) ($row['key_id'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'request_type' => (string) ($row['request_type'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'operator' => (string) ($row['operator'] ?? ''),
                'topup_number' => (string) ($row['topup_number'] ?? $row['bundle_number'] ?? $row['number'] ?? ''),
                'bundle_number' => (string) ($row['bundle_number'] ?? $row['topup_number'] ?? $row['number'] ?? ''),
                'offer_id' => (string) ($row['offer_id'] ?? ''),
                'bundle_name' => (string) ($row['bundle_name'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'message' => (string) ($row['message'] ?? ''),
                'created_at' => (int) ($row['created_at'] ?? 0),
                'updated_at' => (int) ($row['updated_at'] ?? 0),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return (int) ($b['created_at'] ?? 0) <=> (int) ($a['created_at'] ?? 0);
        });

        if (count($out) > $limit) {
            $out = array_slice($out, 0, $limit);
        }

        sub_proxy_response(true, 'SUCCESS', 'API request logs loaded', [
            'uid' => $uid,
            'items' => array_values($out),
        ]);
        break;

    case 'topup_create':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $user = sub_proxy_require_login(true);
        $body = sub_proxy_read_json_body();

        $uid = trim((string) ($user['uid'] ?? ''));
        $topupNumber = trim((string) ($body['topup_number'] ?? ''));
        $operator = trim((string) ($body['operator'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        $note = trim((string) ($body['note'] ?? ''));

        if (!function_exists('subapi_create_panel_topup')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_create_panel_topup helper', [], 500);
        }

        $res = subapi_create_panel_topup($uid, $topupNumber, $operator, $amount, $note);

        if (!($res['ok'] ?? false)) {
            $code = (string) ($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;
            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE', 'TOPUP_DISABLED'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'ROLE_NOT_ALLOWED'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            sub_proxy_response(
                false,
                $code,
                (string) ($res['message'] ?? 'Failed to create topup'),
                (array) ($res['data'] ?? []),
                $httpStatus
            );
        }

        sub_proxy_response(
            true,
            (string) ($res['code'] ?? 'SUCCESS'),
            (string) ($res['message'] ?? 'Topup request created successfully'),
            (array) ($res['data'] ?? []),
            200
        );
        break;
        
        
        
        case 'bundle_offers_panel':
        sub_proxy_require_method('GET');

        $user = sub_proxy_require_login(true);
        $uid = trim((string)($user['uid'] ?? ''));

        if ($uid === '') {
            sub_proxy_response(false, 'SESSION_EXPIRED', 'Subadmin session not found', [], 401);
        }

        if (!function_exists('subapi_panel_bundle_offers')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_panel_bundle_offers helper', [], 500);
        }

        $res = subapi_panel_bundle_offers($uid);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;
            if (in_array($code, ['VALIDATION_ERROR', 'BUNDLE_DISABLED'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'ROLE_NOT_ALLOWED'], true)) {
                $httpStatus = 403;
            } elseif ($code === 'USER_NOT_FOUND') {
                $httpStatus = 404;
            }

            sub_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to load bundle offers'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        sub_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Bundle offers loaded successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;

    case 'bundle_create_panel':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $user = sub_proxy_require_login(true);
        $uid = trim((string)($user['uid'] ?? ''));
        $body = sub_proxy_read_json_body();

        $offerId = trim((string)($body['offer_id'] ?? ''));
        $bundleNumber = trim((string)($body['bundle_number'] ?? $body['number'] ?? ''));
        $note = trim((string)($body['note'] ?? ''));

        $res = sub_proxy_create_panel_bundle_fixed($uid, $offerId, $bundleNumber, $note);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;
            if (in_array($code, ['VALIDATION_ERROR', 'INSUFFICIENT_BALANCE', 'BUNDLE_DISABLED', 'OFFER_INACTIVE', 'INVALID_OFFER'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'ROLE_NOT_ALLOWED'], true)) {
                $httpStatus = 403;
            } elseif (in_array($code, ['USER_NOT_FOUND', 'OFFER_NOT_FOUND'], true)) {
                $httpStatus = 404;
            } elseif ($code === 'SESSION_EXPIRED') {
                $httpStatus = 401;
            }

            sub_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to create bundle request'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        sub_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Bundle request created successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;
        
        
        
        case 'bundle_commission_save':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $user = sub_proxy_require_login(true);
        $uid = trim((string)($user['uid'] ?? ''));
        $body = sub_proxy_read_json_body();

        $offerId = trim((string)($body['offer_id'] ?? ''));
        $userCommission = (float)($body['user_commission'] ?? $body['commission'] ?? 0);
        $active = sub_proxy_bool_value($body['active'] ?? true);

        if ($uid === '') {
            sub_proxy_response(false, 'SESSION_EXPIRED', 'Subadmin session not found', [], 401);
        }

        if ($offerId === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'offer_id is required', [], 422);
        }

        if ($userCommission < 0) {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'user_commission cannot be negative', [], 422);
        }

        if (!function_exists('subapi_save_panel_bundle_commission')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_save_panel_bundle_commission helper', [], 500);
        }

        $res = subapi_save_panel_bundle_commission($uid, $offerId, $userCommission, $active);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;
            if (in_array($code, ['VALIDATION_ERROR', 'OFFER_INACTIVE'], true)) {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'ROLE_NOT_ALLOWED'], true)) {
                $httpStatus = 403;
            } elseif (in_array($code, ['USER_NOT_FOUND', 'OFFER_NOT_FOUND', 'NOT_FOUND'], true)) {
                $httpStatus = 404;
            }

            sub_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to save bundle commission'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        sub_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Bundle commission saved successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;

    case 'bundle_commission_reset':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $user = sub_proxy_require_login(true);
        $uid = trim((string)($user['uid'] ?? ''));
        $body = sub_proxy_read_json_body();

        $offerId = trim((string)($body['offer_id'] ?? ''));

        if ($uid === '') {
            sub_proxy_response(false, 'SESSION_EXPIRED', 'Subadmin session not found', [], 401);
        }

        if ($offerId === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'offer_id is required', [], 422);
        }

        if (!function_exists('subapi_reset_panel_bundle_commission')) {
            sub_proxy_response(false, 'SERVER_ERROR', 'Missing subapi_reset_panel_bundle_commission helper', [], 500);
        }

        $res = subapi_reset_panel_bundle_commission($uid, $offerId);

        if (!($res['ok'] ?? false)) {
            $code = (string)($res['code'] ?? 'SERVER_ERROR');

            $httpStatus = 500;
            if ($code === 'VALIDATION_ERROR') {
                $httpStatus = 422;
            } elseif (in_array($code, ['ACCOUNT_INACTIVE', 'ROLE_NOT_ALLOWED'], true)) {
                $httpStatus = 403;
            } elseif (in_array($code, ['USER_NOT_FOUND', 'OFFER_NOT_FOUND', 'NOT_FOUND'], true)) {
                $httpStatus = 404;
            }

            sub_proxy_response(
                false,
                $code,
                (string)($res['message'] ?? 'Failed to reset bundle commission'),
                (array)($res['data'] ?? []),
                $httpStatus
            );
        }

        sub_proxy_response(
            true,
            (string)($res['code'] ?? 'SUCCESS'),
            (string)($res['message'] ?? 'Bundle commission reset successfully'),
            (array)($res['data'] ?? []),
            200
        );
        break;
        
        
        

    case 'users_list':
        sub_proxy_require_method('GET');

        sub_proxy_require_login(true);

        $role = trim((string) ($_GET['role'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 200);

        if ($limit <= 0) $limit = 200;
        if ($limit > 500) $limit = 500;

        $qs = http_build_query([
            'role' => $role,
            'status' => $status,
            'limit' => $limit,
        ], '', '&', PHP_QUERY_RFC3986);

        sub_proxy_forward_internal_get(
            'users_list.php' . ($qs ? '?' . $qs : ''),
            'LOAD_FAILED',
            'Failed to load users'
        );
        break;

    case 'user_convert_retailer':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $body = sub_proxy_read_json_body();
        $uid = trim((string) ($body['uid'] ?? ''));

        if ($uid === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'User ID is required', [], 422);
        }

        sub_proxy_forward_internal_post(
            'user_convert_retailer.php',
            ['uid' => $uid],
            'CONVERT_FAILED',
            'Failed to convert user'
        );
        break;

    case 'user_create':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        sub_proxy_response(
            false,
            'OTP_REQUIRED',
            'Direct user creation is disabled. Please use OTP verification flow.',
            [],
            403
        );
        break;

    case 'wallet_deduct_send_otp':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $body = sub_proxy_read_json_body();

        sub_proxy_forward_internal_post(
            'wallet_deduct_send_otp.php',
            $body,
            'OTP_SEND_FAILED',
            'Failed to send OTP'
        );
        break;

    case 'wallet_deduct_confirm':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $body = sub_proxy_read_json_body();

        sub_proxy_forward_internal_post(
            'wallet_deduct_confirm.php',
            $body,
            'OTP_CONFIRM_FAILED',
            'Failed to confirm OTP'
        );
        break;

    case 'wallet_add_balance':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $body = sub_proxy_read_json_body();

        sub_proxy_forward_internal_post(
            'wallet_add_balance.php',
            $body,
            'ADD_BALANCE_FAILED',
            'Failed to add balance'
        );
        break;

    case 'wallet_ledger_list':
        sub_proxy_require_method('GET');

        sub_proxy_require_login(true);

        $uid = trim((string) ($_GET['uid'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 100);

        if ($uid === '') {
            sub_proxy_response(false, 'VALIDATION_ERROR', 'User ID is required', [], 422);
        }

        if ($limit <= 0) $limit = 100;
        if ($limit > 500) $limit = 500;

        $qs = http_build_query([
            'uid' => $uid,
            'limit' => $limit,
        ], '', '&', PHP_QUERY_RFC3986);

        sub_proxy_forward_internal_get(
            'wallet_ledger_list.php?' . $qs,
            'LEDGER_LOAD_FAILED',
            'Failed to load wallet ledger'
        );
        break;

    case 'user_create_send_otp':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $body = sub_proxy_read_json_body();

        sub_proxy_require_login(true);
        $sessionToken = sub_proxy_get_session_token();

        $res = sub_proxy_internal_api_request(
            'POST',
            'auth/user_create_send_otp.php',
            $body,
            [
                'X-APP-KEY' => APP_KEY,
                'X-SESSION-TOKEN' => $sessionToken,
            ]
        );

        if (!$res['ok']) {
            $json = $res['json'] ?? [];
            sub_proxy_response(
                false,
                (string)($json['code'] ?? 'USER_CREATE_OTP_SEND_FAILED'),
                (string)($json['message'] ?? 'Failed to send user create OTP'),
                (array)($json['data'] ?? []),
                $res['status'] > 0 ? $res['status'] : 400
            );
        }

        sub_proxy_response(
            true,
            'SUCCESS',
            (string)(($res['json']['message'] ?? '') ?: 'OTP sent successfully'),
            (array)($res['json']['data'] ?? [])
        );
        break;

    case 'user_create_confirm':
        sub_proxy_require_method('POST');
        sub_proxy_require_csrf();

        $body = sub_proxy_read_json_body();

        sub_proxy_require_login(true);
        $sessionToken = sub_proxy_get_session_token();

        $res = sub_proxy_internal_api_request(
            'POST',
            'auth/user_create_confirm.php',
            $body,
            [
                'X-APP-KEY' => APP_KEY,
                'X-SESSION-TOKEN' => $sessionToken,
            ]
        );

        if (!$res['ok']) {
            $json = $res['json'] ?? [];
            sub_proxy_response(
                false,
                (string)($json['code'] ?? 'USER_CREATE_CONFIRM_FAILED'),
                (string)($json['message'] ?? 'Failed to confirm user creation'),
                (array)($json['data'] ?? []),
                $res['status'] > 0 ? $res['status'] : 400
            );
        }

        sub_proxy_response(
            true,
            'SUCCESS',
            (string)(($res['json']['message'] ?? '') ?: 'User created successfully'),
            (array)($res['json']['data'] ?? [])
        );
        break;

    default:
        sub_proxy_response(false, 'NOT_FOUND', 'Unknown proxy action', [], 404);
}