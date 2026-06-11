<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/wallet.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function wallet_add_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
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

function wallet_add_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        wallet_add_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function wallet_add_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wallet_add_response(false, 'INVALID_JSON', 'Request body must be valid JSON', [], 400);
    }

    return $decoded;
}

function wallet_add_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function wallet_add_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function wallet_add_api_base_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/wallet_add_balance.php';
    $apiPath = dirname($script);
    return rtrim(wallet_add_scheme() . '://' . wallet_add_host() . $apiPath, '/');
}

function wallet_add_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = wallet_add_api_base_url() . '/' . ltrim($relativePath, '/');

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

function wallet_add_extract_session_token(): string
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

function wallet_add_require_actor(): array
{
    $sessionToken = wallet_add_extract_session_token();
    if ($sessionToken === '') {
        wallet_add_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $res = wallet_add_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        wallet_add_response(
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
        wallet_add_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can add balance', [], 403);
    }

    if ($status !== 'ACTIVE') {
        wallet_add_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return $actor;
}

function wallet_add_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function wallet_add_make_id(string $prefix = 'WL'): string
{
    return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function wallet_add_money($value, float $default = 0.0): float
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

function wallet_add_actor_can_access_target(array $actor, array $target): bool
{
    $actorRole = strtoupper(trim((string)($actor['role'] ?? '')));
    $actorUid  = trim((string)($actor['uid'] ?? ''));

    if ($actorRole === 'ADMIN') {
        return true;
    }

    if ($actorRole === 'SUBADMIN') {
        $parent    = trim((string)($target['parent_subadmin_uid'] ?? ''));
        $createdBy = trim((string)($target['created_by_uid'] ?? ''));
        return $parent === $actorUid || $createdBy === $actorUid;
    }

    return false;
}

wallet_add_require_method('POST');

$actor = wallet_add_require_actor();
$body  = wallet_add_read_json_body();

$actorUid  = trim((string)($actor['uid'] ?? ''));
$actorRole = strtoupper(trim((string)($actor['role'] ?? '')));

$targetUid = trim((string)($body['uid'] ?? ''));
$amount    = wallet_add_money($body['amount'] ?? 0, 0.0);
$note      = trim((string)($body['note'] ?? ''));
$reference = trim((string)($body['reference'] ?? ''));

if ($targetUid === '') {
    wallet_add_response(false, 'VALIDATION_ERROR', 'Target user ID is required', [], 422);
}

if ($amount <= 0) {
    wallet_add_response(false, 'VALIDATION_ERROR', 'Amount must be greater than 0', [], 422);
}

$targetUser = fb_get('USERS/' . $targetUid);
if (!is_array($targetUser)) {
    wallet_add_response(false, 'NOT_FOUND', 'Target user not found', [], 404);
}

$targetRole   = strtoupper(trim((string)($targetUser['role'] ?? '')));
$targetStatus = strtoupper(trim((string)($targetUser['status'] ?? 'INACTIVE')));

if (!in_array($targetRole, ['USER', 'RETAILER'], true)) {
    wallet_add_response(false, 'FORBIDDEN', 'Only USER or RETAILER wallet can be credited here', [], 403);
}

if ($targetStatus !== 'ACTIVE') {
    wallet_add_response(false, 'FORBIDDEN', 'Target account is inactive', [], 403);
}

if (!wallet_add_actor_can_access_target($actor, $targetUser)) {
    wallet_add_response(false, 'FORBIDDEN', 'You cannot access this account', [], 403);
}

$isSubadminTransfer = $actorRole === 'SUBADMIN';

if ($isSubadminTransfer) {
    if ($actorUid === '') {
        wallet_add_response(false, 'UNAUTHORIZED', 'Subadmin UID missing', [], 401);
    }

    if ($actorUid === $targetUid) {
        wallet_add_response(false, 'VALIDATION_ERROR', 'You cannot add balance to your own account from this panel', [], 422);
    }
}

$targetWallet = fb_get('USER_WALLETS/' . $targetUid);
$targetWallet = is_array($targetWallet) ? $targetWallet : [];

$now = wallet_add_now();
$transferId = wallet_make_transfer_id();
$ledgerId = wallet_add_make_id('WL');
$actorLedgerId = $isSubadminTransfer ? wallet_add_make_id('WL') : '';
$senderIdentity = wallet_identity($actorUid, $actor, $actorRole !== '' ? $actorRole : 'ADMIN');
$receiverIdentity = wallet_identity($targetUid, $targetUser, $targetRole);

$beforeAvailable = wallet_add_money($targetWallet['available_balance'] ?? 0, 0.0);
$beforeHold      = wallet_add_money($targetWallet['hold_balance'] ?? 0, 0.0);

$commissionPer1000 = 0.0;
$commissionAmount  = 0.0;
$totalCredit = round($amount, 2);
$afterAvailable = round($beforeAvailable + $totalCredit, 2);

$actorBeforeAvailable = 0.0;
$actorAfterAvailable = 0.0;
$actorBeforeHold = 0.0;
$actorAfterHold = 0.0;

if ($isSubadminTransfer) {
    $actorWallet = fb_get('USER_WALLETS/' . $actorUid);
    $actorWallet = is_array($actorWallet) ? $actorWallet : [];

    $actorBeforeAvailable = wallet_add_money($actorWallet['available_balance'] ?? 0, 0.0);
    $actorBeforeHold = wallet_add_money($actorWallet['hold_balance'] ?? 0, 0.0);
    $actorAfterHold = $actorBeforeHold;

    if ($actorBeforeAvailable < $totalCredit) {
        wallet_add_response(false, 'INSUFFICIENT_SUBADMIN_BALANCE', 'Subadmin balance is not enough', [
            'required_amount' => $totalCredit,
            'available_balance' => $actorBeforeAvailable,
            'base_amount' => $amount,
            'commission_per_1000' => $commissionPer1000,
            'commission_amount' => $commissionAmount,
            'total_credit' => $totalCredit,
        ], 422);
    }

    $actorAfterAvailable = round($actorBeforeAvailable - $totalCredit, 2);

    $actorDebitOk = fb_patch('USER_WALLETS/' . $actorUid, [
        'available_balance' => $actorAfterAvailable,
        'updated_at' => $now,
    ]);

    if (!$actorDebitOk) {
        wallet_add_response(false, 'SERVER_ERROR', 'Failed to deduct subadmin balance', [], 500);
    }
}

$targetCreditOk = fb_patch('USER_WALLETS/' . $targetUid, [
    'available_balance' => $afterAvailable,
    'updated_at' => $now,
]);

if (!$targetCreditOk) {
    if ($isSubadminTransfer) {
        fb_patch('USER_WALLETS/' . $actorUid, [
            'available_balance' => $actorBeforeAvailable,
            'updated_at' => $now,
        ]);
    }

    wallet_add_response(false, 'SERVER_ERROR', 'Failed to update target wallet balance', [], 500);
}

$baseNote = $note !== '' ? $note : 'Balance added';
$finalNote = $baseNote;
$finalNote .= ' | Base: BDT ' . number_format($amount, 2, '.', '');
$finalNote .= ' | Total Credit: BDT ' . number_format($totalCredit, 2, '.', '');

$transferType = $isSubadminTransfer ? 'SUBADMIN_BALANCE_TRANSFER' : 'ADMIN_BALANCE_ADD';
$transfer = [
    'transfer_id' => $transferId,
    'ledger_id' => $ledgerId,
    'receiver_ledger_id' => $ledgerId,
    'sender_ledger_id' => $actorLedgerId,
    'type' => $transferType,
    'direction' => 'CREDIT',
    'amount' => $totalCredit,
    'currency' => 'BDT',
    'sender_uid' => $senderIdentity['uid'],
    'sender_name' => $senderIdentity['name'],
    'sender_phone' => $senderIdentity['phone'],
    'sender_role' => $senderIdentity['role'],
    'receiver_uid' => $receiverIdentity['uid'],
    'receiver_name' => $receiverIdentity['name'],
    'receiver_phone' => $receiverIdentity['phone'],
    'receiver_role' => $receiverIdentity['role'],
    'before_balance' => $beforeAvailable,
    'after_balance' => $afterAvailable,
    'before_available' => $beforeAvailable,
    'after_available' => $afterAvailable,
    'before_hold' => $beforeHold,
    'after_hold' => $beforeHold,
    'receiver_before_available' => $beforeAvailable,
    'receiver_after_available' => $afterAvailable,
    'receiver_before_hold' => $beforeHold,
    'receiver_after_hold' => $beforeHold,
    'sender_before_available' => $actorBeforeAvailable,
    'sender_after_available' => $actorAfterAvailable,
    'sender_before_hold' => $actorBeforeHold,
    'sender_after_hold' => $actorAfterHold,
    'sender_wallet_debited' => $isSubadminTransfer,
    'note' => $baseNote,
    'reference' => $reference !== '' ? $reference : $transferId,
    'ref_id' => $reference !== '' ? $reference : $transferId,
    'created_by_uid' => $actorUid,
    'created_by_role' => $actorRole,
    'created_at' => $now,
    'updated_at' => $now,
    'source' => $isSubadminTransfer ? 'SUBADMIN_PANEL' : 'ADMIN_PANEL',
    'status' => 'SUCCESS',
    'commission_per_1000' => 0.0,
    'commission_amount' => 0.0,
];

$receiverLedger = wallet_transfer_history_row($transfer, 'CREDIT', $ledgerId);
$receiverLedger['legacy_type'] = $isSubadminTransfer
    ? 'SUBADMIN_CREDIT_WITH_COMMISSION'
    : 'ADMIN_CREDIT_WITH_COMMISSION';
$receiverLedger['base_amount'] = $amount;
$receiverLedger['total_credit'] = $totalCredit;
$receiverLedger['note'] = $finalNote;
$receiverLedger['ref_id'] = $transfer['reference'];

$ledgerRows = [[
    'uid' => $targetUid,
    'row' => $receiverLedger,
]];

if ($isSubadminTransfer) {
    $senderLedger = wallet_transfer_history_row($transfer, 'DEBIT', $actorLedgerId);
    $senderLedger['legacy_type'] = 'SUBADMIN_TRANSFER_OUT';
    $senderLedger['base_amount'] = $amount;
    $senderLedger['total_debit'] = $totalCredit;
    $senderLedger['target_uid'] = $targetUid;
    $senderLedger['target_name'] = $receiverIdentity['name'];
    $senderLedger['target_phone'] = $receiverIdentity['phone'];
    $senderLedger['note'] = 'Balance transferred to user/retailer | ' . $finalNote;
    $senderLedger['ref_id'] = $transfer['reference'];
    $ledgerRows[] = [
        'uid' => $actorUid,
        'row' => $senderLedger,
    ];
}

$historyResult = wallet_store_transfer_records($transfer, $ledgerRows);
if (!($historyResult['ok'] ?? false)) {
    $receiverRolledBack = wallet_restore_available_balance($targetUid, $afterAvailable, $beforeAvailable);
    $senderRolledBack = true;

    if ($isSubadminTransfer) {
        $senderRolledBack = wallet_restore_available_balance(
            $actorUid,
            $actorAfterAvailable,
            $actorBeforeAvailable
        );
    }

    if (function_exists('system_log')) {
        system_log('WALLET_TRANSFER_HISTORY_FAILED', $transferId, 'Wallet transfer history write failed', [
            'target_uid' => $targetUid,
            'actor_uid' => $actorUid,
            'history_code' => (string)($historyResult['code'] ?? ''),
            'receiver_rolled_back' => $receiverRolledBack,
            'sender_rolled_back' => $senderRolledBack,
        ]);
    }

    wallet_add_response(
        false,
        'TRANSFER_HISTORY_FAILED',
        $receiverRolledBack && $senderRolledBack
            ? 'Balance transfer was rolled back because history could not be saved'
            : 'Balance history failed and wallet rollback requires review',
        ['transfer_id' => $transferId],
        500
    );
}

if (function_exists('system_log')) {
    system_log('WALLET_ADD_BALANCE', $ledgerId, 'Balance added by subadmin/admin', [
        'transfer_id' => $transferId,
        'target_uid' => $targetUid,
        'target_role' => $targetRole,
        'base_amount' => $amount,
        'commission_per_1000' => $commissionPer1000,
        'commission_amount' => $commissionAmount,
        'total_credit' => $totalCredit,
        'currency' => 'BDT',
        'created_by_uid' => $actorUid,
        'created_by_role' => $actorRole,
        'subadmin_debited' => $isSubadminTransfer,
        'actor_ledger_id' => $actorLedgerId,
    ]);
}

wallet_add_response(true, 'SUCCESS', 'Balance added successfully', [
    'ledger_id' => $ledgerId,
    'actor_ledger_id' => $actorLedgerId,
    'transfer_id' => $transferId,

    'target_uid' => $targetUid,
    'target_name' => (string)($targetUser['name'] ?? ''),
    'target_phone' => (string)($targetUser['phone'] ?? ''),

    'amount' => $amount,
    'base_amount' => $amount,
    'commission_per_1000' => $commissionPer1000,
    'commission_amount' => $commissionAmount,
    'total_credit' => $totalCredit,
    'currency' => 'BDT',
    'reference' => $transfer['reference'],

    'before_available' => $beforeAvailable,
    'after_available' => $afterAvailable,

    'subadmin_debited' => $isSubadminTransfer,
    'subadmin_before_available' => $actorBeforeAvailable,
    'subadmin_after_available' => $actorAfterAvailable,

    'created_at' => $now,
]);
