<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/subadmin_api.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

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

$bundleEnabled = (bool)($roleSettings['bundle_enabled'] ?? false);
if (!$bundleEnabled) {
    api_response(false, 'BUNDLE_DISABLED', 'Bundle is disabled for this account', [], 403);
}

$body = api_read_json_body();

$offerId = trim((string)($body['offer_id'] ?? ''));
$bundleNumber = trim((string)($body['bundle_number'] ?? $body['number'] ?? ''));
$note = trim((string)($body['note'] ?? 'API bundle request'));

if ($offerId === '') {
    api_response(false, 'VALIDATION_ERROR', 'offer_id is required', ['field' => 'offer_id'], 422);
}

if ($bundleNumber === '') {
    api_response(false, 'VALIDATION_ERROR', 'bundle_number is required', ['field' => 'bundle_number'], 422);
}

if (!function_exists('bundle_load_offer')) {
    api_response(false, 'SERVER_ERROR', 'bundle_load_offer helper missing', [], 500);
}

$baseOffer = bundle_load_offer($offerId);

if (!is_array($baseOffer) || empty($baseOffer)) {
    api_response(false, 'OFFER_NOT_FOUND', 'Bundle offer not found', [
        'offer_id' => $offerId,
    ], 404);
}

if (function_exists('bundle_expire_old_offers')) {
    bundle_expire_old_offers();
    $baseOffer = bundle_load_offer($offerId);
}

if (!function_exists('bundle_is_active_offer') || !bundle_is_active_offer($baseOffer)) {
    api_response(false, 'OFFER_INACTIVE', 'Bundle offer is inactive or expired', [
        'offer_id' => $offerId,
    ], 422);
}

$offer = $baseOffer;

if (function_exists('bundle_build_visible_offer_for_user')) {
    $offer = bundle_build_visible_offer_for_user($baseOffer, $user);
}

$operator = strtoupper(trim((string)($offer['operator'] ?? '')));
$bundleName = trim((string)($offer['bundle_name'] ?? $offer['name'] ?? ''));
$amount = round((float)($offer['amount'] ?? 0), 2);

$adminCommission = round((float)($offer['admin_commission'] ?? 0), 2);
$userCommission = round((float)($offer['user_commission'] ?? 0), 2);
$subadminProfit = round((float)($offer['subadmin_profit'] ?? 0), 2);

$subadminUid = trim((string)($offer['subadmin_uid'] ?? ''));
$customizedBySubadmin = (bool)($offer['customized_by_subadmin'] ?? false);

if ($operator === '') {
    api_response(false, 'INVALID_OFFER', 'Offer operator is missing', [
        'offer_id' => $offerId,
    ], 422);
}

if ($bundleName === '') {
    api_response(false, 'INVALID_OFFER', 'Offer bundle name is missing', [
        'offer_id' => $offerId,
    ], 422);
}

if ($amount <= 0) {
    api_response(false, 'INVALID_OFFER', 'Offer amount is invalid', [
        'offer_id' => $offerId,
    ], 422);
}

$currentAvailable = (float)($wallet['available_balance'] ?? 0);
$currentHold = (float)($wallet['hold_balance'] ?? 0);
$bundleFinancials = bundle_wallet_breakdown($uid, $amount, $user, $wallet);
$walletHoldAmount = (float)$bundleFinancials['wallet_hold_amount'];

if ($currentAvailable < $walletHoldAmount) {
    api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough available balance', [
        'available_balance' => $currentAvailable,
        'required_amount' => $walletHoldAmount,
    ], 422);
}

