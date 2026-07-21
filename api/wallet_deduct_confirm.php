<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/wallet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function deduct_otp_confirm_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function deduct_otp_confirm_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        deduct_otp_confirm_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function deduct_otp_confirm_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        deduct_otp_confirm_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function deduct_otp_confirm_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function deduct_otp_confirm_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function deduct_otp_confirm_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/wallet_deduct_confirm.php';
    $apiPath = dirname($script);
    return rtrim(deduct_otp_confirm_scheme() . '://' . deduct_otp_confirm_host() . $apiPath, '/');
}

function deduct_otp_confirm_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = deduct_otp_confirm_api_base_url() . '/' . ltrim($relativePath, '/');

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

function deduct_otp_confirm_extract_session_token(): string
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

function deduct_otp_confirm_require_actor(): array
{
    $sessionToken = deduct_otp_confirm_extract_session_token();
    if ($sessionToken === '') {
        deduct_otp_confirm_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $res = deduct_otp_confirm_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        deduct_otp_confirm_response(
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
        deduct_otp_confirm_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can confirm deduction OTP', [], 403);
    }

    if ($status !== 'ACTIVE') {
        deduct_otp_confirm_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return $actor;
}

function deduct_otp_confirm_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function deduct_otp_confirm_make_id(string $prefix = 'WL'): string
{
    return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function deduct_otp_confirm_money($value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return round((float)$value, 2);
    }

    $value = str_replace(',', '', trim((string)$value));

    if ($value === '' || !is_numeric($value)) {
        return $default;
    }

    return round((float)$value, 2);
}

function deduct_otp_confirm_actor_can_access_target(array $actor, array $target): bool
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

deduct_otp_confirm_require_method('POST');

$actor = deduct_otp_confirm_require_actor();
$body = deduct_otp_confirm_read_json_body();

$otpRequestId = trim((string)($body['otp_request_id'] ?? ''));
$otp = trim((string)($body['otp'] ?? ''));

if ($otpRequestId === '' || $otp === '') {
    deduct_otp_confirm_response(false, 'VALIDATION_ERROR', 'otp_request_id and otp are required', [], 422);
}

$request = fb_get('WALLET_DEDUCT_OTP/' . $otpRequestId);
if (!is_array($request)) {
    deduct_otp_confirm_response(false, 'NOT_FOUND', 'OTP request not found', [], 404);
}

$actorUid = trim((string)($actor['uid'] ?? ''));
$actorRole = strtoupper(trim((string)($actor['role'] ?? '')));

if (trim((string)($request['requested_by_uid'] ?? '')) !== $actorUid && $actorRole !== 'ADMIN') {
    deduct_otp_confirm_response(false, 'FORBIDDEN', 'You cannot confirm this OTP request', [], 403);
}

$status = strtoupper(trim((string)($request['status'] ?? '')));
if ($status === 'COMPLETED') {
    deduct_otp_confirm_response(true, 'SUCCESS', 'OTP verified and wallet deducted successfully', [
        'otp_request_id' => $otpRequestId,
        'ledger_id' => (string)($request['ledger_id'] ?? ''),
        'actor_ledger_id' => (string)($request['actor_ledger_id'] ?? ''),
        'target_uid' => (string)($request['target_uid'] ?? ''),
        'amount' => deduct_otp_confirm_money($request['base_amount'] ?? $request['amount'] ?? 0, 0.0),
        'base_amount' => deduct_otp_confirm_money($request['base_amount'] ?? $request['amount'] ?? 0, 0.0),
        'commission_per_1000' => deduct_otp_confirm_money($request['commission_per_1000'] ?? 0, 0.0),
        'commission_amount' => deduct_otp_confirm_money($request['commission_amount'] ?? 0, 0.0),
        'total_debit' => deduct_otp_confirm_money($request['total_debit'] ?? $request['amount'] ?? 0, 0.0),
        'currency' => (string)($request['currency'] ?? $request['wallet_currency'] ?? ''),
        'wallet_currency' => (string)($request['wallet_currency'] ?? $request['currency'] ?? ''),
        'before_available' => deduct_otp_confirm_money($request['before_available'] ?? 0, 0.0),
        'after_available' => deduct_otp_confirm_money($request['after_available'] ?? 0, 0.0),
        'subadmin_credited' => !empty($request['subadmin_credited']),
        'subadmin_before_available' => deduct_otp_confirm_money($request['subadmin_before_available'] ?? 0, 0.0),
        'subadmin_after_available' => deduct_otp_confirm_money($request['subadmin_after_available'] ?? 0, 0.0),
        'completed_at' => (int)($request['completed_at'] ?? $request['updated_at'] ?? deduct_otp_confirm_now()),
    ]);
}
if ($status !== 'PENDING') {
    deduct_otp_confirm_response(false, 'INVALID_STATUS', 'OTP request is not pending', [
        'status' => $status,
    ], 409);
}

$now = deduct_otp_confirm_now();

if ((int)($request['expires_at'] ?? 0) < $now) {
    fb_patch('WALLET_DEDUCT_OTP/' . $otpRequestId, [
        'status' => 'EXPIRED',
        'updated_at' => $now,
    ]);

    deduct_otp_confirm_response(false, 'OTP_EXPIRED', 'OTP has expired', [], 410);
}

$attemptCount = (int)($request['attempt_count'] ?? 0);
$maxAttempts = (int)($request['max_attempts'] ?? (defined('WALLET_DEDUCT_OTP_MAX_ATTEMPTS') ? WALLET_DEDUCT_OTP_MAX_ATTEMPTS : 5));

if ($attemptCount >= $maxAttempts) {
    fb_patch('WALLET_DEDUCT_OTP/' . $otpRequestId, [
        'status' => 'LOCKED',
        'updated_at' => $now,
    ]);

    deduct_otp_confirm_response(false, 'OTP_LOCKED', 'Maximum OTP attempts exceeded', [], 423);
}

$otpHash = trim((string)($request['otp_hash'] ?? ''));

if ($otpHash === '' || !password_verify($otp, $otpHash)) {
    $newAttempts = $attemptCount + 1;
    $newStatus = $newAttempts >= $maxAttempts ? 'LOCKED' : 'PENDING';

    fb_patch('WALLET_DEDUCT_OTP/' . $otpRequestId, [
        'attempt_count' => $newAttempts,
        'status' => $newStatus,
        'updated_at' => $now,
    ]);

    deduct_otp_confirm_response(false, 'INVALID_OTP', 'Invalid OTP', [
        'attempt_count' => $newAttempts,
        'remaining_attempts' => max(0, $maxAttempts - $newAttempts),
    ], 422);
}

$targetUid = trim((string)($request['target_uid'] ?? ''));
$targetUser = fb_get('USERS/' . $targetUid);

if (!is_array($targetUser)) {
    deduct_otp_confirm_response(false, 'NOT_FOUND', 'Target user not found', [], 404);
}

if (!deduct_otp_confirm_actor_can_access_target($actor, $targetUser)) {
    deduct_otp_confirm_response(false, 'FORBIDDEN', 'You cannot access this account', [], 403);
}

$targetRole = strtoupper(trim((string)($targetUser['role'] ?? '')));
$allowedTargetRoles = $actorRole === 'ADMIN'
    ? ['USER', 'RETAILER', 'SUBADMIN']
    : ['USER', 'RETAILER'];

if (!in_array($targetRole, $allowedTargetRoles, true)) {
    deduct_otp_confirm_response(false, 'FORBIDDEN', 'This account role cannot be deducted here', [], 403);
}

$wallet = fb_get('USER_WALLETS/' . $targetUid);
$wallet = is_array($wallet) ? $wallet : [];
$operationCurrency = wallet_normalize_currency_code(
    $request['currency'] ?? $request['wallet_currency'] ?? '',
    wallet_account_currency($targetUser, $wallet)
);
$currencyLabel = $operationCurrency === 'MYR' ? 'RM' : 'BDT';

$baseAmount = deduct_otp_confirm_money($request['amount'] ?? 0, 0.0);

if ($baseAmount <= 0) {
    deduct_otp_confirm_response(false, 'VALIDATION_ERROR', 'Invalid deduction amount', [], 422);
}

$isSubadminTransfer = $actorRole === 'SUBADMIN';

$commissionPer1000 = 0.0;
$commissionAmount = 0.0;

if ($isSubadminTransfer) {
    if ($actorUid === '') {
        deduct_otp_confirm_response(false, 'UNAUTHORIZED', 'Subadmin UID missing', [], 401);
    }

    if ($actorUid === $targetUid) {
        deduct_otp_confirm_response(false, 'VALIDATION_ERROR', 'You cannot deduct from your own account here', [], 422);
    }

}

$totalDebit = round($baseAmount, 2);
$operationType = $isSubadminTransfer ? 'SUBADMIN_WALLET_DEDUCT' : 'ADMIN_WALLET_DEDUCT';
$operationRef = 'WALLET_DEDUCT:' . hash('sha256', implode('|', [
    $operationType,
    $otpRequestId,
    $actorUid,
    $targetUid,
]));
$operation = wallet_financial_operation_begin($operationRef, $operationType, 'REQUEST_FINAL', $targetUid, $totalDebit, $operationCurrency, [
    'actor_uid' => $actorUid,
    'actor_role' => $actorRole,
    'target_uid' => $targetUid,
    'otp_request_id' => $otpRequestId,
    'source' => $isSubadminTransfer ? 'SUBADMIN_PANEL' : 'ADMIN_PANEL',
]);
if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
    $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
    deduct_otp_confirm_response(true, 'SUCCESS', 'OTP verified and wallet deducted successfully', $resultData);
}
if (empty($operation['ok']) || empty($operation['claim'])) {
    deduct_otp_confirm_response(false, (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'), (string)($operation['message'] ?? 'Wallet operation is unavailable'), [], 409);
}
$financialClaim = $operation['claim'];

$beforeAvailable = deduct_otp_confirm_money($wallet['available_balance'] ?? 0, 0.0);
$beforeHold = deduct_otp_confirm_money($wallet['hold_balance'] ?? 0, 0.0);

if ($beforeAvailable < $totalDebit) {
    deduct_otp_confirm_response(false, 'INSUFFICIENT_BALANCE', 'Insufficient available balance', [
        'available_balance' => $beforeAvailable,
        'required_amount' => $totalDebit,
        'base_amount' => $baseAmount,
        'commission_per_1000' => $commissionPer1000,
        'commission_amount' => $commissionAmount,
        'total_debit' => $totalDebit,
    ], 422);
}

$afterAvailable = round($beforeAvailable - $totalDebit, 2);

$actorBeforeAvailable = 0.0;
$actorAfterAvailable = 0.0;
$actorLedgerId = '';

if ($isSubadminTransfer) {
    $actorWallet = fb_get('USER_WALLETS/' . $actorUid);
    $actorWallet = is_array($actorWallet) ? $actorWallet : [];
    $actorUser = fb_get('USERS/' . $actorUid);
    $actorUser = is_array($actorUser) ? $actorUser : $actor;
    $actorCurrency = wallet_account_currency($actorUser, $actorWallet);

    if ($actorCurrency !== $operationCurrency) {
        deduct_otp_confirm_response(false, 'CURRENCY_MISMATCH', 'Subadmin and target wallet currency must match', [
            'subadmin_currency' => $actorCurrency,
            'target_currency' => $operationCurrency,
        ], 422);
    }

    $actorBeforeAvailable = deduct_otp_confirm_money($actorWallet['available_balance'] ?? 0, 0.0);
    $actorAfterAvailable = round($actorBeforeAvailable + $totalDebit, 2);
}

$ledgerId = wallet_financial_operation_side_ledger_id($operationRef, $operationType, 'target_debited');
$actorLedgerId = $isSubadminTransfer
    ? wallet_financial_operation_side_ledger_id($operationRef, $operationType, 'actor_credited')
    : '';

$baseNote = trim((string)($request['note'] ?? ''));
$finalNote = $baseNote !== '' ? $baseNote : 'Wallet deducted';

$finalNote .= ' | Base: ' . $currencyLabel . ' ' . number_format($baseAmount, 2, '.', '');
$finalNote .= ' | Total Debit: ' . $currencyLabel . ' ' . number_format($totalDebit, 2, '.', '');

$targetDebit = wallet_apply_available_delta_with_operation(
    $financialClaim,
    $targetUid,
    $totalDebit,
    'DEBIT',
    $operationRef,
    $isSubadminTransfer ? 'SUBADMIN_DEDUCT_WITH_COMMISSION' : 'ADMIN_DEDUCT',
    $finalNote,
    [
        'ledger_id' => $ledgerId,
        'currency' => $operationCurrency,
        'wallet_currency' => $operationCurrency,
        'otp_request_id' => $otpRequestId,
        'base_amount' => $baseAmount,
        'commission_per_1000' => $commissionPer1000,
        'commission_amount' => $commissionAmount,
        'total_debit' => $totalDebit,
        'created_by_uid' => $actorUid,
        'created_by_role' => $actorRole,
    ],
    'target_debited'
);

if (empty($targetDebit['ok'])) {
    deduct_otp_confirm_response(false, (string)($targetDebit['code'] ?? 'SERVER_ERROR'), (string)($targetDebit['message'] ?? 'Failed to update wallet balance'), [], 500);
}

$beforeAvailable = deduct_otp_confirm_money($targetDebit['before_available'] ?? $beforeAvailable, $beforeAvailable);
$afterAvailable = deduct_otp_confirm_money($targetDebit['after_available'] ?? $afterAvailable, $afterAvailable);
$ledgerId = (string)($targetDebit['ledger_id'] ?? $ledgerId);

if ($isSubadminTransfer) {
    $actorCredit = wallet_apply_available_delta_with_operation(
        $financialClaim,
        $actorUid,
        $totalDebit,
        'CREDIT',
        $operationRef,
        'SUBADMIN_DEDUCT_RETURN_IN',
        'Deducted from user/retailer and returned to subadmin | ' . $finalNote,
        [
            'ledger_id' => $actorLedgerId,
            'currency' => $operationCurrency,
            'wallet_currency' => $operationCurrency,
            'otp_request_id' => $otpRequestId,
            'base_amount' => $baseAmount,
            'commission_per_1000' => $commissionPer1000,
            'commission_amount' => $commissionAmount,
            'total_credit' => $totalDebit,
            'target_uid' => $targetUid,
            'target_name' => (string)($targetUser['name'] ?? ''),
            'target_phone' => (string)($targetUser['phone'] ?? ''),
            'created_by_uid' => $actorUid,
            'created_by_role' => $actorRole,
        ],
        'actor_credited'
    );

    if (empty($actorCredit['ok'])) {
        deduct_otp_confirm_response(false, (string)($actorCredit['code'] ?? 'SERVER_ERROR'), (string)($actorCredit['message'] ?? 'Failed to credit subadmin wallet'), [], 500);
    }

    $actorBeforeAvailable = deduct_otp_confirm_money($actorCredit['before_available'] ?? $actorBeforeAvailable, $actorBeforeAvailable);
    $actorAfterAvailable = deduct_otp_confirm_money($actorCredit['after_available'] ?? $actorAfterAvailable, $actorAfterAvailable);
    $actorLedgerId = (string)($actorCredit['ledger_id'] ?? $actorLedgerId);
}

$finalizeOk = fb_patch('WALLET_DEDUCT_OTP/' . $otpRequestId, [
    'status' => 'COMPLETED',
    'attempt_count' => $attemptCount + 1,
    'verified_at' => $now,
    'completed_at' => $now,
    'updated_at' => $now,
    'ledger_id' => $ledgerId,
    'actor_ledger_id' => $actorLedgerId,

    'base_amount' => $baseAmount,
    'commission_per_1000' => $commissionPer1000,
    'commission_amount' => $commissionAmount,
    'total_debit' => $totalDebit,
    'subadmin_credited' => $isSubadminTransfer,
    'currency' => $operationCurrency,
    'wallet_currency' => $operationCurrency,
]);

if (!$finalizeOk) {
    wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Wallet deduct OTP finalization failed', [
        'wallet_applied' => true,
        'target_debited' => true,
        'actor_credited' => $isSubadminTransfer,
        'request_finalized' => false,
        'ledger_id' => $ledgerId,
        'actor_ledger_id' => $actorLedgerId,
    ]);
    deduct_otp_confirm_response(false, 'REQUEST_FINALIZATION_FAILED', 'Wallet updated but confirmation finalization must be retried', [
        'otp_request_id' => $otpRequestId,
        'ledger_id' => $ledgerId,
        'actor_ledger_id' => $actorLedgerId,
    ], 500);
}

fb_put('WALLET_DEDUCT_OTP_LATEST/' . $targetUid . '/' . $actorUid, [
    'otp_request_id' => $otpRequestId,
    'target_uid' => $targetUid,
    'requested_by_uid' => $actorUid,
    'status' => 'COMPLETED',
    'created_at' => (int)($request['created_at'] ?? $now),
    'updated_at' => $now,
]);

$responseData = [
    'otp_request_id' => $otpRequestId,
    'ledger_id' => $ledgerId,
    'actor_ledger_id' => $actorLedgerId,
    'target_uid' => $targetUid,

    'amount' => $baseAmount,
    'base_amount' => $baseAmount,
    'commission_per_1000' => $commissionPer1000,
    'commission_amount' => $commissionAmount,
    'total_debit' => $totalDebit,

    'currency' => $operationCurrency,
    'wallet_currency' => $operationCurrency,
    'before_available' => $beforeAvailable,
    'after_available' => $afterAvailable,

    'subadmin_credited' => $isSubadminTransfer,
    'subadmin_before_available' => $actorBeforeAvailable,
    'subadmin_after_available' => $actorAfterAvailable,

    'completed_at' => $now,
];

wallet_financial_operation_mark_completed($financialClaim, [
    'wallet_applied' => true,
    'target_debited' => true,
    'actor_credited' => $isSubadminTransfer,
    'request_finalized' => true,
    'ledger_id' => $ledgerId,
    'actor_ledger_id' => $actorLedgerId,
    'result_data' => $responseData,
]);

if (function_exists('system_log')) {
    system_log('WALLET_DEDUCT_COMPLETED', $otpRequestId, 'Wallet deduction completed after OTP verification', [
        'target_uid' => $targetUid,
        'requested_by_uid' => $actorUid,
        'base_amount' => $baseAmount,
        'commission_per_1000' => $commissionPer1000,
        'commission_amount' => $commissionAmount,
        'total_debit' => $totalDebit,
        'currency' => $operationCurrency,
        'ledger_id' => $ledgerId,
        'actor_ledger_id' => $actorLedgerId,
        'subadmin_credited' => $isSubadminTransfer,
    ]);
}

deduct_otp_confirm_response(true, 'SUCCESS', 'OTP verified and wallet deducted successfully', $responseData);
