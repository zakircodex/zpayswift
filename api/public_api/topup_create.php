<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/subadmin_api.php';
require_once dirname(__DIR__) . '/lib/topup.php';

api_require_method('POST');

$auth = subapi_authenticate_request();

$uid = trim((string)($auth['uid'] ?? ''));
$keyId = trim((string)($auth['key_id'] ?? ''));
$user = (array)($auth['user'] ?? []);
$roleSettings = (array)($auth['role_settings'] ?? []);
$wallet = (array)($auth['wallet'] ?? []);

if ($uid === '' || $keyId === '') {
    api_response(false, 'AUTH_ERROR', 'Invalid authenticated API user', [], 401);
}

$topupEnabled = (bool)($roleSettings['topup_enabled'] ?? false);
if (!$topupEnabled) {
    api_response(false, 'TOPUP_DISABLED', 'Topup is disabled for this account', [], 403);
}

$body = api_read_json_body();

$topupNumber = trim((string)($body['topup_number'] ?? ''));
$operator = strtoupper(trim((string)($body['operator'] ?? '')));
$amount = (float)($body['amount'] ?? 0);
$note = trim((string)($body['note'] ?? 'API topup request'));

if ($topupNumber === '') {
    api_response(false, 'VALIDATION_ERROR', 'topup_number is required', ['field' => 'topup_number'], 422);
}

if ($operator === '') {
    api_response(false, 'VALIDATION_ERROR', 'operator is required', ['field' => 'operator'], 422);
}

$allowedOperators = ['GP', 'ROBI', 'AIRTEL', 'BL', 'TT'];
if (!in_array($operator, $allowedOperators, true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid operator', ['field' => 'operator'], 422);
}

[$amountOk, $amountMsg] = subapi_validate_amount_limits($amount, $roleSettings);
if (!$amountOk) {
    api_response(false, 'VALIDATION_ERROR', $amountMsg, ['field' => 'amount'], 422);
}

$currentAvailable = (float)($wallet['available_balance'] ?? 0);
$currentHold = (float)($wallet['hold_balance'] ?? 0);
$financials = topup_commission_breakdown($uid, $amount, $user, $roleSettings);
$walletDebit = (float)$financials['wallet_debit_bdt'];

if ($currentAvailable < $walletDebit) {
    api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough available balance', [
        'available_balance' => $currentAvailable,
        'required_amount' => $walletDebit,
    ], 422);
}

$requestId = make_uid();
$now = now_ts();
$userPhone = trim((string)($user['phone'] ?? ''));
$newAvailable = round($currentAvailable - $walletDebit, 2);
$newHold = round($currentHold + $walletDebit, 2);

/*
|--------------------------------------------------------------------------
| Hold balance first
|--------------------------------------------------------------------------
*/
$walletHoldOk = fb_patch('USER_WALLETS/' . $uid, [
    'available_balance' => $newAvailable,
    'hold_balance' => $newHold,
    'updated_at' => $now,
]);

if (!$walletHoldOk) {
    api_response(false, 'SERVER_ERROR', 'Failed to hold wallet balance', [], 500);
}

/*
|--------------------------------------------------------------------------
| Create pending topup request
|--------------------------------------------------------------------------
*/
$requestRow = [
    'request_id' => $requestId,
    'uid' => $uid,
    'user_phone' => $userPhone,
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'commission_amount' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $walletDebit,
    'total_debit' => $walletDebit,
    'charged_amount' => $walletDebit,
    'request_pin_verified' => true,
    'wallet_hold_amount' => $walletDebit,
    'held_amount' => $walletDebit,
    'status' => 'PENDING',
    'assigned_device_id' => '',
    'assigned_slot' => '',
    'created_at' => $now,
    'updated_at' => $now,
    'source' => 'SUBADMIN_API',
    'source_key_id' => $keyId,
    'note' => $note,
    'final_message' => '',
];

$requestSaved = fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $requestRow);

if (!$requestSaved) {
    fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $currentAvailable,
        'hold_balance' => $currentHold,
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'SERVER_ERROR', 'Failed to create topup request', [], 500);
}

/*
|--------------------------------------------------------------------------
| Create request status
|--------------------------------------------------------------------------
*/
fb_put('REQUEST_STATUS/' . $requestId, [
    'request_id' => $requestId,
    'type' => 'TOPUP',
    'uid' => $uid,
    'status' => 'PENDING',
    'message' => 'Topup request created via subadmin API',
    'updated_at' => $now,
]);

/*
|--------------------------------------------------------------------------
| Wallet ledger entry
|--------------------------------------------------------------------------
*/
$ledgerId = make_uid();
$ledgerMonth = date('Y-m', (int)$now);

fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, [
    'ledger_id' => $ledgerId,
    'uid' => $uid,
    'type' => 'API_TOPUP_HOLD',
    'direction' => 'HOLD',
    'amount' => $walletDebit,
    'topup_amount_bdt' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $walletDebit,
    'before_available' => $currentAvailable,
    'after_available' => $newAvailable,
    'before_hold' => $currentHold,
    'after_hold' => $newHold,
    'ref_id' => $requestId,
    'key_id' => $keyId,
    'note' => 'Balance moved to hold for API topup request',
    'created_at' => $now,
]);

/*
|--------------------------------------------------------------------------
| Request log
|--------------------------------------------------------------------------
*/
subapi_log_request($uid, [
    'request_id' => $requestId,
    'key_id' => $keyId,
    'action' => 'TOPUP_CREATE',
    'request_type' => 'TOPUP',
    'status' => 'PENDING',
    'operator' => $operator,
    'topup_number' => $topupNumber,
    'amount' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $walletDebit,
    'message' => 'Topup request created via subadmin API',
    'note' => $note,
    'created_at' => $now,
    'updated_at' => $now,
]);

if (function_exists('system_log')) {
    system_log('SUBAPI_TOPUP_CREATE', $requestId, 'Topup request created via subadmin API', [
        'uid' => $uid,
        'key_id' => $keyId,
        'operator' => $operator,
        'topup_number' => $topupNumber,
        'amount' => $amount,
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'wallet_debit_bdt' => $walletDebit,
        'note' => $note,
    ]);
}

topup_notify_telegram_request($requestRow);

api_response(true, 'SUCCESS', 'Topup request created successfully', [
    'request_id' => $requestId,
    'uid' => $uid,
    'status' => 'PENDING',
    'operator' => $operator,
    'topup_number' => $topupNumber,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $walletDebit,
    'total_debit' => $walletDebit,
    'created_at' => $now,
    'wallet' => [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
    ],
]);
