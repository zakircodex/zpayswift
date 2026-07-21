<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';
require_once dirname(__DIR__, 2) . '/lib/notifications.php';

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

$now = wallet_now();
$targetWallet = get_user_wallet($uid) ?? [];
$receiverCurrency = wallet_account_currency($user, $targetWallet);
$receiverCountry = wallet_account_country_code($user, $targetWallet);
$receiverIdentity = wallet_identity($uid, $user, (string)($user['role'] ?? 'USER'));
$senderUid = trim((string)($adminUser['uid'] ?? ''));
$senderIdentity = wallet_identity($senderUid, $adminUser, 'ADMIN');

$operationSeed = trim((string)($body['idempotency_key'] ?? $body['client_request_id'] ?? $body['action_id'] ?? $reference));
if ($operationSeed === '') {
    $operationSeed = hash('sha256', implode('|', [
        'ADMIN_BALANCE_ADD',
        $senderUid,
        $uid,
        number_format($totalCredit, 2, '.', ''),
        $receiverCurrency,
        $note,
        (string)floor($now / 120),
    ]));
}
$operationRef = 'ADMIN_WALLET_ADD:' . hash('sha256', implode('|', [$senderUid, $uid, $operationSeed]));
$refId = 'ADMIN_CREDIT_' . strtoupper(substr(hash('sha256', $operationRef), 0, 22));
$transferId = 'WTR' . strtoupper(substr(hash('sha256', $operationRef . '|TRANSFER'), 0, 24));
$operation = wallet_financial_operation_begin(
    $operationRef,
    'ADMIN_BALANCE_ADD',
    'REQUEST_FINAL',
    $uid,
    $totalCredit,
    $receiverCurrency,
    [
        'actor_uid' => $senderUid,
        'target_uid' => $uid,
        'transfer_id' => $transferId,
        'source' => 'ADMIN_PANEL_LEGACY_ENDPOINT',
    ]
);
if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
    $resultData = is_array($operation['operation']['result_data'] ?? null)
        ? (array)$operation['operation']['result_data']
        : [];
    api_response(true, 'SUCCESS', 'Balance added successfully', $resultData);
}
if (empty($operation['ok']) || empty($operation['claim'])) {
    api_response(
        false,
        (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'),
        (string)($operation['message'] ?? 'Wallet operation is unavailable'),
        [],
        409
    );
}
$financialClaim = (array)$operation['claim'];
$recoveredTransferId = trim((string)(
    $financialClaim['transfer']['transfer_id']
    ?? $financialClaim['wallet_marker']['ledger_row']['transfer_id']
    ?? ''
));
if ($recoveredTransferId !== '') {
    $transferId = $recoveredTransferId;
}

$finalNote = $note;

$finalNote .= ' | Base: ' . $receiverCurrency . ' ' . number_format($amount, 2, '.', '');
$finalNote .= ' | Total Credit: ' . $receiverCurrency . ' ' . number_format($totalCredit, 2, '.', '');

$ledgerId = wallet_financial_operation_side_ledger_id($operationRef, 'ADMIN_BALANCE_ADD', 'target_credited');
$res = wallet_credit_available($uid, $totalCredit, $operationRef, 'ADMIN_CREDIT', $finalNote, [
    'ledger_id' => $ledgerId,
    'type' => 'WALLET_CREDIT',
    'currency' => $receiverCurrency,
    'wallet_currency' => $receiverCurrency,
    'country_code' => $receiverCountry,
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
], [
    'financial_operation' => $financialClaim,
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

$ledgerId = trim((string)($res['ledger_id'] ?? $ledgerId));
$beforeAvailable = admin_wallet_round_money((float)($res['before_available'] ?? 0));
$afterAvailable = admin_wallet_round_money((float)($res['after_available'] ?? $res['available_balance'] ?? 0));
$beforeHold = admin_wallet_round_money((float)($targetWallet['hold_balance'] ?? $res['hold_balance'] ?? 0));
$afterHold = admin_wallet_round_money((float)($res['hold_balance'] ?? $beforeHold));

$savedLedger = $ledgerId !== ''
    ? fb_get('WALLET_LEDGER/' . $uid . '/' . wallet_month_key($now) . '/' . $ledgerId)
    : null;
if (!is_array($savedLedger)) {
    wallet_financial_operation_mark_reconciliation_required($financialClaim, 'LEDGER_EVIDENCE_MISSING', 'Wallet credit succeeded but deterministic ledger evidence is missing');
    api_response(
        false,
        'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
        'Wallet ledger requires reconciliation',
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
    'currency' => $receiverCurrency,
    'receiver_currency' => $receiverCurrency,
    'receiver_country_code' => $receiverCountry,
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
    wallet_financial_operation_mark_failed($financialClaim, 'TRANSFER_HISTORY_FAILED', 'Admin wallet history write failed', [
        'wallet_applied' => true,
        'target_credited' => true,
        'ledger_written' => true,
        'transfer' => $transfer,
    ]);

    if (function_exists('system_log')) {
        system_log('ADMIN_BALANCE_HISTORY_FAILED', $transferId, 'Admin balance history write failed', [
            'uid' => $uid,
            'ledger_id' => $ledgerId,
            'history_code' => (string)($historyResult['code'] ?? ''),
            'recovery_state' => 'FAILED_RETRYABLE',
        ]);
    }

    api_response(
        false,
        'TRANSFER_HISTORY_FAILED',
        'Balance updated but history finalization must be retried',
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
    'currency' => $receiverCurrency,
    'note' => $note,
    'reference' => $reference,
    'final_note' => $finalNote,
    'ref_id' => $refId,
];

$notification = notification_record_user(
    $uid,
    'WALLET_CREDIT',
    'Wallet Credited',
    'Your wallet was credited with ' . $receiverCurrency . ' ' . number_format($totalCredit, 2, '.', '') . '.',
    'WALLET_LEDGER',
    $ledgerId !== '' ? $ledgerId : $transferId,
    'WALLET_CREDIT:' . ($ledgerId !== '' ? $ledgerId : $transferId),
    [
        'transfer_id' => $transferId,
        'request_id' => $refId,
    ]
);

$responseData = [
    'uid' => $uid,
    'name' => (string)($user['name'] ?? ''),
    'phone' => (string)($user['phone'] ?? ''),
    'base_amount' => $amount,
    'commission_per_1000' => $commissionPer1000,
    'commission_amount' => $commissionAmount,
    'total_credit' => $totalCredit,
    'currency' => $receiverCurrency,
    'wallet_currency' => $receiverCurrency,
    'country_code' => $receiverCountry,
    'available_balance' => (float)($res['available_balance'] ?? 0),
    'ref_id' => $refId,
    'reference' => $reference,
    'transfer_id' => $transferId,
    'ledger_id' => $ledgerId,
];

if (!wallet_financial_operation_mark_completed($financialClaim, [
    'wallet_applied' => true,
    'target_credited' => true,
    'ledger_written' => true,
    'request_finalized' => true,
    'history_written' => true,
    'notification_written' => !empty($notification['ok']),
    'result_data' => $responseData,
])) {
    api_response(false, 'FINANCIAL_OPERATION_FINALIZATION_FAILED', 'Balance updated but operation finalization must be retried', [
        'transfer_id' => $transferId,
    ], 500);
}

if (function_exists('admin_action_log')) {
    admin_action_log('ADD_BALANCE', $uid, 'Admin added balance', $logData);
}

if (function_exists('system_log')) {
    system_log('ADMIN_ADD_BALANCE', $uid, 'Admin added balance', $logData);
}

api_response(true, 'SUCCESS', 'Balance added successfully', $responseData);
