<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/subadmin_api.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/topup_config.php';

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

$countryCode = topup_country_code($body['country_code'] ?? $body['country'] ?? 'BD');
$topupNumber = topup_normalize_number_for_country($countryCode, $body['topup_number'] ?? $body['number'] ?? '');
$operator = normalize_operator($body['operator'] ?? $body['operator_code'] ?? '');
$amount = (float)($body['amount'] ?? 0);
$note = trim((string)($body['note'] ?? 'API topup request'));

if (!topup_is_valid_number_for_country($countryCode, $topupNumber)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid topup number', ['field' => 'topup_number'], 422);
}

if ($operator === '') {
    api_response(false, 'VALIDATION_ERROR', 'operator is required', ['field' => 'operator'], 422);
}

[$amountOk, $amountMsg] = subapi_validate_amount_limits($amount, $roleSettings);
if (!$amountOk) {
    api_response(false, 'VALIDATION_ERROR', $amountMsg, ['field' => 'amount'], 422);
}

$topupValidation = topup_validate_request($countryCode, $operator, topup_money($amount), true, true);
if (empty($topupValidation['ok'])) {
    topup_api_error($topupValidation);
}

$currentAvailable = (float)($wallet['available_balance'] ?? 0);
$currentHold = (float)($wallet['hold_balance'] ?? 0);
$financials = topup_calculate_payment_context($uid, topup_money($amount), $user, $wallet, $roleSettings, $countryCode);
if (empty($financials['ok'])) {
    api_response(false, (string)($financials['code'] ?? 'TOPUP_CALCULATION_FAILED'), (string)($financials['message'] ?? 'Topup calculation failed'), [], 422);
}
$walletDebit = (float)$financials['wallet_debit_amount'];

if ($currentAvailable < $walletDebit) {
    api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough available balance', [
        'available_balance' => $currentAvailable,
        'required_amount' => $walletDebit,
    ], 422);
}

$requestId = make_uid();
$now = now_ts();
$userPhone = trim((string)($user['phone'] ?? ''));
$operationSeed = trim((string)($body['idempotency_key'] ?? $body['client_request_id'] ?? $body['request_reference'] ?? $body['reference_id'] ?? ''));
if ($operationSeed === '') {
    $operationSeed = hash('sha256', implode('|', [
        'API_TOPUP_CREATE',
        $keyId,
        $uid,
        $countryCode,
        $operator,
        $topupNumber,
        number_format($amount, 2, '.', ''),
        number_format($walletDebit, 2, '.', ''),
        (string)floor($now / 120),
    ]));
}
$operationRef = 'API_TOPUP_CREATE:' . hash('sha256', implode('|', [$keyId, $uid, $operationSeed]));
$operation = wallet_financial_operation_begin($operationRef, 'API_TOPUP_CREATE_HOLD', 'REQUEST_CREATE', $uid, $walletDebit, (string)$financials['wallet_debit_currency'], [
    'request_id' => $requestId,
    'key_id' => $keyId,
    'operator' => $operator,
    'topup_number_hash' => hash('sha256', $topupNumber),
]);
if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
    $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
    api_response(true, 'SUCCESS', 'Topup request created successfully', $resultData);
}
if (empty($operation['ok']) || empty($operation['claim'])) {
    api_response(false, (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'), (string)($operation['message'] ?? 'Wallet operation is unavailable'), [], 409);
}
$financialClaim = $operation['claim'];
$requestId = trim((string)($financialClaim['meta']['request_id'] ?? $requestId));

/*
|--------------------------------------------------------------------------
| Hold balance first
|--------------------------------------------------------------------------
*/
$hold = wallet_hold_amount($uid, $walletDebit, $operationRef, 'API_TOPUP_HOLD', [
    'financial_operation' => $financialClaim,
    'ledger_extra' => [
        'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'API_TOPUP_CREATE_HOLD'),
        'request_id' => $requestId,
        'ref_id' => $requestId,
        'key_id' => $keyId,
        'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
        'topup_currency' => (string)($financials['topup_currency'] ?? ($countryCode === 'MY' ? 'MYR' : 'BDT')),
        'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
        'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
        'account_country' => (string)($financials['account_country'] ?? ''),
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
        'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
        'commission_amount' => (float)($financials['commission_amount'] ?? $financials['commission_bdt'] ?? 0),
        'commission_credit' => (float)($financials['commission_credit'] ?? 0),
        'fee_amount' => (float)($financials['fee_amount'] ?? 0),
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
        'rate_snapshot' => $financials['rate_snapshot'] ?? null,
        'rate_used' => $financials['rate_used'],
        'calculation_version' => (string)($financials['calculation_version'] ?? ''),
        'note' => 'Balance moved to hold for API topup request',
    ],
]);

if (empty($hold['ok'])) {
    api_response(false, (string)($hold['code'] ?? 'SERVER_ERROR'), (string)($hold['message'] ?? 'Failed to hold wallet balance'), [], 500);
}

$newAvailable = (float)($hold['available_balance'] ?? $hold['after_available'] ?? round($currentAvailable - $walletDebit, 2));
$newHold = (float)($hold['hold_balance'] ?? $hold['after_hold'] ?? round($currentHold + $walletDebit, 2));

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
    'country_code' => $countryCode,
    'execution_mode' => function_exists('topup_operator_execution_mode') ? topup_operator_execution_mode($countryCode, $operator) : 'WORKER_USSD',
    'worker_claimable' => function_exists('topup_operator_worker_claimable') ? topup_operator_worker_claimable($countryCode, $operator) : true,
    'WORKER_CLAIMABLE' => function_exists('topup_operator_worker_claimable') ? topup_operator_worker_claimable($countryCode, $operator) : true,
    'manual_telegram_required' => function_exists('topup_operator_worker_claimable') ? !topup_operator_worker_claimable($countryCode, $operator) : false,
    'amount' => $amount,
    'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
    'topup_currency' => (string)($financials['topup_currency'] ?? ($countryCode === 'MY' ? 'MYR' : 'BDT')),
    'amount_bdt' => (float)($financials['amount_bdt'] ?? 0),
    'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
    'amount_myr' => (float)($financials['amount_myr'] ?? 0),
    'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
    'account_country' => (string)($financials['account_country'] ?? ''),
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'commission_amount' => $financials['commission_bdt'],
    'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
    'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
    'commission_credit' => (float)($financials['commission_credit'] ?? 0),
    'fee_amount' => (float)($financials['fee_amount'] ?? 0),
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'wallet_currency' => $financials['wallet_currency'],
    'display_currency' => (string)($financials['display_currency'] ?? $financials['wallet_currency']),
    'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
    'rate_snapshot' => $financials['rate_snapshot'] ?? null,
    'rate_used' => $financials['rate_used'],
    'balance_before' => $currentAvailable,
    'balance_after' => $newAvailable,
    'calculation_version' => (string)($financials['calculation_version'] ?? ''),
    'total_debit_bdt' => $financials['total_debit_bdt'],
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
    wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_CREATE_FAILED', 'Topup request could not be saved after wallet hold', [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_id' => $requestId,
        'request_row' => $requestRow,
        'request_finalized' => false,
    ]);

    api_response(false, 'SERVER_ERROR', 'Failed to create topup request', ['request_id' => $requestId], 500);
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

