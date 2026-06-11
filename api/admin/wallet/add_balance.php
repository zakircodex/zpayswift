<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';

api_require_method('POST');
auth_require_admin_session();

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

$finalNote = $note;

$finalNote .= ' | Base: BDT ' . number_format($amount, 2, '.', '');
$finalNote .= ' | Total Credit: BDT ' . number_format($totalCredit, 2, '.', '');

$res = wallet_admin_add_balance($uid, $totalCredit, $refId, $finalNote);

if (!($res['ok'] ?? false)) {
    api_response(
        false,
        (string)($res['code'] ?? 'SERVER_ERROR'),
        (string)($res['message'] ?? 'Failed to add balance'),
        [],
        500
    );
}

$logData = [
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
]);
