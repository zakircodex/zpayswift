<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/topup_config.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(false);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = (string)($user['uid'] ?? '');
$body = api_read_json_body();

$countryCode = topup_country_code($body['country_code'] ?? $body['country'] ?? 'BD');
$topupNumber = topup_normalize_number_for_country($countryCode, $body['topup_number'] ?? $body['number'] ?? '');
$operator = normalize_operator($body['operator'] ?? $body['operator_code'] ?? '');
$amount = topup_money($body['amount'] ?? 0);
$checkOnly = topup_bool($body['check_only'] ?? $body['validate_only'] ?? false, false);

if (!topup_is_valid_number_for_country($countryCode, $topupNumber)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid top-up number.', [
        'field' => 'topup_number',
    ], 422);
}

$accountAccess = topup_user_topup_access_validation($uid, $user, $countryCode);
if (empty($accountAccess['ok'])) {
    api_response(
        false,
        (string)($accountAccess['code'] ?? 'TOPUP_ACCOUNT_DISABLED'),
        (string)($accountAccess['message'] ?? 'Mobile top-up is disabled for this account.'),
        [],
        422
    );
}

$amountValidation = topup_validate_request($countryCode, $operator, $amount, true, true);
if (empty($amountValidation['ok'])) {
    topup_api_error($amountValidation);
}

$countryConfig = (array)($amountValidation['country'] ?? []);
$operatorConfig = (array)($amountValidation['operator'] ?? []);
$wallet = get_user_wallet($uid);

if (!is_array($wallet)) {
    api_response(false, 'WALLET_NOT_FOUND', 'Wallet not found or unavailable.', [], 422);
}

$financials = topup_calculate_payment_context($uid, $amount, $user, $wallet, [], $countryCode);
if (empty($financials['ok'])) {
    $code = (string)($financials['code'] ?? 'TOPUP_PREVIEW_FAILED');
    $message = (string)($financials['message'] ?? 'Top-up preview could not be loaded.');
    $status = in_array($code, ['ACCOUNT_CURRENCY_INVALID', 'RATE_UNAVAILABLE', 'RATE_INVALID', 'COMMISSION_CONFIG_INVALID'], true)
        ? 422
        : 500;
    api_response(false, $code, $message, [
        'account_country' => (string)($financials['account_country'] ?? ''),
        'wallet_currency' => (string)($financials['wallet_currency'] ?? ''),
    ], $status);
}

$walletDebit = topup_money($financials['wallet_debit_amount'] ?? $amount);
$walletCurrency = (string)($financials['wallet_debit_currency'] ?? $financials['wallet_currency'] ?? 'BDT');
$rate = (float)($financials['rate_snapshot'] ?? $financials['rate_used'] ?? 0);
$topupCurrency = (string)($financials['topup_currency'] ?? ($countryConfig['currency'] ?? 'BDT'));
$topupAmount = topup_money($financials['topup_amount'] ?? $amount);
$topupAmountText = topup_amount_text($topupAmount, $topupCurrency);

$available = topup_money($financials['balance_before'] ?? $wallet['available_balance'] ?? 0);
$balanceAfter = topup_money($financials['balance_after'] ?? ($available - $walletDebit));

if ($available < $walletDebit) {
    api_response(false, 'INSUFFICIENT_BALANCE', 'Insufficient ' . $walletCurrency . ' balance.', [
        'available_balance' => $available,
        'required_amount' => $walletDebit,
        'currency' => $walletCurrency,
    ], 422);
}

$totalPayText = $walletCurrency === 'MYR'
    ? topup_amount_text($walletDebit, 'MYR')
    : topup_amount_text($walletDebit, $walletCurrency);
$feeText = $walletCurrency === 'MYR' ? 'RM 0.00' : topup_amount_text(0, $walletCurrency);
$balanceAfterText = $walletCurrency === 'MYR'
    ? topup_amount_text($balanceAfter, 'MYR')
    : topup_amount_text($balanceAfter, $walletCurrency);
$rateApplicable = (bool)($financials['rate_applicable'] ?? false);
$commissionApplicable = (bool)($financials['commission_applicable'] ?? false);
$commissionAmount = (float)($financials['commission_amount'] ?? 0);
$feeAmount = (float)($financials['fee_amount'] ?? 0);