topup_write_history($requestRow);

/*
|--------------------------------------------------------------------------
| Wallet ledger entry
|--------------------------------------------------------------------------
*/
$ledgerId = (string)($hold['ledger_id'] ?? '');

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
    'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
    'topup_currency' => (string)($financials['topup_currency'] ?? ($countryCode === 'MY' ? 'MYR' : 'BDT')),
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
    'rate_snapshot' => $financials['rate_snapshot'] ?? null,
    'rate_used' => $financials['rate_used'],
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
        'topup_number_masked' => topup_mask_number($topupNumber),
        'amount' => $amount,
        'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
        'topup_currency' => (string)($financials['topup_currency'] ?? ($countryCode === 'MY' ? 'MYR' : 'BDT')),
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'rate_used' => $financials['rate_used'],
        'note' => $note,
    ]);
}

topup_notify_telegram_request($requestRow);

$responseData = [
    'request_id' => $requestId,
    'uid' => $uid,
    'status' => 'PENDING',
    'operator' => $operator,
    'topup_number' => $topupNumber,
    'amount' => $amount,
    'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
    'topup_currency' => (string)($financials['topup_currency'] ?? ($countryCode === 'MY' ? 'MYR' : 'BDT')),
    'amount_bdt' => (float)($financials['amount_bdt'] ?? 0),
    'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
    'amount_myr' => (float)($financials['amount_myr'] ?? 0),
    'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'wallet_currency' => $financials['wallet_currency'],
    'account_country' => (string)($financials['account_country'] ?? ''),
    'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
    'rate_snapshot' => $financials['rate_snapshot'] ?? null,
    'rate_used' => $financials['rate_used'],
    'total_debit' => $walletDebit,
    'created_at' => $now,
    'wallet' => [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
    ],
];

wallet_financial_operation_mark_completed($financialClaim, [
    'wallet_applied' => true,
    'ledger_written' => true,
    'request_finalized' => true,
    'history_written' => true,
    'notification_written' => true,
    'request_id' => $requestId,
    'ledger_id' => $ledgerId,
    'result_data' => $responseData,
]);

api_response(true, 'SUCCESS', 'Topup request created successfully', $responseData);
