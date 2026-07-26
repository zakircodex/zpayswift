<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Z-Pay Swift - MFS Preview Endpoint
|--------------------------------------------------------------------------
| File: /api/mfs/preview.php
|
| POST JSON:
| {
|   "provider": "BKASH" / "NAGAD",
|   "service_type": "SEND_MONEY" / "CASH_OUT",
|   "account_type": "PERSONAL" / "AGENT",
|   "receiver_number": "01XXXXXXXXX",
|   "amount": 100,
|   "currency": "BDT" / "MYR",
|   "amount_bdt": 3100,
|   "amount_rm": 100,
|   "reference": "optional"
| }
|
| Note:
| - Preview only. Balance hold হবে না।
| - MY user: SEND_MONEY + PERSONAL only.
| - BD user: SEND_MONEY personal, CASH_OUT agent support রাখা হয়েছে।
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);

$user = (array)($auth['user'] ?? []);
$uid = trim((string)($user['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'UNAUTHORIZED', 'User session invalid', [], 401);
}

$body = api_read_json_body();

/* =========================================================
   Local Helpers
========================================================= */

function mfs_preview_now(): int
{
    if (function_exists('mfs_now')) {
        return (int)mfs_now();
    }

    return function_exists('now_ts') ? (int)now_ts() : time();
}

function mfs_preview_fb_get(string $path)
{
    if (function_exists('mfs_fb_get')) {
        return mfs_fb_get($path);
    }

    if (function_exists('fb_get')) {
        return fb_get($path);
    }

    return null;
}

function mfs_preview_round($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
    }

    return round((float)$value, 2);
}

function mfs_preview_string($value): string
{
    return trim((string)$value);
}

function mfs_preview_upper($value): string
{
    return strtoupper(trim((string)$value));
}

function mfs_preview_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    $s = strtoupper(trim((string)$value));

    if (in_array($s, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true)) {
        return true;
    }

    if (in_array($s, ['0', 'FALSE', 'NO', 'OFF', 'INACTIVE', 'DISABLED'], true)) {
        return false;
    }

    return $default;
}

function mfs_preview_money_text(float $amount, string $currency): string
{
    $currency = strtoupper(trim($currency));

    if ($currency === 'MYR') {
        return 'RM ' . number_format($amount, 2, '.', '');
    }

    return 'BDT ' . number_format($amount, 2, '.', '');
}

function mfs_preview_load_wallet(string $uid): array
{
    $row = mfs_preview_fb_get('USER_WALLETS/' . $uid);
    return is_array($row) ? $row : [];
}

function mfs_preview_load_config(): array
{
    $row = mfs_preview_fb_get('MFS_CONFIG');
    $settings = mfs_preview_fb_get('MFS_SETTINGS');

    if (!is_array($row)) {
        $row = [];
    }

    if (is_array($settings)) {
        $row = array_replace_recursive($row, $settings);
    }

    return $row;
}

function mfs_preview_config_path(array $config, array $path, $default = null)
{
    $current = $config;

    foreach ($path as $key) {
        if (!is_array($current) || !array_key_exists($key, $current)) {
            return $default;
        }

        $current = $current[$key];
    }

    return $current;
}

function mfs_preview_normalize_provider(string $provider): string
{
    $provider = strtoupper(trim($provider));

    $map = [
        'BKASH' => 'BKASH',
        'B-KASH' => 'BKASH',
        'B KASH' => 'BKASH',
        'বিকাশ' => 'BKASH',

        'NAGAD' => 'NAGAD',
        'নগদ' => 'NAGAD',
    ];

    return $map[$provider] ?? '';
}

function mfs_preview_provider_name(string $provider): string
{
    $provider = mfs_preview_normalize_provider($provider);

    if ($provider === 'BKASH') {
        return 'bKash';
    }

    if ($provider === 'NAGAD') {
        return 'Nagad';
    }

    return $provider;
}

function mfs_preview_normalize_service_type(string $serviceType): string
{
    $serviceType = strtoupper(trim(str_replace(['-', ' '], '_', $serviceType)));

    $map = [
        'SEND_MONEY' => 'SEND_MONEY',
        'SENDMONEY' => 'SEND_MONEY',
        'SEND' => 'SEND_MONEY',
        'PERSONAL' => 'SEND_MONEY',

        'CASH_OUT' => 'CASH_OUT',
        'CASHOUT' => 'CASH_OUT',
        'WITHDRAW' => 'CASH_OUT',
    ];

    return $map[$serviceType] ?? '';
}

