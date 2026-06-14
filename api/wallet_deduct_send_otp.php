<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth_sms.php';
require_once __DIR__ . '/lib/wallet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function deduct_otp_send_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function deduct_otp_send_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        deduct_otp_send_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function deduct_otp_send_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        deduct_otp_send_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function deduct_otp_send_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function deduct_otp_send_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function deduct_otp_send_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/wallet_deduct_send_otp.php';
    $apiPath = dirname($script);
    return rtrim(deduct_otp_send_scheme() . '://' . deduct_otp_send_host() . $apiPath, '/');
}

function deduct_otp_send_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = deduct_otp_send_api_base_url() . '/' . ltrim($relativePath, '/');

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
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

function deduct_otp_send_extract_session_token(): string
{
    $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

function deduct_otp_send_require_actor(): array
{
    $sessionToken = deduct_otp_send_extract_session_token();
    if ($sessionToken === '') {
        deduct_otp_send_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $res = deduct_otp_send_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        deduct_otp_send_response(
            false,
            (string)($json['code'] ?? 'SESSION_EXPIRED'),
            (string)($json['message'] ?? 'Session expired'),
            [],
            $res['status'] > 0 ? $res['status'] : 401
        );
    }

    $actor = (array)($res['json']['data'] ?? []);
    $role = strtoupper(trim((string)($actor['role'] ?? '')));
    $status = strtoupper(trim((string)($actor['status'] ?? 'INACTIVE')));

    if (!in_array($role, ['SUBADMIN', 'ADMIN'], true)) {
        deduct_otp_send_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can send deduction OTP', [], 403);
    }

    if ($status !== 'ACTIVE') {
        deduct_otp_send_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return $actor;
}

function deduct_otp_send_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function deduct_otp_send_make_id(string $prefix = 'OD'): string
{
    return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function deduct_otp_send_normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function deduct_otp_send_mask_phone(string $phone): string
{
    $digits = deduct_otp_send_normalize_phone($phone);
    $len = strlen($digits);

    if ($len <= 4) return $digits;
    return str_repeat('*', max(0, $len - 4)) . substr($digits, -4);
}

function deduct_otp_send_actor_can_access_target(array $actor, array $target): bool
{
    $actorRole = strtoupper(trim((string)($actor['role'] ?? '')));
    $actorUid = trim((string)($actor['uid'] ?? ''));

    if ($actorRole === 'ADMIN') {
        return true;
    }

    if ($actorRole === 'SUBADMIN') {
        $parent = trim((string)($target['parent_subadmin_uid'] ?? ''));
        $createdBy = trim((string)($target['created_by_uid'] ?? ''));
        return $parent === $actorUid || $createdBy === $actorUid;
    }

    return false;
}

deduct_otp_send_require_method('POST');
$actor = deduct_otp_send_require_actor();
$body = deduct_otp_send_read_json_body();

$targetUid = trim((string)($body['uid'] ?? ''));
$amount = (float)($body['amount'] ?? 0);
$note = trim((string)($body['note'] ?? ''));

if ($targetUid === '') {
    deduct_otp_send_response(false, 'VALIDATION_ERROR', 'Target user ID is required', [], 422);
}

if ($amount <= 0) {
    deduct_otp_send_response(false, 'VALIDATION_ERROR', 'Amount must be greater than 0', [], 422);
}

$targetUser = fb_get('USERS/' . $targetUid);
if (!is_array($targetUser)) {
    deduct_otp_send_response(false, 'NOT_FOUND', 'Target user not found', [], 404);
}

$targetRole = strtoupper(trim((string)($targetUser['role'] ?? '')));
$targetStatus = strtoupper(trim((string)($targetUser['status'] ?? 'INACTIVE')));

$actorRole = strtoupper(trim((string)($actor['role'] ?? '')));
$allowedTargetRoles = $actorRole === 'ADMIN'
    ? ['USER', 'RETAILER', 'SUBADMIN']
    : ['USER', 'RETAILER'];

if (!in_array($targetRole, $allowedTargetRoles, true)) {
    deduct_otp_send_response(false, 'FORBIDDEN', 'This account role cannot be deducted here', [], 403);
}

if ($targetStatus !== 'ACTIVE') {
    deduct_otp_send_response(false, 'FORBIDDEN', 'Target account is inactive', [], 403);
}

if (!deduct_otp_send_actor_can_access_target($actor, $targetUser)) {
    deduct_otp_send_response(false, 'FORBIDDEN', 'You cannot access this account', [], 403);
}

$wallet = fb_get('USER_WALLETS/' . $targetUid);
$wallet = is_array($wallet) ? $wallet : [];
$targetCurrency = wallet_account_currency($targetUser, $wallet);
$targetPricingCountry = auth_pricing_country_from_user($targetUser, $wallet);
$targetPhoneCountry = auth_phone_country_from_user($targetUser);
$targetPhone = normalize_phone_by_country(
    (string)($targetUser['phone'] ?? ''),
    $targetPhoneCountry
);

if ($targetPhone === '') {
    deduct_otp_send_response(
        false,
        'VALIDATION_ERROR',
        auth_phone_validation_message($targetPhoneCountry),
        [],
        422
    );
}

if ($actorRole === 'SUBADMIN') {
    $actorUidForCurrency = trim((string)($actor['uid'] ?? ''));
    $actorUser = $actorUidForCurrency !== '' ? fb_get('USERS/' . $actorUidForCurrency) : [];
    $actorWallet = $actorUidForCurrency !== '' ? fb_get('USER_WALLETS/' . $actorUidForCurrency) : [];
    $actorCurrency = wallet_account_currency(
        is_array($actorUser) ? $actorUser : $actor,
        is_array($actorWallet) ? $actorWallet : []
    );

    if ($actorCurrency !== $targetCurrency) {
        deduct_otp_send_response(
            false,
            'CURRENCY_MISMATCH',
            'Subadmin and target wallet currency must match',
            [
                'subadmin_currency' => $actorCurrency,
                'target_currency' => $targetCurrency,
            ],
            422
        );
    }
}

$available = (float)($wallet['available_balance'] ?? 0);
if ($available < $amount) {
    deduct_otp_send_response(false, 'INSUFFICIENT_BALANCE', 'Insufficient available balance', [
        'available_balance' => $available,
    ], 422);
}

$actorUid = trim((string)($actor['uid'] ?? ''));
$latestPath = 'WALLET_DEDUCT_OTP_LATEST/' . $targetUid . '/' . $actorUid;
$latest = fb_get($latestPath);
$latest = is_array($latest) ? $latest : [];
$now = deduct_otp_send_now();

if (
    !empty($latest['created_at']) &&
    ((int)$latest['created_at'] + (defined('WALLET_DEDUCT_OTP_COOLDOWN_SECONDS') ? WALLET_DEDUCT_OTP_COOLDOWN_SECONDS : 60)) > $now &&
    strtoupper(trim((string)($latest['status'] ?? ''))) === 'PENDING'
) {
    deduct_otp_send_response(false, 'OTP_COOLDOWN', 'Please wait before sending another OTP', [
        'retry_after_seconds' => max(1, ((int)$latest['created_at'] + (defined('WALLET_DEDUCT_OTP_COOLDOWN_SECONDS') ? WALLET_DEDUCT_OTP_COOLDOWN_SECONDS : 60)) - $now),
    ], 429);
}

$otp = (string)random_int(100000, 999999);
$otpRequestId = deduct_otp_send_make_id('OD');
$expiresAt = $now + (defined('WALLET_DEDUCT_OTP_TTL_SECONDS') ? WALLET_DEDUCT_OTP_TTL_SECONDS : 300);
$maxAttempts = defined('WALLET_DEDUCT_OTP_MAX_ATTEMPTS') ? (int)WALLET_DEDUCT_OTP_MAX_ATTEMPTS : 5;

$currencyLabel = $targetCurrency === 'MYR' ? 'RM' : 'BDT';
$message = 'Z-Pay Swift: OTP for deducting ' . $currencyLabel . ' '
    . number_format($amount, 2, '.', '')
    . ' from your wallet is ' . $otp
    . '. Valid for 5 minutes. Do not share this code.';

$smsRes = auth_send_otp_sms_by_country(
    $targetPhoneCountry,
    $targetPhone,
    $message,
    $otpRequestId,
    'BALANCE_DEDUCT',
    $otp
);
$smsPatch = auth_sms_result_log_fields($smsRes);

$dataRow = [
    'otp_request_id' => $otpRequestId,
    'target_uid' => $targetUid,
    'target_role' => $targetRole,
    'target_name' => (string)($targetUser['name'] ?? ''),
    'target_phone' => $targetPhone,
    'country' => $targetPhoneCountry,
    'phone_country' => $targetPhoneCountry,
    'pricing_country' => $targetPricingCountry,
    'service_country' => $targetPricingCountry,
    'phone_e164' => $targetPhone,
    'requested_by_uid' => $actorUid,
    'requested_by_role' => (string)($actor['role'] ?? ''),
    'amount' => $amount,
    'currency' => $targetCurrency,
    'wallet_currency' => $targetCurrency,
    'note' => $note,
    'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
    'otp_last4' => substr($otp, -4),
    'status' => ($smsRes['ok'] ?? false) ? 'PENDING' : 'SMS_FAILED',
    'attempt_count' => 0,
    'max_attempts' => $maxAttempts,
    'expires_at' => $expiresAt,
    'created_at' => $now,
    'updated_at' => $now,
    'verified_at' => 0,
    'completed_at' => 0,
    'sms_provider' => (string)($smsRes['gateway'] ?? ''),
    'sms_status' => ($smsRes['ok'] ?? false) ? 'SENT' : 'FAILED',
    'sms_code' => (string)($smsRes['code'] ?? ''),
    'sms_raw' => '',
] + $smsPatch;

fb_put('WALLET_DEDUCT_OTP/' . $otpRequestId, $dataRow);
fb_put($latestPath, [
    'otp_request_id' => $otpRequestId,
    'target_uid' => $targetUid,
    'requested_by_uid' => $actorUid,
    'status' => (string)$dataRow['status'],
    'created_at' => $now,
    'updated_at' => $now,
]);

if (!($smsRes['ok'] ?? false)) {
    deduct_otp_send_response(false, 'SMS_SEND_FAILED', 'OTP SMS could not be sent', [
        'otp_request_id' => $otpRequestId,
        'sms_code' => (string)($smsRes['code'] ?? ''),
        'sms_message' => (string)($smsRes['message'] ?? ''),
        'sms_raw' => (string)($smsRes['raw'] ?? ''),
    ], 500);
}

if (function_exists('system_log')) {
    system_log('WALLET_DEDUCT_OTP_SENT', $otpRequestId, 'Wallet deduction OTP sent', [
        'target_uid' => $targetUid,
        'requested_by_uid' => $actorUid,
        'amount' => $amount,
        'currency' => $targetCurrency,
        'phone_country' => $targetPhoneCountry,
        'pricing_country' => $targetPricingCountry,
        'sms_gateway' => (string)($smsRes['gateway'] ?? ''),
        'sms_template_key' => (string)($smsRes['template_key'] ?? ''),
    ]);
}

deduct_otp_send_response(true, 'SUCCESS', 'OTP sent successfully', [
    'otp_request_id' => $otpRequestId,
    'target_uid' => $targetUid,
    'target_name' => (string)($targetUser['name'] ?? ''),
    'masked_phone' => deduct_otp_send_mask_phone($targetPhone),
    'amount' => $amount,
    'currency' => $targetCurrency,
    'wallet_currency' => $targetCurrency,
    'phone_country' => $targetPhoneCountry,
    'pricing_country' => $targetPricingCountry,
    'expires_at' => $expiresAt,
    'expires_in_seconds' => max(0, $expiresAt - $now),
]);
