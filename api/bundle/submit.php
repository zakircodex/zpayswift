<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$userPhone = trim((string)($user['phone'] ?? ''));
$body = api_read_json_body();

$offerId = trim((string)($body['offer_id'] ?? ''));
$bundleNumber = trim((string)($body['bundle_number'] ?? $body['number'] ?? ''));
$pin = trim((string)($body['pin'] ?? ''));
$previewToken = trim((string)($body['preview_token'] ?? ''));
$note = trim((string)($body['note'] ?? ''));
$idempotencyKey = trim((string)($body['idempotency_key'] ?? $body['client_request_id'] ?? ''));
$idempotencyKey = preg_replace('/[^A-Za-z0-9:_-]/', '', $idempotencyKey) ?? '';
$idempotencyPath = $idempotencyKey !== ''
    ? 'BUNDLE_SUBMIT_IDEMPOTENCY/' . rawurlencode($uid) . '/' . hash('sha256', $idempotencyKey)
    : '';

if ($idempotencyPath !== '') {
    $existing = fb_get($idempotencyPath);
    if (is_array($existing)) {
        $existingRequestId = trim((string)($existing['request_id'] ?? ''));
        if ($existingRequestId !== '') {
            $existingRow = fb_get('BUNDLE_REQUESTS/PENDING/' . $existingRequestId);
            if (!is_array($existingRow)) {
                $existingRow = fb_get('BUNDLE_REQUESTS/DONE/' . $existingRequestId);
            }
            $existingRow = is_array($existingRow) ? bundle_with_financial_aliases($existingRow) : [];
            api_response(true, 'BUNDLE_REQUEST_CREATED', 'Bundle request already submitted', [
                'request_id' => $existingRequestId,
                'duplicate' => true,
                'status' => (string)($existingRow['status'] ?? $existing['status'] ?? 'WAITING_ADMIN'),
                'bundle_number' => (string)($existingRow['bundle_number'] ?? ''),
                'operator' => (string)($existingRow['operator'] ?? ''),
                'bundle_name' => (string)($existingRow['bundle_name'] ?? ''),
                'amount' => (float)($existingRow['service_amount_bdt'] ?? $existingRow['price_amount'] ?? 0),
                'service_amount' => (float)($existingRow['service_amount'] ?? 0),
                'service_amount_bdt' => (float)($existingRow['service_amount_bdt'] ?? 0),
                'service_currency' => (string)($existingRow['service_currency'] ?? 'BDT'),
                'bundle_commission' => (float)($existingRow['bundle_commission'] ?? 0),
                'commission_currency' => (string)($existingRow['commission_currency'] ?? 'BDT'),
                'wallet_debit_amount' => (float)($existingRow['wallet_debit_amount'] ?? 0),
                'wallet_debit_currency' => (string)($existingRow['wallet_debit_currency'] ?? ''),
                'rate_used' => (float)($existingRow['rate_used'] ?? 0),
                'rate_snapshot' => $existingRow['rate_snapshot'] ?? null,
            ]);
        }
    }
}