function mfs_preview_service_name(string $serviceType): string
{
    $serviceType = mfs_preview_normalize_service_type($serviceType);

    if ($serviceType === 'SEND_MONEY') {
        return 'Send Money';
    }

    if ($serviceType === 'CASH_OUT') {
        return 'Cash Out';
    }

    return $serviceType;
}

function mfs_preview_normalize_account_type(string $accountType): string
{
    $accountType = strtoupper(trim(str_replace(['-', ' '], '_', $accountType)));

    $map = [
        'PERSONAL' => 'PERSONAL',
        'PERSON' => 'PERSONAL',
        'USER' => 'PERSONAL',
        'CUSTOMER' => 'PERSONAL',

        'AGENT' => 'AGENT',
        'CASH_OUT_AGENT' => 'AGENT',
    ];

    return $map[$accountType] ?? 'PERSONAL';
}

function mfs_preview_normalize_number(string $number): string
{
    return preg_replace('/\D+/', '', trim($number)) ?? '';
}

function mfs_preview_number_valid(string $number): bool
{
    return preg_match('/^01\d{9}$/', $number) === 1;
}

function mfs_preview_normalize_country(string $country): string
{
    $country = strtoupper(trim($country));

    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',

        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
    ];

    return $map[$country] ?? '';
}

function mfs_preview_normalize_currency(string $currency): string
{
    $currency = strtoupper(trim($currency));

    $map = [
        'BDT' => 'BDT',
        'TK' => 'BDT',
        'TAKA' => 'BDT',

        'MYR' => 'MYR',
        'RM' => 'MYR',
        'RINGGIT' => 'MYR',
    ];

    return $map[$currency] ?? '';
}

function mfs_preview_expected_currency_for_country(string $countryCode): string
{
    $countryCode = mfs_preview_normalize_country($countryCode);

    if ($countryCode === 'BD') {
        return 'BDT';
    }

    if ($countryCode === 'MY') {
        return 'MYR';
    }

    return '';
}

function mfs_preview_country_from_user(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        $country = mfs_preview_normalize_country(
            (string)auth_pricing_country_from_user($user, $wallet)
        );
        if ($country !== '') {
            return $country;
        }
    }

    $country = mfs_preview_normalize_country((string)(
        $user['pricing_country']
        ?? $user['market_country']
        ?? $user['service_country']
        ?? $user['country_code']
        ?? $user['country']
        ?? $user['user_country']
        ?? ''
    ));

    if ($country !== '') {
        return $country;
    }

    if (defined('DEFAULT_USER_COUNTRY')) {
        return mfs_preview_normalize_country((string)DEFAULT_USER_COUNTRY);
    }

    return 'BD';
}

function mfs_preview_currency_from_user_wallet(array $user, array $wallet, string $countryCode): string
{
    $currency = mfs_preview_normalize_currency((string)(
        $user['wallet_currency']
        ?? $user['currency']
        ?? ''
    ));

    if ($currency !== '') {
        return $currency;
    }

    $currency = mfs_preview_normalize_currency((string)(
        $wallet['currency']
        ?? $wallet['wallet_currency']
        ?? ''
    ));

    if ($currency !== '') {
        return $currency;
    }

    $expected = mfs_preview_expected_currency_for_country($countryCode);

    if ($expected !== '') {
        return $expected;
    }

    if (defined('DEFAULT_USER_CURRENCY')) {
        return mfs_preview_normalize_currency((string)DEFAULT_USER_CURRENCY);
    }

    return 'BDT';
}

function mfs_preview_service_mode(string $walletCurrency): string
{
    $walletCurrency = mfs_preview_normalize_currency($walletCurrency);

    if ($walletCurrency === 'MYR') {
        return 'REMITTANCE';
    }

    if ($walletCurrency === 'BDT') {
        return 'LOCAL';
    }

    return 'UNKNOWN';
}

function mfs_preview_exchange_rate(array $config): float
{
    $candidates = [
        mfs_preview_config_path($config, ['exchange_rate', 'MYR_TO_BDT']),
        mfs_preview_config_path($config, ['exchange_rates', 'MYR_TO_BDT']),
        mfs_preview_config_path($config, ['rates', 'MYR_TO_BDT']),
        mfs_preview_config_path($config, ['myr_to_bdt_rate']),
        mfs_preview_config_path($config, ['MYR_TO_BDT_RATE']),
    ];

    foreach ($candidates as $value) {
        if (is_numeric($value) && (float)$value > 0) {
            return mfs_preview_round($value);
        }
    }

    if (defined('MYR_TO_BDT_RATE') && (float)MYR_TO_BDT_RATE > 0) {
        return mfs_preview_round((float)MYR_TO_BDT_RATE);
    }

    return 31.00;
}