$requestId = function_exists('bundle_make_request_id') ? bundle_make_request_id() : make_uid();
$now = function_exists('bundle_now') ? bundle_now() : now_ts();
$userPhone = trim((string)($user['phone'] ?? ''));
$operationSeed = trim((string)($body['idempotency_key'] ?? $body['client_request_id'] ?? $body['request_reference'] ?? $body['reference_id'] ?? ''));
if ($operationSeed === '') {
    $operationSeed = hash('sha256', implode('|', [
        'API_BUNDLE_CREATE',
        $keyId,
        $uid,
        $offerId,
        $operator,
        $bundleNumber,
        number_format($walletHoldAmount, 2, '.', ''),
        (string)floor($now / 120),
    ]));
}
$operationRef = 'API_BUNDLE_CREATE:' . hash('sha256', implode('|', [$keyId, $uid, $operationSeed]));
$operation = wallet_financial_operation_begin($operationRef, 'API_BUNDLE_CREATE_HOLD', 'REQUEST_CREATE', $uid, $walletHoldAmount, (string)$bundleFinancials['wallet_currency'], [
    'request_id' => $requestId,
    'key_id' => $keyId,
    'offer_id' => $offerId,
    'bundle_number_hash' => hash('sha256', $bundleNumber),
]);
if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
    $resultData = is_array($operation['operation']['result_data'] ?? null) ? $operation['operation']['result_data'] : [];
    api_response(true, 'SUCCESS', 'Bundle request created successfully', $resultData);
}
if (empty($operation['ok']) || empty($operation['claim'])) {
    api_response(false, (string)($operation['code'] ?? 'FINANCIAL_OPERATION_UNAVAILABLE'), (string)($operation['message'] ?? 'Wallet operation is unavailable'), [], 409);
}
$financialClaim = $operation['claim'];
$requestId = trim((string)($financialClaim['meta']['request_id'] ?? $requestId));

/*
|--------------------------------------------------------------------------
| Hold wallet balance
|--------------------------------------------------------------------------
*/
$hold = wallet_hold_amount($uid, $walletHoldAmount, $operationRef, 'API_BUNDLE_HOLD', [
    'financial_operation' => $financialClaim,
    'ledger_extra' => [
        'ledger_id' => wallet_financial_operation_ledger_id($operationRef, 'API_BUNDLE_CREATE_HOLD'),
        'request_id' => $requestId,
        'ref_id' => $requestId,
        'key_id' => $keyId,
        'offer_id' => $offerId,
        'payable_amount_bdt' => $amount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],
        'note' => 'Balance moved to hold for API bundle request',
    ],
]);

if (empty($hold['ok'])) {
    api_response(false, (string)($hold['code'] ?? 'SERVER_ERROR'), (string)($hold['message'] ?? 'Failed to hold wallet balance'), [], 500);
}

$newAvailable = (float)($hold['available_balance'] ?? $hold['after_available'] ?? ($currentAvailable - $walletHoldAmount));
$newHold = (float)($hold['hold_balance'] ?? $hold['after_hold'] ?? ($currentHold + $walletHoldAmount));

/*
|--------------------------------------------------------------------------
| Create pending bundle request
|--------------------------------------------------------------------------
*/
$extra = [
    'offer_id' => $offerId,
    'offer_source' => 'PUBLIC_API',
    'source' => 'SUBADMIN_API',
    'source_key_id' => $keyId,
    'request_source' => 'PUBLIC_API',
    'created_from_api' => true,

    'admin_commission' => $adminCommission,
    'user_commission' => $userCommission,
    'subadmin_profit' => $subadminProfit,
    'subadmin_uid' => $subadminUid,
    'customized_by_subadmin' => $customizedBySubadmin,

    'payable_amount_bdt' => $amount,
    'wallet_hold_amount' => $walletHoldAmount,
    'held_amount' => $walletHoldAmount,
    'wallet_debit_amount' => $walletHoldAmount,
    'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
    'wallet_currency' => $bundleFinancials['wallet_currency'],
    'rate_used' => $bundleFinancials['rate_used'],
    'hold_settled_at' => 0,
    'hold_settlement_status' => 'PENDING',
    'source_key_id' => $keyId,
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
        $amount,
        $note,
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
        'amount' => $amount,
        'note' => $note,
        'wallet_hold_amount' => $walletHoldAmount,
        'held_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'wallet_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],
        'status' => 'WAITING_ADMIN',
        'telegram_sent' => false,
        'telegram_queue_id' => '',
        'offer_id' => $offerId,
        'offer_source' => 'PUBLIC_API',
        'source' => 'SUBADMIN_API',
        'source_key_id' => $keyId,
        'request_source' => 'PUBLIC_API',
        'created_from_api' => true,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'subadmin_uid' => $subadminUid,
        'customized_by_subadmin' => $customizedBySubadmin,
        'commission_status' => 'PENDING',
        'commission_credited_at' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