$tokenHash = '';
$claimedPreview = [];
$hasPreviewToken = $previewToken !== '';
if ($hasPreviewToken) {
    $tokenHash = bundle_preview_token_hash($previewToken);
    $claim = bundle_claim_preview_token($tokenHash, $uid);
    if (empty($claim['ok'])) {
        $httpStatus = (int)($claim['http_status'] ?? 422);
        api_response(
            false,
            (string)($claim['code'] ?? 'BUNDLE_PREVIEW_INVALID'),
            (string)($claim['message'] ?? 'Bundle preview is invalid. Please validate again.'),
            (array)($claim['data'] ?? []),
            $httpStatus
        );
    }

    $claimedPreview = (array)($claim['preview'] ?? []);
    $duplicateRequestId = trim((string)($claim['request_id'] ?? $claimedPreview['request_id'] ?? ''));
    if (!empty($claim['duplicate']) && $duplicateRequestId !== '') {
        $existingRow = fb_get('BUNDLE_REQUESTS/PENDING/' . $duplicateRequestId);
        if (!is_array($existingRow)) {
            $existingRow = fb_get('BUNDLE_REQUESTS/DONE/' . $duplicateRequestId);
        }
        $existingRow = is_array($existingRow) ? bundle_with_financial_aliases($existingRow) : bundle_with_financial_aliases($claimedPreview);
        api_response(true, 'BUNDLE_REQUEST_CREATED', 'Bundle request already submitted', [
            'request_id' => $duplicateRequestId,
            'duplicate' => true,
            'status' => (string)($existingRow['status'] ?? 'WAITING_ADMIN'),
            'display_status' => 'Pending',
            'bundle_number' => (string)($existingRow['bundle_number'] ?? ''),
            'operator' => (string)($existingRow['operator'] ?? ''),
            'bundle_name' => (string)($existingRow['bundle_name'] ?? ''),
            'amount' => (float)($existingRow['service_amount_bdt'] ?? $existingRow['price_amount'] ?? 0),
            'service_amount' => (float)($existingRow['service_amount'] ?? 0),
            'service_amount_bdt' => (float)($existingRow['service_amount_bdt'] ?? 0),
            'service_currency' => (string)($existingRow['service_currency'] ?? 'BDT'),
            'bundle_commission' => (float)($existingRow['bundle_commission'] ?? 0),
            'commission_currency' => (string)($existingRow['commission_currency'] ?? 'BDT'),
            'wallet_debit_amount' => (float)($existingRow['wallet_debit_amount'] ?? 0),
            'wallet_debit_currency' => (string)($existingRow['wallet_debit_currency'] ?? 'BDT'),
            'rate_used' => (float)($existingRow['rate_used'] ?? 0),
            'rate_snapshot' => $existingRow['rate_snapshot'] ?? null,
        ]);
    }

    $previewOfferId = trim((string)($claimedPreview['offer_id'] ?? ''));
    $previewNumber = trim((string)($claimedPreview['bundle_number'] ?? $claimedPreview['number'] ?? ''));
    if ($previewOfferId !== '') {
        if ($offerId !== '' && $offerId !== $previewOfferId) {
            bundle_mark_preview_failed($tokenHash, 'BUNDLE_PREVIEW_MISMATCH', 'Offer changed before submit');
            api_response(false, 'BUNDLE_PREVIEW_MISMATCH', 'Bundle preview does not match the selected offer.', [], 422);
        }
        $offerId = $previewOfferId;
    }
    if ($previewNumber !== '') {
        $bodyNumber = function_exists('normalize_bd_topup_number')
            ? normalize_bd_topup_number($bundleNumber)
            : preg_replace('/\D+/', '', $bundleNumber);
        if ($bodyNumber !== '' && $bodyNumber !== $previewNumber) {
            bundle_mark_preview_failed($tokenHash, 'BUNDLE_PREVIEW_MISMATCH', 'Number changed before submit');
            api_response(false, 'BUNDLE_PREVIEW_MISMATCH', 'Bundle preview does not match the mobile number.', [], 422);
        }
        $bundleNumber = $previewNumber;
    }
} else {
    if (!is_valid_user_pin($pin)) {
        api_response(false, 'PIN_INVALID', 'PIN must be exactly 4 digits', [
            'field' => 'pin',
        ], 422);
    }
}

$preview = bundle_preview_for_user($uid, $offerId, $bundleNumber, $user);
if (!($preview['ok'] ?? false)) {
    $code = (string)($preview['code'] ?? 'BUNDLE_PREVIEW_FAILED');
    $httpStatus = $code === 'ACCOUNT_INACTIVE' ? 403 : ($code === 'USER_NOT_FOUND' ? 404 : 422);
    api_response(false, $code, (string)($preview['message'] ?? 'Bundle preview failed'), (array)($preview['data'] ?? []), $httpStatus);
}

$pinHash = (string)($user['pin_hash'] ?? '');
if (!$hasPreviewToken && ($pinHash === '' || !password_verify($pin, $pinHash))) {
    api_response(false, 'PIN_INVALID', 'Transaction PIN is incorrect', [], 403);
}

