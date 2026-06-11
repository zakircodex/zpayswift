<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = is_array($auth['user'] ?? null) ? $auth['user'] : [];

$body = api_read_json_body();

function admin_wallet_float_value($value, float $default = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return round((float)$value, 2);
    }

    $value = trim((string)$value);
    if ($value === '') {
        return $default;
    }

    $value = str_replace(',', '', $value);

    if (!is_numeric($value)) {
        return $default;
    }

    return round((float)$value, 2);
}

function admin_wallet_round_money(float $amount): float
{
    return round($amount, 2);
}

function admin_wallet_safe_note(string $note): string
{
    $note = trim($note);

    if ($note === '') {
        return 'Admin balance added';
    }

    return $note;
}

$uid = trim((string)($body['uid'] ?? ''));
$amount = admin_wallet_float_value($body['amount'] ?? 0, 0.0);
$note = admin_wallet_safe_note((string)($body['note'] ?? 'Admin balance added'));
$reference = trim((string)($body['reference'] ?? ''));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', [], 422);
}

if ($amount <= 0) {
    api_response(false, 'VALIDATION_ERROR', 'amount must be greater than zero', [], 422);
}

$user = fb_get('USERS/' . $uid);
if (!is_array($user)) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$userStatus = strtoupper(trim((string)($user['status'] ?? '')));
if ($userStatus !== 'ACTIVE') {
    api_response(false, 'FORBIDDEN', 'User account is not active', [], 403);
}

$commissionPer1000 = 0.0;
$commissionAmount = 0.0;
$totalCredit = admin_wallet_round_money($amount);

$refId = 'ADMIN_CREDIT_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$transferId = wallet_make_transfer_id();
$now = wallet_now();
$targetWallet = get_user_wallet($uid) ?? [];
$receiverIdentity = wallet_identity($uid, $user, (string)($user['role'] ?? 'USER'));
$senderUid = trim((string)($adminUser['uid'] ?? ''));
$senderIdentity = wallet_identity($senderUid, $adminUser, 'ADMIN');

$finalNote = $note;

$finalNote .= ' | Base: BDT ' . number_format($amount, 2, '.', '');
$finalNote .= ' | Total Credit: BDT ' . number_format($totalCredit, 2, '.', '');

$res = wallet_admin_add_balance($uid, $totalCredit, $refId, $finalNote, [
    'type' => 'WALLET_CREDIT',
    'transfer_id' => $transferId,
    'transfer_type' => 'ADMIN_BALANCE_ADD',
    'legacy_type' => 'ADMIN_CREDIT',
    'sender_uid' => $senderIdentity['uid'],
    'sender_name' => $senderIdentity['name'],
    'sender_phone' => $senderIdentity['phone'],
    'sender_role' => $senderIdentity['role'],
    'receiver_uid' => $receiverIdentity['uid'],
    'receiver_name' => $receiverIdentity['name'],
    'receiver_phone' => $receiverIdentity['phone'],
    'receiver_role' => $receiverIdentity['role'],
    'reference' => $reference,
    'created_by_uid' => $senderIdentity['uid'],
    'created_by_role' => $senderIdentity['role'],
    'created_at' => $now,
    'updated_at' => $now,
    'source' => 'ADMIN_PANEL',
    'status' => 'SUCCESS',
]);

if (!($res['ok'] ?? false)) {
    api_response(
        false,
        (string)($res['code'] ?? 'SERVER_ERROR'),
        (string)($res['message'] ?? 'Failed to add balance'),
        [],
        500
    );
}

$ledgerId = trim((string)($res['ledger_id'] ?? ''));
$beforeAvailable = admin_wallet_round_money((float)($res['before_available'] ?? 0));
$afterAvailable = admin_wallet_round_money((float)($res['after_available'] ?? $res['available_balance'] ?? 0));
$beforeHold = admin_wallet_round_money((float)($targetWallet['hold_balance'] ?? $res['hold_balance'] ?? 0));
$afterHold = admin_wallet_round_money((float)($res['hold_balance'] ?? $beforeHold));