if ($checkOnly) {
    api_response(true, 'TOPUP_AMOUNT_READY', 'Top-up amount can be processed.', [
        'country' => (string)($countryConfig['name'] ?? $countryCode),
        'country_code' => $countryCode,
        'number' => $topupNumber,
        'topup_number' => $topupNumber,
        'operator' => (string)($operatorConfig['name'] ?? $operator),
        'operator_code' => $operator,
        'amount' => $amount,
        'topup_amount' => $topupAmount,
        'topup_currency' => $topupCurrency,
        'topup_amount_text' => $topupAmountText,
        'amount_bdt' => (float)($financials['amount_bdt'] ?? 0),
        'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
        'amount_myr' => (float)($financials['amount_myr'] ?? 0),
        'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
        'currency' => $topupCurrency,
        'account_country' => (string)$financials['account_country'],
        'wallet_currency' => $walletCurrency,
        'display_currency' => (string)($financials['display_currency'] ?? $walletCurrency),
        'rate_applicable' => $rateApplicable,
        'rate_snapshot' => $rateApplicable ? $rate : null,
        'rate' => $rateApplicable ? $rate : null,
        'rate_text' => $rateApplicable ? ('RM 1 = ' . number_format($rate, 2, '.', '') . ' BDT') : '',
        'commission_applicable' => $commissionApplicable,
        'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
        'commission_value' => (float)($financials['commission_value'] ?? 0),
        'commission_amount' => $commissionAmount,
        'commission_text' => $commissionApplicable ? topup_amount_text($commissionAmount, 'BDT') : '',
        'fee_amount' => $feeAmount,
        'total_myr' => $walletCurrency === 'MYR' ? $walletDebit : 0,
        'wallet_debit' => $walletDebit,
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_bdt' => (float)($financials['wallet_debit_bdt'] ?? $amount),
        'total_pay' => $walletDebit,
        'total_pay_text' => $totalPayText,
        'fee_myr' => 0,
        'fee' => $feeAmount,
        'fee_text' => $feeText,
        'balance_before' => $available,
        'balance_after' => $balanceAfter,
        'balance_after_text' => $balanceAfterText,
        'calculation_version' => (string)($financials['calculation_version'] ?? topup_calculation_version()),
    ]);
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
    'topup_amount' => $topupAmount,
    'topup_currency' => $topupCurrency,
    'topup_amount_text' => $topupAmountText,
    'currency' => $topupCurrency,
    'financials' => $financials,
    'expires_at' => $now + 300,
    'verified_by' => topup_clean_text($body['verified_by'] ?? '', 30),
];
$previewPayload = array_merge($previewPayload, [
    'account_country' => (string)$financials['account_country'],
    'wallet_currency' => $walletCurrency,
    'wallet_debit' => $walletDebit,
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_bdt' => topup_money($financials['wallet_debit_bdt'] ?? $amount),
    'wallet_debit_myr' => topup_money($financials['wallet_debit_myr'] ?? 0),
    'amount_bdt' => (float)($financials['amount_bdt'] ?? 0),
    'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
    'amount_myr' => (float)($financials['amount_myr'] ?? 0),
    'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
    'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
    'rate_snapshot' => ($financials['rate_snapshot'] ?? null),
    'rate' => $rate,
    'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
    'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
    'commission_value_snapshot' => (float)($financials['commission_value_snapshot'] ?? $financials['commission_per_1000'] ?? 0),
    'commission_amount' => (float)($financials['commission_amount'] ?? 0),
    'commission_credit' => (float)($financials['commission_credit'] ?? 0),
    'fee_amount' => (float)($financials['fee_amount'] ?? 0),
    'balance_before' => $available,
    'balance_after' => $balanceAfter,
    'display_currency' => (string)($financials['display_currency'] ?? $walletCurrency),
    'calculation_version' => (string)($financials['calculation_version'] ?? topup_calculation_version()),
]);

$previewToken = topup_create_preview_token($previewPayload);
if ($previewToken === '') {
    api_response(false, 'TOPUP_PREVIEW_FAILED', 'Top-up preview could not be created.', [], 500);
}

api_response(true, 'TOPUP_PREVIEW_READY', 'Top-up preview ready.', [
    'country' => (string)($countryConfig['name'] ?? $countryCode),
    'country_code' => $countryCode,
    'number' => $topupNumber,
    'topup_number' => $topupNumber,
    'operator' => (string)($operatorConfig['name'] ?? $operator),
    'operator_code' => $operator,
    'amount' => $amount,
    'topup_amount' => $topupAmount,
    'topup_currency' => $topupCurrency,
    'topup_amount_text' => $topupAmountText,
    'amount_bdt' => (float)($financials['amount_bdt'] ?? 0),
    'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? 0),
    'amount_myr' => (float)($financials['amount_myr'] ?? 0),
    'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
    'currency' => $topupCurrency,
    'account_country' => (string)$financials['account_country'],
    'wallet_currency' => $walletCurrency,
    'display_currency' => (string)($financials['display_currency'] ?? $walletCurrency),
    'rate_applicable' => $rateApplicable,
    'rate_snapshot' => $rateApplicable ? $rate : null,
    'rate' => $rateApplicable ? $rate : null,
    'rate_text' => $rateApplicable ? ('RM 1 = ' . number_format($rate, 2, '.', '') . ' BDT') : '',
    'commission_applicable' => $commissionApplicable,
    'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
    'commission_value' => (float)($financials['commission_value'] ?? 0),
    'commission_amount' => $commissionAmount,
    'commission_text' => $commissionApplicable ? topup_amount_text($commissionAmount, 'BDT') : '',
    'fee_amount' => $feeAmount,
    'total_myr' => $walletCurrency === 'MYR' ? $walletDebit : 0,
    'wallet_debit' => $walletDebit,
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_bdt' => (float)($financials['wallet_debit_bdt'] ?? $amount),
    'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
    'total_pay' => $walletDebit,
    'total_pay_text' => $totalPayText,
    'fee_myr' => 0,
    'fee' => $feeAmount,
    'fee_text' => $feeText,
    'balance_before' => $available,
    'balance_after' => $balanceAfter,
    'balance_after_text' => $balanceAfterText,
    'calculation_version' => (string)($financials['calculation_version'] ?? topup_calculation_version()),
    'preview_token' => $previewToken,
    'expires_in' => 300,
]);
