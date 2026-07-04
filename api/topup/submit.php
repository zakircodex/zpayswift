<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/topup_config.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = $auth['user'];
$uid = (string)$user['uid'];
$userPhone = (string)$user['phone'];

$body = api_read_json_body();

$previewToken = trim((string)($body['preview_token'] ?? ''));
if ($previewToken === '') {
    api_response(false, 'TOPUP_PREVIEW_REQUIRED', 'Top-up preview token is required.', [], 422);
}

$tokenHash = topup_preview_token_hash($previewToken);
$claim = topup_claim_preview_token($tokenHash, $uid);
if (empty($claim['ok'])) {
    topup_api_error($claim);
}

$preview = (array)($claim['preview'] ?? []);
$failPreview = static function (string $code, string $message) use ($tokenHash): void {
    topup_mark_preview_failed($tokenHash, $code, $message);
};

$operator = normalize_operator($preview['operator'] ?? '');
$amount = topup_money($preview['amount'] ?? 0);
$countryCode = topup_country_code($preview['country_code'] ?? 'BD');
$topupNumber = topup_normalize_number_for_country($countryCode, $preview['topup_number'] ?? $preview['number'] ?? '');

if (!topup_is_valid_number_for_country($countryCode, $topupNumber) || $operator === '' || $amount <= 0) {
    $failPreview('TOPUP_PREVIEW_INVALID', 'Top-up preview data is invalid.');
    api_response(false, 'TOPUP_PREVIEW_INVALID', 'Top-up preview data is invalid. Please preview again.', [], 422);
}

$bodyNumber = topup_normalize_number_for_country($countryCode, $body['topup_number'] ?? $body['number'] ?? '');
$bodyOperator = normalize_operator($body['operator'] ?? '');
$bodyAmount = isset($body['amount']) && is_numeric($body['amount']) ? topup_money($body['amount']) : 0;
if (($bodyNumber !== '' && $bodyNumber !== $topupNumber)
    || ($bodyOperator !== '' && $bodyOperator !== $operator)
    || ($bodyAmount > 0 && abs($bodyAmount - $amount) > 0.001)
) {
    $failPreview('TOPUP_PREVIEW_MISMATCH', 'Top-up preview does not match this request.');
    api_response(false, 'TOPUP_PREVIEW_MISMATCH', 'Top-up preview does not match this request. Please preview again.', [], 422);
}

$amountValidation = topup_validate_request($countryCode, $operator, $amount, true, true);
if (empty($amountValidation['ok'])) {
    $failPreview((string)($amountValidation['code'] ?? 'TOPUP_AMOUNT_INVALID'), (string)($amountValidation['message'] ?? 'Invalid top-up amount.'));
    topup_api_error($amountValidation);
}

/*
|--------------------------------------------------------------------------
| Operator runtime check
|--------------------------------------------------------------------------
*/
$runtime = (array)($amountValidation['operator'] ?? []);

/*
|--------------------------------------------------------------------------
| Create request + hold balance
|--------------------------------------------------------------------------
*/
$requestId = make_topup_request_id();
$financials = topup_commission_breakdown($uid, $amount, $user);
$walletDebit = (float)$financials['wallet_debit_amount'];

$hold = wallet_hold_amount($uid, $walletDebit, $requestId, 'TOPUP_HOLD');
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'SERVER_ERROR');

    if ($code === 'INSUFFICIENT_BALANCE') {
        $failPreview('INSUFFICIENT_BALANCE', 'Not enough balance');
        api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough balance', [
            'available_balance' => (float)($hold['available_balance'] ?? 0),
            'required_amount' => (float)($hold['required_amount'] ?? $walletDebit),
        ], 422);
    }

    $failPreview($code, (string)($hold['message'] ?? 'Wallet hold failed'));
    api_response(false, $code, (string)($hold['message'] ?? 'Wallet hold failed'), [], 500);
}

/*
|--------------------------------------------------------------------------
| Save topup request
|--------------------------------------------------------------------------
*/
$pendingSaved = create_topup_pending_request(
    $requestId,
    $uid,
    $userPhone,
    $topupNumber,
    $operator,
    $amount,
    $financials,
    [
        'preview_token_hash' => (string)($preview['_token_hash'] ?? ''),
        'preview_created_at' => (int)($preview['created_at'] ?? 0),
        'preview_expires_at' => (int)($preview['expires_at'] ?? 0),
        'verified_by' => topup_clean_text($preview['verified_by'] ?? $body['verified_by'] ?? '', 30),
    ]
);

if (!$pendingSaved) {
    wallet_refund_hold($uid, $walletDebit, $requestId, 'TOPUP_REFUND');
    $failPreview('SERVER_ERROR', 'Failed to create topup request');
    api_response(false, 'SERVER_ERROR', 'Failed to create topup request', [], 500);
}

/*
|--------------------------------------------------------------------------
| Save request status
|--------------------------------------------------------------------------
*/
$statusSaved = create_request_status(
    $requestId,
    'TOPUP',
    $uid,
    'PENDING',
    'Topup request created successfully'
);

if (!$statusSaved) {
    fb_delete('TOPUP_REQUESTS/PENDING/' . $requestId);
    wallet_refund_hold($uid, $walletDebit, $requestId, 'TOPUP_REFUND');
    $failPreview('SERVER_ERROR', 'Failed to create request status');
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

topup_mark_preview_used($tokenHash, $requestId);

system_log('TOPUP_SUBMIT', $requestId, 'Topup request created successfully', [
    'uid' => $uid,
    'operator' => $operator,
    'amount' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'topup_number_masked' => topup_mask_number($topupNumber),
    'operator_active' => (bool)($runtime['active'] ?? false),
]);

$topupRow = topup_find_request($requestId);
if (is_array($topupRow)) {
    topup_notify_telegram_request($topupRow);
}

api_response(true, 'TOPUP_REQUEST_CREATED', 'Topup request submitted', [
    'request_id' => $requestId,
    'status' => 'PENDING',
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'total_debit' => $walletDebit,
]);