function mfs_preview_provider_enabled(array $config, string $provider): bool
{
    $provider = mfs_preview_normalize_provider($provider);

    $value = mfs_preview_config_path($config, ['providers', $provider, 'enabled'], null);

    if ($value !== null) {
        return mfs_preview_bool($value, true);
    }

    $value = mfs_preview_config_path($config, ['provider_enabled', $provider], null);

    if ($value !== null) {
        return mfs_preview_bool($value, true);
    }

    return true;
}

function mfs_preview_mfs_enabled(array $config): bool
{
    $value = mfs_preview_config_path($config, ['enabled'], null);

    if ($value !== null) {
        return mfs_preview_bool($value, true);
    }

    $value = mfs_preview_config_path($config, ['mfs_enabled'], null);

    if ($value !== null) {
        return mfs_preview_bool($value, true);
    }

    return true;
}

function mfs_preview_my_fee_rm(array $config, string $role, string $provider = ''): float
{
    $role = strtoupper(trim($role));
    $provider = mfs_preview_normalize_provider($provider);

    $paths = [
        ['fees', 'MY', $provider, $role],
        ['fees', 'MY', $provider, $role, 'fee_rm'],
        ['fees', 'MY', $provider, $role, 'fixed'],
        ['fees', 'MY', $role, 'fee_rm'],
        ['fees', 'MY', $role, 'amount'],
        ['fees', 'MY', $role],
        ['my_remittance_fees', $role],
        ['my_fees', $role],
        ['remittance_fees', 'MY', $role],
    ];

    foreach ($paths as $path) {
        $value = mfs_preview_config_path($config, $path, null);

        if (is_array($value)) {
            foreach (['fee_rm', 'amount', 'fee'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return mfs_preview_round($value[$key]);
                }
            }
        }

        if (is_numeric($value)) {
            return mfs_preview_round($value);
        }
    }

    if ($role === 'SUBADMIN' && defined('MY_REMITTANCE_FEE_SUBADMIN_RM')) {
        return mfs_preview_round((float)MY_REMITTANCE_FEE_SUBADMIN_RM);
    }

    if ($role === 'RETAILER' && defined('MY_REMITTANCE_FEE_RETAILER_RM')) {
        return mfs_preview_round((float)MY_REMITTANCE_FEE_RETAILER_RM);
    }

    if (defined('MY_REMITTANCE_FEE_USER_RM')) {
        return mfs_preview_round((float)MY_REMITTANCE_FEE_USER_RM);
    }

    if ($role === 'SUBADMIN' || $role === 'RETAILER') {
        return 2.00;
    }

    if ($role === 'ADMIN') {
        return 0.00;
    }

    return 5.00;
}

function mfs_preview_fee_from_rule($rule, float $amount): float
{
    if ($rule === null || $rule === '' || $rule === false) {
        return 0.0;
    }

    if (is_numeric($rule)) {
        return mfs_preview_round($rule);
    }

    if (!is_array($rule)) {
        return 0.0;
    }

    if (array_key_exists('active', $rule) && !mfs_preview_bool($rule['active'], true)) {
        return 0.0;
    }

    if (!empty($rule['tiers']) && is_array($rule['tiers'])) {
        foreach ($rule['tiers'] as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $min = isset($tier['min']) ? (float)$tier['min'] : 0.0;
            $max = isset($tier['max']) ? (float)$tier['max'] : 0.0;

            if ($amount < $min) {
                continue;
            }

            if ($max > 0 && $amount > $max) {
                continue;
            }

            return mfs_preview_fee_from_rule($tier, $amount);
        }
    }

    $type = strtoupper(trim((string)($rule['type'] ?? $rule['fee_type'] ?? 'FLAT')));

    if (in_array($type, ['PERCENT', 'PERCENTAGE', 'RATE'], true)) {
        $percent = 0.0;

        foreach (['percent', 'percentage', 'rate', 'fee_percent'] as $key) {
            if (isset($rule[$key]) && is_numeric($rule[$key])) {
                $percent = (float)$rule[$key];
                break;
            }
        }

        $fee = ($amount * $percent) / 100.0;
    } elseif (in_array($type, ['PER_1000', 'PER_THOUSAND'], true)) {
        $per1000 = 0.0;

        foreach (['per_1000', 'fee_per_1000', 'amount'] as $key) {
            if (isset($rule[$key]) && is_numeric($rule[$key])) {
                $per1000 = (float)$rule[$key];
                break;
            }
        }

        $fee = ($amount / 1000.0) * $per1000;
    } else {
        $fee = 0.0;

        foreach (['amount', 'fee', 'flat', 'fee_amount'] as $key) {
            if (isset($rule[$key]) && is_numeric($rule[$key])) {
                $fee = (float)$rule[$key];
                break;
            }
        }
    }

    if (isset($rule['min']) && is_numeric($rule['min'])) {
        $fee = max($fee, (float)$rule['min']);
    }

    if (isset($rule['max']) && is_numeric($rule['max']) && (float)$rule['max'] > 0) {
        $fee = min($fee, (float)$rule['max']);
    }

    return mfs_preview_round($fee);
}

