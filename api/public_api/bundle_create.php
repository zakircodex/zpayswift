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

if ($currentAvailable < $amount) {
    api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough available balance', [
        'available_balance' => $currentAvailable,
        'required_amount' => $amount,
    ], 422);
}

$requestId = function_exists('bundle_make_request_id') ? bundle_make_request_id() : make_uid();
$now = function_exists('bundle_now') ? bundle_now() : now_ts();
$userPhone = trim((string)($user['phone'] ?? ''));

$newAvailable = $currentAvailable - $amount;
$newHold = $currentHold + $amount;

/*
|--------------------------------------------------------------------------
| Hold wallet balance
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

    'wallet_hold_amount' => $amount,
    'held_amount' => $amount,
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
        'wallet_hold_amount' => $amount,
        'held_amount' => $amount,
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
    fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $currentAvailable,
        'hold_balance' => $currentHold,
        'updated_at' => now_ts(),
    ]);

    api_response(false, 'SERVER_ERROR', 'Failed to create bundle request', [], 500);
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
$ledgerId = make_uid();
$ledgerMonth = date('Y-m', (int)$now);

fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, [
    'ledger_id' => $ledgerId,
    'uid' => $uid,
    'type' => 'API_BUNDLE_HOLD',
    'direction' => 'HOLD',
    'amount' => $amount,
    'before_available' => $currentAvailable,
    'after_available' => $newAvailable,
    'before_hold' => $currentHold,
    'after_hold' => $newHold,
    'ref_id' => $requestId,
    'key_id' => $keyId,
    'offer_id' => $offerId,
    'note' => 'Balance moved to hold for API bundle request',
    'created_at' => $now,
]);

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
        'note' => $note,
    ]);
}

api_response(true, 'SUCCESS', 'Bundle request created successfully', [
    'request_id' => $requestId,
    'uid' => $uid,
    'status' => 'WAITING_ADMIN',
    'offer_id' => $offerId,
    'operator' => $operator,
    'bundle_number' => $bundleNumber,
    'bundle_name' => $bundleName,
    'amount' => $amount,
    'admin_commission' => $adminCommission,
    'user_commission' => $userCommission,
    'subadmin_profit' => $subadminProfit,
    'created_at' => $now,
    'wallet' => [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
    ],
]);