if (!$requestSaved) {
    wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_CREATE_FAILED', 'Bundle request could not be saved after wallet hold', [
        'wallet_applied' => true,
        'ledger_written' => true,
        'request_id' => $requestId,
        'request_extra' => $extra,
        'request_finalized' => false,
    ]);

    api_response(false, 'SERVER_ERROR', 'Failed to create bundle request', ['request_id' => $requestId], 500);
}

/*
|--------------------------------------------------------------------------
| Request status
|--------------------------------------------------------------------------
*/
fb_put('REQUEST_STATUS/' . $requestId, [
    'request_id' => $requestId,
    'type' => 'BUNDLE',
    'uid' => $uid,
    'status' => 'WAITING_ADMIN',
    'message' => 'Bundle request created via subadmin API',
    'updated_at' => $now,
]);

/*
|--------------------------------------------------------------------------
| Wallet ledger
|--------------------------------------------------------------------------
*/
$ledgerId = (string)($hold['ledger_id'] ?? '');

/*
|--------------------------------------------------------------------------
| API request log
|--------------------------------------------------------------------------
*/
if (function_exists('subapi_log_request')) {
    subapi_log_request($uid, [
    'request_id' => $requestId,
    'key_id' => $keyId,
    'action' => 'BUNDLE_CREATE',
    'request_type' => 'BUNDLE',
    'status' => 'PENDING',
    'operator' => $operator,
    'bundle_number' => $bundleNumber,
    'topup_number' => $bundleNumber,
    'number' => $bundleNumber,
    'offer_id' => $offerId,
    'bundle_name' => $bundleName,
    'amount' => $amount,
    'payable_amount_bdt' => $amount,
    'wallet_hold_amount' => $walletHoldAmount,
    'wallet_debit_amount' => $walletHoldAmount,
    'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
    'rate_used' => $bundleFinancials['rate_used'],
    'message' => 'Bundle request created via subadmin API',
    'note' => $note,
    'created_at' => $now,
    'updated_at' => $now,
]);
}

if (function_exists('system_log')) {
    system_log('SUBAPI_BUNDLE_CREATE', $requestId, 'Bundle request created via subadmin API', [
        'uid' => $uid,
        'key_id' => $keyId,
        'offer_id' => $offerId,
        'operator' => $operator,
        'bundle_number' => $bundleNumber,
        'bundle_name' => $bundleName,
        'amount' => $amount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],
        'note' => $note,
    ]);
}

$responseData = [
    'request_id' => $requestId,
    'uid' => $uid,
    'status' => 'WAITING_ADMIN',
    'offer_id' => $offerId,
    'operator' => $operator,
    'bundle_number' => $bundleNumber,
    'bundle_name' => $bundleName,
    'amount' => $amount,
    'wallet_hold_amount' => $walletHoldAmount,
    'wallet_debit_amount' => $walletHoldAmount,
    'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
    'rate_used' => $bundleFinancials['rate_used'],
    'admin_commission' => $adminCommission,
    'user_commission' => $userCommission,
    'subadmin_profit' => $subadminProfit,
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

api_response(true, 'SUCCESS', 'Bundle request created successfully', $responseData);