function mfs_preview_bd_fee_bdt(array $config, string $provider, string $serviceType, string $accountType, float $amountBdt): float
{
    $provider = mfs_preview_normalize_provider($provider);
    $serviceType = mfs_preview_normalize_service_type($serviceType);
    $accountType = mfs_preview_normalize_account_type($accountType);

    $paths = [
        ['fees', 'BD', $provider, $serviceType, $accountType],
        ['fees', 'BD', $provider, $serviceType],
        ['fees', 'BD', $serviceType, $accountType],
        ['fees', 'BD', $serviceType],
        ['bd_fees', $provider, $serviceType, $accountType],
        ['bd_fees', $provider, $serviceType],
    ];

    foreach ($paths as $path) {
        $rule = mfs_preview_config_path($config, $path, null);

        if ($rule !== null) {
            return mfs_preview_fee_from_rule($rule, $amountBdt);
        }
    }

    /*
     * Default: 0.
     * Official fee DB/config থেকে set করাই safe, কারণ bKash/Nagad fee পরিবর্তন হতে পারে।
     */
    return 0.0;
}

function mfs_preview_make_preview_id(): string
{
    try {
        return 'MP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable $e) {
        return 'MP' . date('YmdHis') . strtoupper(substr(md5((string)microtime(true)), 0, 8));
    }
}

/* =========================================================
   Main Preview
========================================================= */

$config = mfs_preview_load_config();
$wallet = mfs_preview_load_wallet($uid);

$provider = mfs_preview_normalize_provider((string)($body['provider'] ?? ''));
$serviceType = mfs_preview_normalize_service_type((string)($body['service_type'] ?? 'SEND_MONEY'));
$accountType = mfs_preview_normalize_account_type((string)($body['account_type'] ?? 'PERSONAL'));
$receiverNumber = mfs_preview_normalize_number((string)(
    $body['receiver_number']
    ?? $body['number']
    ?? $body['to_number']
    ?? ''
));

$reference = substr(trim((string)($body['reference'] ?? '')), 0, 80);
$note = substr(trim((string)($body['note'] ?? '')), 0, 160);

$userStatus = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
$userRole = strtoupper(trim((string)($user['role'] ?? 'USER')));

$countryCode = mfs_preview_country_from_user($user, $wallet);
$walletCurrency = mfs_preview_currency_from_user_wallet($user, $wallet, $countryCode);
$expectedCurrency = mfs_preview_expected_currency_for_country($countryCode);
$serviceMode = mfs_preview_service_mode($walletCurrency);
$exchangeRate = mfs_preview_exchange_rate($config);

if ($userStatus !== 'ACTIVE') {
    api_response(false, 'ACCOUNT_INACTIVE', 'Account is inactive', [], 403);
}

if (!mfs_preview_mfs_enabled($config)) {
    api_response(false, 'MFS_DISABLED', 'MFS service is currently disabled', [], 422);
}

if ($provider === '') {
    api_response(false, 'VALIDATION_ERROR', 'Valid provider is required', [], 422);
}

