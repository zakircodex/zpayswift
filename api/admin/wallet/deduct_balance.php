<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';

api_require_method('POST');
auth_require_admin_session();

$body = api_read_json_body();

api_response(false, 'OTP_REQUIRED', 'Balance deduction requires the OTP confirmation flow.', [
    'send_otp_endpoint' => 'wallet_deduct_send_otp.php',
    'confirm_endpoint' => 'wallet_deduct_confirm.php',
], 409);

$uid = trim((string)($body['uid'] ?? ''));
$amount = (float)($body['amount'] ?? 0);
$note = trim((string)($body['note'] ?? 'Admin balance deducted'));

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

$refId = 'ADMIN_DEBIT_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$res = wallet_admin_deduct_balance($uid, $amount, $refId, $note);

if (!($res['ok'] ?? false)) {
    $code = (string)($res['code'] ?? 'SERVER_ERROR');

    if ($code === 'INSUFFICIENT_BALANCE') {
        api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough available balance', [
            'available_balance' => (float)($res['available_balance'] ?? 0),
            'required_amount' => (float)($res['required_amount'] ?? $amount),
        ], 422);
    }

    api_response(false, $code, (string)($res['message'] ?? 'Failed to deduct balance'), [], 500);
}

admin_action_log('DEDUCT_BALANCE', $uid, 'Admin deducted balance', [
    'amount' => $amount,
    'note' => $note,
]);

system_log('ADMIN_DEDUCT_BALANCE', $uid, 'Admin deducted balance', [
    'amount' => $amount,
    'note' => $note,
]);

api_response(true, 'SUCCESS', 'Balance deducted successfully', [
    'uid' => $uid,
    'available_balance' => (float)($res['available_balance'] ?? 0),
]);