$savedLedger = $ledgerId !== ''
    ? fb_get('WALLET_LEDGER/' . $uid . '/' . wallet_month_key($now) . '/' . $ledgerId)
    : null;
if (!is_array($savedLedger)) {
    $rolledBack = wallet_restore_available_balance($uid, $afterAvailable, $beforeAvailable);
    api_response(
        false,
        'LEDGER_WRITE_FAILED',
        $rolledBack
            ? 'Balance add was rolled back because ledger could not be saved'
            : 'Wallet ledger failed and wallet rollback requires review',
        ['transfer_id' => $transferId],
        500
    );
}

$transfer = [
    'transfer_id' => $transferId,
    'ledger_id' => $ledgerId,
    'receiver_ledger_id' => $ledgerId,
    'sender_ledger_id' => '',
    'type' => 'ADMIN_BALANCE_ADD',
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
    'after_hold' => $afterHold,
    'receiver_before_available' => $beforeAvailable,
    'receiver_after_available' => $afterAvailable,
    'receiver_before_hold' => $beforeHold,
    'receiver_after_hold' => $afterHold,
    'sender_before_available' => 0.0,
    'sender_after_available' => 0.0,
    'sender_before_hold' => 0.0,
    'sender_after_hold' => 0.0,
    'sender_wallet_debited' => false,
    'note' => $note,
    'reference' => $reference !== '' ? $reference : $refId,
    'ref_id' => $refId,
    'created_by_uid' => $senderIdentity['uid'],
    'created_by_role' => $senderIdentity['role'],
    'created_at' => $now,
    'updated_at' => $now,
    'source' => 'ADMIN_PANEL',
    'status' => 'SUCCESS',
    'commission_per_1000' => 0.0,
    'commission_amount' => 0.0,
];

$historyResult = wallet_store_transfer_records($transfer);
if (!($historyResult['ok'] ?? false)) {
    $rolledBack = wallet_restore_available_balance($uid, $afterAvailable, $beforeAvailable);
    wallet_delete_ledger_record($uid, $now, $ledgerId);

    if (function_exists('system_log')) {
        system_log('ADMIN_BALANCE_HISTORY_FAILED', $transferId, 'Admin balance history write failed', [
            'uid' => $uid,
            'ledger_id' => $ledgerId,
            'history_code' => (string)($historyResult['code'] ?? ''),
            'wallet_rolled_back' => $rolledBack,
        ]);
    }

    api_response(
        false,
        'TRANSFER_HISTORY_FAILED',
        $rolledBack
            ? 'Balance add was rolled back because history could not be saved'
            : 'Balance history failed and wallet rollback requires review',
        ['transfer_id' => $transferId],
        500
    );
}

$logData = [
    'transfer_id' => $transferId,
    'ledger_id' => $ledgerId,
    'uid' => $uid,
    'name' => (string)($user['name'] ?? ''),
    'phone' => (string)($user['phone'] ?? ''),
    'role' => (string)($user['role'] ?? ''),
    'base_amount' => $amount,
    'commission_per_1000' => $commissionPer1000,
    'commission_amount' => $commissionAmount,
    'total_credit' => $totalCredit,
    'currency' => 'BDT',
    'note' => $note,
    'reference' => $reference,
    'final_note' => $finalNote,
    'ref_id' => $refId,
];

if (function_exists('admin_action_log')) {
    admin_action_log('ADD_BALANCE', $uid, 'Admin added balance', $logData);
}

if (function_exists('system_log')) {
    system_log('ADMIN_ADD_BALANCE', $uid, 'Admin added balance', $logData);
}

api_response(true, 'SUCCESS', 'Balance added successfully', [
    'uid' => $uid,
    'name' => (string)($user['name'] ?? ''),
    'phone' => (string)($user['phone'] ?? ''),
    'base_amount' => $amount,
    'commission_per_1000' => $commissionPer1000,
    'commission_amount' => $commissionAmount,
    'total_credit' => $totalCredit,
    'currency' => 'BDT',
    'available_balance' => (float)($res['available_balance'] ?? 0),
    'ref_id' => $refId,
    'reference' => $reference,
    'transfer_id' => $transferId,
    'ledger_id' => $ledgerId,
]);