if (!in_array($provider, ['BKASH', 'NAGAD'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'Only BKASH and NAGAD are supported now', [], 422);
}

if (!mfs_preview_provider_enabled($config, $provider)) {
    api_response(false, 'PROVIDER_DISABLED', mfs_preview_provider_name($provider) . ' is disabled now', [
        'provider' => $provider,
    ], 422);
}

if ($serviceType === '') {
    api_response(false, 'VALIDATION_ERROR', 'Valid service type is required', [], 422);
}

if (!in_array($serviceType, ['SEND_MONEY', 'CASH_OUT'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid service type', [], 422);
}

if (!mfs_preview_number_valid($receiverNumber)) {
    api_response(false, 'VALIDATION_ERROR', 'Receiver number must be valid 11 digit BD number', [
        'receiver_number' => $receiverNumber,
    ], 422);
}

if ($countryCode === '') {
    api_response(false, 'COUNTRY_MISSING', 'User country is not set', [], 422);
}

if (!in_array($countryCode, ['BD', 'MY'], true)) {
    api_response(false, 'UNSUPPORTED_COUNTRY', 'Only Bangladesh and Malaysia are supported', [
        'country_code' => $countryCode,
    ], 422);
}

if ($walletCurrency === '') {
    api_response(false, 'WALLET_CURRENCY_MISSING', 'Wallet currency is not set', [], 422);
}

if ($expectedCurrency !== '' && $walletCurrency !== $expectedCurrency) {
    api_response(false, 'COUNTRY_CURRENCY_MISMATCH', 'User country and wallet currency mismatch', [
        'country_code' => $countryCode,
        'wallet_currency' => $walletCurrency,
        'expected_currency' => $expectedCurrency,
    ], 422);
}

/*
 * MY user: only Send Money + Personal.
 * BD user: Send Money personal + Cash Out agent support.
 */
if ($countryCode === 'MY') {
    if ($serviceType !== 'SEND_MONEY' || $accountType !== 'PERSONAL') {
        api_response(false, 'SERVICE_NOT_ALLOWED', 'Malaysia user can use only personal Send Money', [
            'country_code' => $countryCode,
            'service_type' => $serviceType,
            'account_type' => $accountType,
        ], 422);
    }
}

if ($countryCode === 'BD') {
    if ($serviceType === 'SEND_MONEY' && $accountType !== 'PERSONAL') {
        api_response(false, 'SERVICE_NOT_ALLOWED', 'Send Money supports personal account only', [
            'service_type' => $serviceType,
            'account_type' => $accountType,
        ], 422);
    }

    if ($serviceType === 'CASH_OUT' && !in_array($accountType, ['AGENT', 'PERSONAL'], true)) {
        api_response(false, 'SERVICE_NOT_ALLOWED', 'Invalid cash out account type', [
            'service_type' => $serviceType,
            'account_type' => $accountType,
        ], 422);
    }
}

$inputCurrency = mfs_preview_normalize_currency((string)($body['currency'] ?? ''));

$amount = 0.0;
$amountBdt = 0.0;
$amountRm = 0.0;

if (isset($body['amount']) && is_numeric(str_replace(',', '', (string)$body['amount']))) {
    $amount = mfs_preview_round($body['amount']);
}

if (isset($body['amount_bdt']) && is_numeric(str_replace(',', '', (string)$body['amount_bdt']))) {
    $amountBdt = mfs_preview_round($body['amount_bdt']);
}

if (isset($body['amount_rm']) && is_numeric(str_replace(',', '', (string)$body['amount_rm']))) {
    $amountRm = mfs_preview_round($body['amount_rm']);
}

if ($countryCode === 'BD') {
    $inputCurrency = 'BDT';

    if ($amountBdt <= 0 && $amount > 0) {
        $amountBdt = $amount;
    }

    $amountRm = 0.0;
} else {
    if ($inputCurrency === '') {
        $inputCurrency = $amountBdt > 0 ? 'BDT' : 'MYR';
    }

    if (!in_array($inputCurrency, ['MYR', 'BDT'], true)) {
        api_response(false, 'VALIDATION_ERROR', 'Malaysia amount currency must be MYR or BDT', [], 422);
    }

    if ($inputCurrency === 'MYR') {
        if ($amountRm <= 0 && $amount > 0) {
            $amountRm = $amount;
        }

        if ($amountRm > 0) {
            $amountBdt = mfs_preview_round($amountRm * $exchangeRate);
        }
    } else {
        if ($amountBdt <= 0 && $amount > 0) {
            $amountBdt = $amount;
        }

        if ($amountBdt > 0) {
            $amountRm = mfs_preview_round($amountBdt / $exchangeRate);
        }
    }
}

if ($amountBdt < 500 || $amountBdt > 50000) {
    api_response(false, 'VALIDATION_ERROR', 'Amount must be between BDT 500 and BDT 50,000', [], 422);
}

if ($countryCode === 'MY' && ($amountBdt <= 0 || $amountRm <= 0)) {
    api_response(false, 'VALIDATION_ERROR', 'Valid MYR or BDT amount is required', [], 422);
}

$feeBdt = 0.0;
$feeRm = 0.0;
$totalDebit = 0.0;
$totalDebitCurrency = $walletCurrency;

if ($countryCode === 'BD') {
    $feeBdt = mfs_preview_bd_fee_bdt($config, $provider, $serviceType, $accountType, $amountBdt);
    $feeRm = 0.0;
    $totalDebit = mfs_preview_round($amountBdt + $feeBdt);
} else {
    $feeRm = mfs_preview_my_fee_rm($config, $userRole, $provider);
    $feeBdt = mfs_preview_round($feeRm * $exchangeRate);
    $totalDebit = $walletCurrency === 'MYR'
        ? mfs_preview_round($amountRm + $feeRm)
        : mfs_preview_round($amountBdt + $feeBdt);
}

$availableBalance = mfs_preview_round((float)($wallet['available_balance'] ?? 0));
$holdBalance = mfs_preview_round((float)($wallet['hold_balance'] ?? 0));
$balanceAfterDebit = mfs_preview_round($availableBalance - $totalDebit);

$canSubmit = $availableBalance >= $totalDebit;
$validationCode = $canSubmit ? 'READY' : 'INSUFFICIENT_BALANCE';
$validationMessage = $canSubmit
    ? 'Preview ready'
    : 'Insufficient available balance';

$previewId = mfs_preview_make_preview_id();
$now = mfs_preview_now();

$responseData = [
    'preview_id' => $previewId,
    'uid' => $uid,
    'role' => $userRole,

    'provider' => $provider,
    'provider_name' => mfs_preview_provider_name($provider),
    'service_type' => $serviceType,
    'service_name' => mfs_preview_service_name($serviceType),
    'account_type' => $accountType,

    'receiver_number' => $receiverNumber,
    'number' => $receiverNumber,
    'reference' => $reference,
    'note' => $note,

    'country_code' => $countryCode,
    'service_mode' => $serviceMode,
    'wallet_currency' => $walletCurrency,
    'input_currency' => $inputCurrency,
    'exchange_rate' => $exchangeRate,

    'amount_bdt' => $amountBdt,
    'amount_rm' => $amountRm,
    'fee_bdt' => $feeBdt,
    'fee_rm' => $feeRm,
    'fee_currency' => $walletCurrency === 'MYR' ? 'MYR' : 'BDT',
    'fee_amount' => $walletCurrency === 'MYR' ? $feeRm : $feeBdt,

    'total_debit' => $totalDebit,
    'total_pay' => $totalDebit,
    'wallet_hold_amount' => $totalDebit,
    'total_pay_bdt' => $countryCode === 'MY' ? mfs_preview_round($amountBdt + $feeBdt) : $totalDebit,
    'total_pay_myr' => $countryCode === 'MY' ? mfs_preview_round($amountRm + $feeRm) : 0.0,
    'total_debit_currency' => $totalDebitCurrency,
    'total_debit_text' => mfs_preview_money_text($totalDebit, $totalDebitCurrency),

    'available_balance' => $availableBalance,
    'hold_balance' => $holdBalance,
    'balance_after_debit' => $balanceAfterDebit,
    'available_balance_text' => mfs_preview_money_text($availableBalance, $walletCurrency),
    'balance_after_debit_text' => mfs_preview_money_text($balanceAfterDebit, $walletCurrency),

    'trxid' => '',
    'trxid_text' => 'TRXID will be added after successful payment',

    'can_submit' => $canSubmit,
    'validation_code' => $validationCode,
    'validation_message' => $validationMessage,

    'created_at' => $now,
    'expires_in' => $canSubmit ? 300 : 0,
    'preview_token' => '',
];

if ($canSubmit) {
    $previewToken = mfs_create_preview_token(array_merge($responseData, [
        'expires_at' => $now + 300,
        'status' => 'READY',
    ]));

    if ($previewToken === '') {
        api_response(false, 'MFS_PREVIEW_FAILED', 'MFS preview could not be created. Please try again.', [], 500);
    }

    $responseData['preview_token'] = $previewToken;
}

api_response(true, 'SUCCESS', 'MFS preview ready', $responseData, 200);