$data = bundle_with_financial_aliases((array)($preview['data'] ?? []));
$failPreview = static function (string $code, string $message) use ($hasPreviewToken, $tokenHash): void {
    if ($hasPreviewToken) {
        bundle_mark_preview_failed($tokenHash, $code, $message);
    }
};
$offer = bundle_visible_offer_for_user($uid, $offerId, $user);
if (!$offer) {
    $failPreview('BUNDLE_OFFER_INACTIVE', 'Bundle offer unavailable at submit');
    api_response(false, 'BUNDLE_OFFER_INACTIVE', 'Bundle offer is unavailable', ['offer_id' => $offerId], 422);
}
$requestId = function_exists('make_bundle_request_id') ? make_bundle_request_id() : bundle_make_request_id();
$operator = strtoupper(trim((string)($data['operator'] ?? $offer['operator'] ?? '')));
$bundleName = trim((string)($data['bundle_name'] ?? $offer['bundle_name'] ?? $offer['name'] ?? ''));
$normalizedBundleNumber = (string)($data['bundle_number'] ?? normalize_bd_topup_number($bundleNumber));
$priceAmount = bundle_round_money((float)($data['service_amount_bdt'] ?? $data['price_amount'] ?? 0));
$payableAmount = bundle_round_money((float)($data['payable_amount'] ?? $data['you_pay'] ?? 0));
$walletDebit = bundle_round_money((float)($data['wallet_debit_amount'] ?? $data['wallet_hold_amount'] ?? $payableAmount));
$walletCurrency = (string)($data['wallet_debit_currency'] ?? $data['wallet_currency'] ?? 'BDT');

$hold = wallet_hold_amount($uid, $walletDebit, $requestId, 'BUNDLE_HOLD');
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'WALLET_HOLD_FAILED');
    $status = $code === 'INSUFFICIENT_BALANCE' ? 422 : 500;
    $failPreview($code, (string)($hold['message'] ?? 'Wallet hold failed'));
    api_response(false, $code, (string)($hold['message'] ?? 'Wallet hold failed'), [
        'available_balance' => (float)($hold['available_balance'] ?? 0),
        'required_amount' => (float)($hold['required_amount'] ?? $walletDebit),
        'wallet_currency' => $walletCurrency,
    ], $status);
}

$extra = [
    'offer_id' => $offerId,
    'offer_source' => 'ANDROID',
    'source' => 'ANDROID',
    'request_source' => 'ANDROID',
    'created_from_android' => true,
    'internal_note' => 'Bundle request created from Android',
    'preview_token_hash' => $hasPreviewToken ? (string)($claimedPreview['_token_hash'] ?? $tokenHash) : '',
    'preview_created_at' => (int)($claimedPreview['created_at'] ?? 0),
    'preview_expires_at' => (int)($claimedPreview['expires_at'] ?? 0),
    'verified_by' => (string)($claimedPreview['verified_by'] ?? $body['verified_by'] ?? ($hasPreviewToken ? 'BUNDLE_PREVIEW' : 'PIN')),
    'amount' => $priceAmount,
    'service_amount' => $priceAmount,
    'service_amount_bdt' => $priceAmount,
    'service_currency' => 'BDT',
    'price_amount' => $priceAmount,
    'offer_price' => $priceAmount,
    'bundle_commission' => (float)($offer['user_commission'] ?? $data['bundle_commission'] ?? $data['user_commission'] ?? 0),
    'commission_currency' => 'BDT',
    'admin_commission' => (float)($offer['admin_commission'] ?? $data['admin_commission'] ?? 0),
    'user_commission' => (float)($offer['user_commission'] ?? $data['user_commission'] ?? 0),
    'customer_commission' => (float)($offer['user_commission'] ?? $data['user_commission'] ?? 0),
    'user_discount' => (float)($offer['user_commission'] ?? $data['user_commission'] ?? 0),
    'subadmin_profit' => (float)($offer['subadmin_profit'] ?? 0),
    'subadmin_uid' => (string)($offer['subadmin_uid'] ?? ''),
    'customized_by_subadmin' => (bool)($offer['customized_by_subadmin'] ?? false),
    'net_cost_after_commission' => $payableAmount,
    'you_pay' => $payableAmount,
    'payable_amount' => $payableAmount,
    'payable_amount_bdt' => $payableAmount,
    'wallet_hold_amount' => $walletDebit,
    'held_amount' => $walletDebit,
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $walletCurrency,
    'wallet_currency' => $walletCurrency,
    'rate_used' => (float)($data['rate_used'] ?? 0),
    'rate_snapshot' => $data['rate_snapshot'] ?? null,
    'rate_applicable' => (bool)($data['rate_applicable'] ?? false),
    'wallet_debit_bdt' => (float)($data['wallet_debit_bdt'] ?? ($walletCurrency === 'BDT' ? $walletDebit : $payableAmount)),
    'wallet_debit_myr' => (float)($data['wallet_debit_myr'] ?? ($walletCurrency === 'MYR' ? $walletDebit : 0)),
    'hold_settled_at' => 0,
    'hold_settlement_status' => 'PENDING',
    'idempotency_key_hash' => $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : '',
];

