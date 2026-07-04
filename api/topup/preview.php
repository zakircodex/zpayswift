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
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = (string)($user['uid'] ?? '');
$body = api_read_json_body();

$countryCode = topup_country_code($body['country_code'] ?? $body['country'] ?? 'BD');
$topupNumber = topup_normalize_number_for_country($countryCode, $body['topup_number'] ?? $body['number'] ?? '');
$operator = normalize_operator($body['operator'] ?? $body['operator_code'] ?? '');
$amount = topup_money($body['amount'] ?? 0);

if (!topup_is_valid_number_for_country($countryCode, $topupNumber)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid top-up number.', [
        'field' => 'topup_number',
    ], 422);
}

$amountValidation = topup_validate_request($countryCode, $operator, $amount, true, true);
if (empty($amountValidation['ok'])) {
    topup_api_error($amountValidation);
}

$countryConfig = (array)($amountValidation['country'] ?? []);
$operatorConfig = (array)($amountValidation['operator'] ?? []);
$financials = topup_commission_breakdown($uid, $amount, $user);
$walletDebit = topup_money($financials['wallet_debit_amount'] ?? $amount);
$walletCurrency = (string)($financials['wallet_debit_currency'] ?? $financials['wallet_currency'] ?? 'BDT');
$rate = wallet_myr_to_bdt_rate();
$wallet = get_user_wallet($uid);

if (!is_array($wallet)) {
    api_response(false, 'WALLET_NOT_FOUND', 'Wallet not found or unavailable.', [], 422);
}

$available = topup_money($wallet['available_balance'] ?? 0);
$balanceAfter = topup_money($available - $walletDebit);

if ($available < $walletDebit) {
    api_response(false, 'INSUFFICIENT_BALANCE', 'Insufficient balance. Please add money first.', [
        'available_balance' => $available,
        'required_amount' => $walletDebit,
        'currency' => $walletCurrency,
    ], 422);
}

$now = now_ts();
$previewPayload = [
    'uid' => $uid,
    'country_code' => $countryCode,
    'country' => (string)($countryConfig['name'] ?? $countryCode),
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'operator_name' => (string)($operatorConfig['name'] ?? $operator),
    'amount' => $amount,
    'currency' => (string)($countryConfig['currency'] ?? 'BDT'),
    'financials' => $financials,
    'wallet_currency' => $walletCurrency,
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_bdt' => topup_money($financials['wallet_debit_bdt'] ?? $amount),
    'rate' => $rate,
    'balance_before' => $available,
    'balance_after' => $balanceAfter,
    'expires_at' => $now + 300,
    'verified_by' => topup_clean_text($body['verified_by'] ?? '', 30),
];

$previewToken = topup_create_preview_token($previewPayload);
if ($previewToken === '') {
    api_response(false, 'TOPUP_PREVIEW_FAILED', 'Top-up preview could not be created.', [], 500);
}

$totalPayText = $walletCurrency === 'MYR'
    ? topup_amount_text($walletDebit, 'MYR')
    : topup_amount_text($walletDebit, $walletCurrency);
$feeText = $walletCurrency === 'MYR' ? 'RM 0.00' : topup_amount_text(0, $walletCurrency);
$balanceAfterText = $walletCurrency === 'MYR'
    ? topup_amount_text($balanceAfter, 'MYR')
    : topup_amount_text($balanceAfter, $walletCurrency);

api_response(true, 'TOPUP_PREVIEW_READY', 'Top-up preview ready.', [
    'country' => (string)($countryConfig['name'] ?? $countryCode),
    'country_code' => $countryCode,
    'number' => $topupNumber,
    'topup_number' => $topupNumber,
    'operator' => (string)($operatorConfig['name'] ?? $operator),
    'operator_code' => $operator,
    'amount' => $amount,
    'currency' => (string)($countryConfig['currency'] ?? 'BDT'),
    'rate' => $rate,
    'rate_text' => 'RM 1 = ' . number_format($rate, 2, '.', '') . ' BDT',
    'total_myr' => $walletCurrency === 'MYR' ? $walletDebit : 0,
    'total_pay' => $walletDebit,
    'total_pay_text' => $totalPayText,
    'fee_myr' => 0,
    'fee' => 0,
    'fee_text' => $feeText,
    'balance_before' => $available,
    'balance_after' => $balanceAfter,
    'balance_after_text' => $balanceAfterText,
    'wallet_currency' => $walletCurrency,
    'preview_token' => $previewToken,
    'expires_in' => 300,
]);