$saved = create_bundle_pending_request(
    $requestId,
    $uid,
    $userPhone,
    $normalizedBundleNumber,
    $operator,
    $bundleName,
    $priceAmount,
    $note,
    false,
    '',
    $extra
);

if (!$saved) {
    $failPreview('BUNDLE_REQUEST_SAVE_FAILED', 'Failed to create bundle request');
    wallet_refund_hold($uid, $walletDebit, $requestId, 'BUNDLE_REFUND');
    api_response(false, 'BUNDLE_REQUEST_SAVE_FAILED', 'Failed to create bundle request', [], 500);
}

if (function_exists('create_request_status')
    && !create_request_status($requestId, 'BUNDLE', $uid, 'WAITING_ADMIN', 'Bundle request submitted and waiting for admin')) {
    $failPreview('BUNDLE_STATUS_SAVE_FAILED', 'Failed to create request status');
    fb_delete('BUNDLE_REQUESTS/PENDING/' . $requestId);
    wallet_refund_hold($uid, $walletDebit, $requestId, 'BUNDLE_REFUND');
    api_response(false, 'BUNDLE_STATUS_SAVE_FAILED', 'Failed to create request status', [], 500);
}

if ($idempotencyPath !== '') {
    fb_put($idempotencyPath, [
        'request_id' => $requestId,
        'status' => 'WAITING_ADMIN',
        'created_at' => bundle_now(),
    ]);
}

if ($hasPreviewToken) {
    bundle_mark_preview_used($tokenHash, $requestId);
}

if (function_exists('system_log')) {
    system_log('BUNDLE_SUBMIT', $requestId, 'Bundle request created successfully', [
        'uid' => $uid,
        'offer_id' => $offerId,
        'operator' => $operator,
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $walletCurrency,
        'rate_used' => (float)($data['rate_used'] ?? 0),
    ]);
}

$savedRow = fb_get('BUNDLE_REQUESTS/PENDING/' . $requestId);
$savedRow = is_array($savedRow) ? $savedRow : [];

api_response(true, 'BUNDLE_REQUEST_CREATED', 'Bundle request submitted', [
    'request_id' => $requestId,
    'status' => 'WAITING_ADMIN',
    'display_status' => 'Pending',
    'offer_id' => $offerId,
    'operator' => $operator,
    'operator_name' => (string)($data['operator_name'] ?? $operator),
    'bundle_number' => $normalizedBundleNumber,
    'bundle_name' => $bundleName,
    'amount' => $priceAmount,
    'service_amount' => $priceAmount,
    'service_amount_bdt' => $priceAmount,
    'service_currency' => 'BDT',
    'bundle_commission' => (float)($extra['bundle_commission'] ?? 0),
    'commission_currency' => 'BDT',
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $walletCurrency,
    'rate_used' => (float)($data['rate_used'] ?? 0),
    'rate_snapshot' => $data['rate_snapshot'] ?? null,
    'rate_applicable' => (bool)($data['rate_applicable'] ?? false),
    'wallet_debit_bdt' => (float)($extra['wallet_debit_bdt'] ?? 0),
    'wallet_debit_myr' => (float)($extra['wallet_debit_myr'] ?? 0),
    'balance_after' => (float)($hold['after_available'] ?? $data['balance_after'] ?? 0),
    'telegram_sent' => (bool)($savedRow['telegram_sent'] ?? false),
    'telegram_message' => (string)($savedRow['telegram_error'] ?? ''),
]);
