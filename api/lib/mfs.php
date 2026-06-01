<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

/*
|--------------------------------------------------------------------------
| ZawTopup / Z-Pay Swift - MFS Core Helper
|--------------------------------------------------------------------------
| Providers: bKash, Nagad
| Modes:
| - BD user = LOCAL, wallet BDT, official/config fee
| - MY user = REMITTANCE, wallet MYR, manual RM fee
|
| Public user status:
| PENDING, PROCESSING, SUCCESSFUL, FAILED
|--------------------------------------------------------------------------
*/

function mfs_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function mfs_month_key(?int $ts = null): string
{
    if ($ts === null && function_exists('month_key')) {
        return (string)month_key();
    }

    return date('Y-m', $ts ?? mfs_now());
}

function mfs_round_money($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
    }

    return round((float)$value, 2);
}

function mfs_make_request_id(): string
{
    try {
        return 'MF' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    } catch (Throwable $e) {
        return 'MF' . date('YmdHis') . strtoupper(substr(md5((string)microtime(true)), 0, 10));
    }
}

function mfs_make_ledger_id(): string
{
    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    try {
        return 'WL' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable $e) {
        return 'WL' . date('YmdHis') . strtoupper(substr(md5((string)microtime(true)), 0, 8));
    }
}

function mfs_fb_get(string $path)
{
    if (!function_exists('fb_get')) {
        return null;
    }

    try {
        return fb_get($path);
    } catch (Throwable $e) {
        return null;
    }
}

function mfs_fb_put(string $path, array $data): bool
{
    if (!function_exists('fb_put')) {
        return false;
    }

    try {
        return (bool)fb_put($path, $data);
    } catch (Throwable $e) {
        return false;
    }
}

function mfs_fb_patch(string $path, array $data): bool
{
    if (!function_exists('fb_patch')) {
        return false;
    }

    try {
        return (bool)fb_patch($path, $data);
    } catch (Throwable $e) {
        return false;
    }
}

function mfs_fb_delete(string $path): bool
{
    if (function_exists('fb_delete')) {
        try {
            return (bool)fb_delete($path);
        } catch (Throwable $e) {
            return false;
        }
    }

    return false;
}

function mfs_bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $s = strtoupper(trim((string)$value));

    return in_array($s, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true);
}

function mfs_const_string(string $name, string $default = ''): string
{
    if (!defined($name)) {
        return $default;
    }

    return trim((string)constant($name));
}

function mfs_const_float(string $name, float $default = 0.0): float
{
    if (!defined($name)) {
        return $default;
    }

    $value = constant($name);

    if (!is_numeric($value)) {
        return $default;
    }

    return mfs_round_money($value);
}

function mfs_const_bool(string $name, bool $default = false): bool
{
    if (!defined($name)) {
        return $default;
    }

    return mfs_bool_value(constant($name));
}

function mfs_bdt_transfer_limits(): array
{
    $minimum = mfs_const_float('MFS_MIN_AMOUNT_BDT', 500.00);
    $maximum = mfs_const_float('MFS_MAX_AMOUNT_BDT', 50000.00);

    return [
        'minimum' => max(0.01, mfs_round_money($minimum)),
        'maximum' => max($minimum, mfs_round_money($maximum)),
    ];
}

function mfs_validate_bdt_transfer_amount(float $amountBdt): array
{
    $limits = mfs_bdt_transfer_limits();
    $amountBdt = mfs_round_money($amountBdt);

    if ($amountBdt < (float)$limits['minimum']) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Minimum amount is BDT ' . number_format((float)$limits['minimum'], 2, '.', ''),
            'data' => [
                'minimum_amount_bdt' => (float)$limits['minimum'],
            ],
        ];
    }

    if ($amountBdt > (float)$limits['maximum']) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Maximum amount is BDT ' . number_format((float)$limits['maximum'], 2, '.', ''),
            'data' => [
                'maximum_amount_bdt' => (float)$limits['maximum'],
            ],
        ];
    }

    return ['ok' => true];
}

/* =========================================================
   Provider / Service / Number Normalize
========================================================= */

function mfs_normalize_provider(string $provider): string
{
    $provider = strtoupper(trim($provider));

    $map = [
        'BKASH' => 'BKASH',
        'B-KASH' => 'BKASH',
        'B KASH' => 'BKASH',
        'বিকাশ' => 'BKASH',

        'NAGAD' => 'NAGAD',
        'NOGOD' => 'NAGAD',
        'নগদ' => 'NAGAD',
    ];

    return $map[$provider] ?? '';
}

function mfs_provider_name(string $provider): string
{
    $provider = mfs_normalize_provider($provider);

    $map = [
        'BKASH' => 'bKash',
        'NAGAD' => 'Nagad',
    ];

    return $map[$provider] ?? $provider;
}

function mfs_provider_enabled(string $provider): bool
{
    $provider = mfs_normalize_provider($provider);

    if ($provider === 'BKASH') {
        return mfs_const_bool('MFS_PROVIDER_BKASH_ENABLED', true);
    }

    if ($provider === 'NAGAD') {
        return mfs_const_bool('MFS_PROVIDER_NAGAD_ENABLED', true);
    }

    return false;
}

function mfs_normalize_service_type(string $serviceType): string
{
    $serviceType = strtoupper(trim(str_replace(['-', ' '], '_', $serviceType)));

    $map = [
        'SEND' => 'SEND_MONEY',
        'SEND_MONEY' => 'SEND_MONEY',
        'PERSONAL_SEND' => 'SEND_MONEY',
        'TRANSFER' => 'SEND_MONEY',
        'REMITTANCE' => 'SEND_MONEY',

        'CASHOUT' => 'CASH_OUT',
        'CASH_OUT' => 'CASH_OUT',
        'AGENT_CASHOUT' => 'CASH_OUT',
        'WITHDRAW' => 'CASH_OUT',
    ];

    return $map[$serviceType] ?? '';
}

function mfs_service_name(string $serviceType): string
{
    $serviceType = mfs_normalize_service_type($serviceType);

    $map = [
        'SEND_MONEY' => 'Send Money',
        'CASH_OUT' => 'Cash Out',
    ];

    return $map[$serviceType] ?? $serviceType;
}

function mfs_normalize_account_type(string $accountType, string $serviceType = ''): string
{
    $accountType = strtoupper(trim(str_replace(['-', ' '], '_', $accountType)));

    if ($accountType === '') {
        $serviceType = mfs_normalize_service_type($serviceType);

        if ($serviceType === 'CASH_OUT') {
            return 'AGENT';
        }

        return 'PERSONAL';
    }

    $map = [
        'PERSONAL' => 'PERSONAL',
        'P' => 'PERSONAL',
        'USER' => 'PERSONAL',

        'AGENT' => 'AGENT',
        'A' => 'AGENT',
    ];

    return $map[$accountType] ?? '';
}

function mfs_clean_mobile_number(string $number): string
{
    return preg_replace('/\D+/', '', trim($number)) ?? '';
}

function mfs_valid_bd_mobile(string $number): bool
{
    $number = mfs_clean_mobile_number($number);

    return (bool)preg_match('/^01\d{9}$/', $number);
}

/* =========================================================
   Config Helpers
========================================================= */

function mfs_config(bool $refresh = false): array
{
    static $cache = null;

    if (!$refresh && is_array($cache)) {
        return $cache;
    }

    $row = mfs_fb_get('MFS_CONFIG');

    $cache = is_array($row) ? $row : [];

    return $cache;
}

function mfs_nested_value(array $data, string $path, $default = null)
{
    $path = trim(str_replace('/', '.', $path), '.');

    if ($path === '') {
        return $default;
    }

    $parts = explode('.', $path);
    $current = $data;

    foreach ($parts as $part) {
        if (!is_array($current)) {
            return $default;
        }

        if (array_key_exists($part, $current)) {
            $current = $current[$part];
            continue;
        }

        $upper = strtoupper($part);
        if (array_key_exists($upper, $current)) {
            $current = $current[$upper];
            continue;
        }

        $lower = strtolower($part);
        if (array_key_exists($lower, $current)) {
            $current = $current[$lower];
            continue;
        }

        return $default;
    }

    return $current;
}

function mfs_config_first(array $paths, $default = null)
{
    $config = mfs_config();

    foreach ($paths as $path) {
        $value = mfs_nested_value($config, (string)$path, null);

        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return $default;
}

function mfs_config_float(array $paths, float $default = 0.0): float
{
    $value = mfs_config_first($paths, null);

    if ($value === null || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return mfs_round_money($value);
}

function mfs_myr_to_bdt_rate(): float
{
    $rate = mfs_config_float([
        'myr_to_bdt_rate',
        'MYR_TO_BDT_RATE',
        'rate.myr_to_bdt',
        'rates.myr_to_bdt',
        'MY.rate',
        'REMITTANCE.rate',
    ], 0.0);

    if ($rate <= 0) {
        $rate = mfs_const_float('MYR_TO_BDT_RATE', 31.00);
    }

    if ($rate <= 0) {
        $rate = 31.00;
    }

    return mfs_round_money($rate);
}

/* =========================================================
   User / Wallet / Country / Currency
========================================================= */

function mfs_load_user(string $uid): array
{
    $uid = trim($uid);

    if ($uid === '') {
        return [];
    }

    $row = mfs_fb_get('USERS/' . $uid);

    if (!is_array($row)) {
        return [];
    }

    $row['uid'] = (string)($row['uid'] ?? $uid);

    return $row;
}

function mfs_load_wallet(string $uid): array
{
    $uid = trim($uid);

    if ($uid === '') {
        return [];
    }

    $row = mfs_fb_get('USER_WALLETS/' . $uid);

    return is_array($row) ? $row : [];
}

function mfs_user_role(array $user): string
{
    return strtoupper(trim((string)($user['role'] ?? 'USER')));
}

function mfs_user_status(array $user): string
{
    return strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
}

function mfs_normalize_country_code(string $country): string
{
    if (function_exists('security_normalize_country_code')) {
        return (string)security_normalize_country_code($country);
    }

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

function mfs_normalize_currency(string $currency): string
{
    if (function_exists('security_normalize_currency')) {
        return (string)security_normalize_currency($currency);
    }

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

function mfs_user_country_code(array $user): string
{
    if (function_exists('security_user_country_code')) {
        $country = (string)security_user_country_code($user);
        if ($country !== '') {
            return $country;
        }
    }

    $country = mfs_normalize_country_code((string)(
        $user['country_code']
        ?? $user['country']
        ?? $user['user_country']
        ?? ''
    ));

    if ($country !== '') {
        return $country;
    }

    return mfs_normalize_country_code(mfs_const_string('DEFAULT_USER_COUNTRY', 'BD'));
}

function mfs_wallet_currency(array $user, array $wallet = []): string
{
    if (function_exists('security_user_wallet_currency')) {
        $currency = (string)security_user_wallet_currency($user, $wallet);
        if ($currency !== '') {
            return $currency;
        }
    }

    $currency = mfs_normalize_currency((string)(
        $user['wallet_currency']
        ?? $user['currency']
        ?? ''
    ));

    if ($currency !== '') {
        return $currency;
    }

    $currency = mfs_normalize_currency((string)(
        $wallet['currency']
        ?? $wallet['wallet_currency']
        ?? ''
    ));

    if ($currency !== '') {
        return $currency;
    }

    $country = mfs_user_country_code($user);

    if ($country === 'MY') {
        return 'MYR';
    }

    return mfs_normalize_currency(mfs_const_string('DEFAULT_USER_CURRENCY', 'BDT')) ?: 'BDT';
}

function mfs_service_mode_from_currency(string $currency): string
{
    if (function_exists('security_service_mode_from_currency')) {
        return (string)security_service_mode_from_currency($currency);
    }

    $currency = mfs_normalize_currency($currency);

    if ($currency === 'BDT') {
        return 'LOCAL';
    }

    if ($currency === 'MYR') {
        return 'REMITTANCE';
    }

    return 'UNKNOWN';
}

function mfs_country_wallet_check(array $user, array $wallet): array
{
    if (function_exists('security_validate_country_wallet_lock')) {
        $check = security_validate_country_wallet_lock($user, $wallet);

        if (is_array($check) && !empty($check)) {
            return $check;
        }
    }

    $country = mfs_user_country_code($user);
    $currency = mfs_wallet_currency($user, $wallet);

    $expected = '';
    if ($country === 'BD') {
        $expected = 'BDT';
    } elseif ($country === 'MY') {
        $expected = 'MYR';
    }

    if ($country === '') {
        return [
            'ok' => false,
            'code' => 'COUNTRY_MISSING',
            'message' => 'User country is not set',
            'country_code' => '',
            'wallet_currency' => $currency,
            'expected_currency' => $expected,
            'service_mode' => mfs_service_mode_from_currency($currency),
        ];
    }

    if ($currency === '') {
        return [
            'ok' => false,
            'code' => 'WALLET_CURRENCY_MISSING',
            'message' => 'Wallet currency is not set',
            'country_code' => $country,
            'wallet_currency' => '',
            'expected_currency' => $expected,
            'service_mode' => 'UNKNOWN',
        ];
    }

    if ($expected !== '' && $currency !== $expected) {
        return [
            'ok' => false,
            'code' => 'COUNTRY_CURRENCY_MISMATCH',
            'message' => 'User country and wallet currency mismatch',
            'country_code' => $country,
            'wallet_currency' => $currency,
            'expected_currency' => $expected,
            'service_mode' => mfs_service_mode_from_currency($currency),
        ];
    }

    return [
        'ok' => true,
        'code' => 'COUNTRY_WALLET_OK',
        'message' => 'Country and wallet currency valid',
        'country_code' => $country,
        'wallet_currency' => $currency,
        'expected_currency' => $expected,
        'service_mode' => mfs_service_mode_from_currency($currency),
    ];
}

/* =========================================================
   Fee / Amount Calculation
========================================================= */

function mfs_official_fee_row(string $provider, string $serviceType): array
{
    $provider = mfs_normalize_provider($provider);
    $serviceType = mfs_normalize_service_type($serviceType);

    $paths = [
        'BD.' . $provider . '.' . $serviceType,
        'LOCAL.' . $provider . '.' . $serviceType,
        'official_fees.' . $provider . '.' . $serviceType,
        'fees.BD.' . $provider . '.' . $serviceType,
        'fees.LOCAL.' . $provider . '.' . $serviceType,
        'providers.' . $provider . '.fees.' . $serviceType,
    ];

    $config = mfs_config();

    foreach ($paths as $path) {
        $row = mfs_nested_value($config, $path, null);

        if (is_array($row)) {
            return $row;
        }
    }

    return [];
}

function mfs_pick_fee_float(array $row, array $keys, float $default = 0.0): float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }

        if ($value !== '' && is_numeric($value)) {
            return mfs_round_money($value);
        }
    }

    return $default;
}

function mfs_bd_official_fee_bdt(string $provider, string $serviceType, float $amountBdt): float
{
    $provider = mfs_normalize_provider($provider);
    $serviceType = mfs_normalize_service_type($serviceType);

    $row = mfs_official_fee_row($provider, $serviceType);

    $fixed = mfs_pick_fee_float($row, ['fixed_fee', 'fixed', 'fee_fixed', 'flat_fee'], 0.0);
    $percent = mfs_pick_fee_float($row, ['percent_fee', 'percent', 'fee_percent', 'rate_percent'], 0.0);
    $minFee = mfs_pick_fee_float($row, ['min_fee', 'minimum_fee'], 0.0);
    $maxFee = mfs_pick_fee_float($row, ['max_fee', 'maximum_fee'], 0.0);

    $fee = $fixed;

    if ($percent > 0) {
        $fee += ($amountBdt * $percent / 100);
    }

    if ($minFee > 0 && $fee < $minFee) {
        $fee = $minFee;
    }

    if ($maxFee > 0 && $fee > $maxFee) {
        $fee = $maxFee;
    }

    return mfs_round_money($fee);
}

function mfs_remittance_fee_rm(string $role, string $provider = ''): float
{
    $role = strtoupper(trim($role));
    $provider = mfs_normalize_provider($provider);

    $paths = [];

    if ($provider !== '') {
        $paths[] = 'MY.' . $provider . '.remittance_fee_rm.' . $role;
        $paths[] = 'REMITTANCE.' . $provider . '.fee_rm.' . $role;
        $paths[] = 'fees.MY.' . $provider . '.' . $role;
    }

    $paths[] = 'MY.remittance_fee_rm.' . $role;
    $paths[] = 'REMITTANCE.fee_rm.' . $role;
    $paths[] = 'remittance_fee_rm.' . $role;
    $paths[] = 'fees.MY.' . $role;

    $fee = mfs_config_float($paths, -1.0);

    if ($fee >= 0) {
        return mfs_round_money($fee);
    }

    if ($role === 'SUBADMIN') {
        return mfs_const_float('MY_REMITTANCE_FEE_SUBADMIN_RM', 2.00);
    }

    if ($role === 'RETAILER') {
        return mfs_const_float('MY_REMITTANCE_FEE_RETAILER_RM', 3.00);
    }

    if ($role === 'ADMIN') {
        return mfs_const_float('MY_REMITTANCE_FEE_ADMIN_RM', 0.00);
    }

    return mfs_const_float('MY_REMITTANCE_FEE_USER_RM', 5.00);
}

function mfs_amount_input_value(array $body, array $keys): float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }

        $value = $body[$key];

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }

        if ($value !== '' && is_numeric($value)) {
            return mfs_round_money($value);
        }
    }

    return 0.0;
}

function mfs_calculate_amounts(array $user, array $wallet, string $provider, string $serviceType, array $body): array
{
    $check = mfs_country_wallet_check($user, $wallet);

    if (empty($check['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($check['code'] ?? 'COUNTRY_WALLET_ERROR'),
            'message' => (string)($check['message'] ?? 'Country and wallet validation failed'),
            'data' => $check,
        ];
    }

    $countryCode = (string)$check['country_code'];
    $walletCurrency = (string)$check['wallet_currency'];
    $serviceMode = (string)$check['service_mode'];
    $role = mfs_user_role($user);
    $rate = mfs_myr_to_bdt_rate();

    $inputCurrency = mfs_normalize_currency((string)(
        $body['currency']
        ?? $body['input_currency']
        ?? ''
    ));

    $amount = mfs_amount_input_value($body, ['amount']);
    $amountBdt = mfs_amount_input_value($body, ['amount_bdt', 'bdt_amount', 'send_amount_bdt']);
    $amountRm = mfs_amount_input_value($body, ['amount_rm', 'amount_myr', 'rm_amount', 'myr_amount', 'send_amount_rm']);

    if ($serviceMode === 'LOCAL' || $walletCurrency === 'BDT' || $countryCode === 'BD') {
        if ($amountBdt <= 0 && $amount > 0) {
            $amountBdt = $amount;
        }

        $amountBdt = mfs_round_money($amountBdt);

        $limitCheck = mfs_validate_bdt_transfer_amount($amountBdt);

        if (empty($limitCheck['ok'])) {
            return $limitCheck;
        }

        $feeBdt = mfs_bd_official_fee_bdt($provider, $serviceType, $amountBdt);
        $totalDebitBdt = mfs_round_money($amountBdt + $feeBdt);

        return [
            'ok' => true,
            'country_code' => 'BD',
            'wallet_currency' => 'BDT',
            'service_mode' => 'LOCAL',
            'exchange_rate' => 1.0,

            'amount_bdt' => $amountBdt,
            'amount_rm' => 0.0,

            'fee_bdt' => $feeBdt,
            'fee_rm' => 0.0,

            'total_debit_bdt' => $totalDebitBdt,
            'total_debit_rm' => 0.0,
            'total_debit' => $totalDebitBdt,
        ];
    }

    if ($serviceMode === 'REMITTANCE' || $walletCurrency === 'MYR' || $countryCode === 'MY') {
        if ($amountRm <= 0 && $amount > 0 && ($inputCurrency === 'MYR' || $inputCurrency === '')) {
            $amountRm = $amount;
        }

        if ($amountBdt <= 0 && $amount > 0 && $inputCurrency === 'BDT') {
            $amountBdt = $amount;
        }

        if ($amountRm > 0 && $amountBdt <= 0) {
            $amountBdt = mfs_round_money($amountRm * $rate);
        }

        if ($amountBdt > 0 && $amountRm <= 0) {
            $amountRm = mfs_round_money($amountBdt / $rate);
        }

        $amountRm = mfs_round_money($amountRm);
        $amountBdt = mfs_round_money($amountBdt);

        $limitCheck = mfs_validate_bdt_transfer_amount($amountBdt);

        if (empty($limitCheck['ok'])) {
            return $limitCheck;
        }

        $feeRm = mfs_remittance_fee_rm($role, $provider);
        $totalDebitRm = mfs_round_money($amountRm + $feeRm);

        return [
            'ok' => true,
            'country_code' => 'MY',
            'wallet_currency' => 'MYR',
            'service_mode' => 'REMITTANCE',
            'exchange_rate' => $rate,

            'amount_bdt' => $amountBdt,
            'amount_rm' => $amountRm,

            'fee_bdt' => 0.0,
            'fee_rm' => $feeRm,

            'total_debit_bdt' => 0.0,
            'total_debit_rm' => $totalDebitRm,
            'total_debit' => $totalDebitRm,
        ];
    }

    return [
        'ok' => false,
        'code' => 'UNSUPPORTED_COUNTRY_CURRENCY',
        'message' => 'Unsupported country or wallet currency',
        'data' => [
            'country_code' => $countryCode,
            'wallet_currency' => $walletCurrency,
            'service_mode' => $serviceMode,
        ],
    ];
}

/* =========================================================
   Policy Validation
========================================================= */

function mfs_validate_policy(string $countryCode, string $serviceMode, string $serviceType, string $accountType): array
{
    $countryCode = mfs_normalize_country_code($countryCode);
    $serviceMode = strtoupper(trim($serviceMode));
    $serviceType = mfs_normalize_service_type($serviceType);
    $accountType = mfs_normalize_account_type($accountType, $serviceType);

    if ($countryCode === 'MY' || $serviceMode === 'REMITTANCE') {
        if (!mfs_const_bool('MFS_MY_REMITTANCE_ENABLED', true)) {
            return [false, 'Malaysia remittance is disabled'];
        }

        if ($serviceType !== 'SEND_MONEY') {
            return [false, 'Malaysia user can only use Send Money'];
        }

        if ($accountType !== 'PERSONAL') {
            return [false, 'Malaysia user can only send to personal account'];
        }

        return [true, 'OK'];
    }

    if ($countryCode === 'BD' || $serviceMode === 'LOCAL') {
        if ($serviceType === 'SEND_MONEY') {
            if (!mfs_const_bool('MFS_BD_SEND_MONEY_ENABLED', true)) {
                return [false, 'BD Send Money is disabled'];
            }

            if ($accountType !== 'PERSONAL') {
                return [false, 'Send Money requires personal account'];
            }

            return [true, 'OK'];
        }

        if ($serviceType === 'CASH_OUT') {
            if (!mfs_const_bool('MFS_BD_CASH_OUT_ENABLED', true)) {
                return [false, 'BD Cash Out is disabled'];
            }

            if ($accountType !== 'AGENT') {
                return [false, 'Cash Out requires agent account'];
            }

            return [true, 'OK'];
        }
    }

    return [false, 'Unsupported MFS service'];
}

/* =========================================================
   Wallet Hold / Settlement
========================================================= */

function mfs_wallet_available_balance(array $wallet): float
{
    return mfs_round_money((float)($wallet['available_balance'] ?? 0));
}

function mfs_wallet_hold_balance(array $wallet): float
{
    return mfs_round_money((float)($wallet['hold_balance'] ?? 0));
}

function mfs_hold_wallet(string $uid, float $amount, string $currency, string $requestId, string $note): array
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $currency = mfs_normalize_currency($currency);
    $amount = mfs_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0 || $currency === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid wallet hold data',
            'data' => [],
        ];
    }

    $wallet = mfs_load_wallet($uid);
    $available = mfs_wallet_available_balance($wallet);
    $hold = mfs_wallet_hold_balance($wallet);

    if ($available < $amount) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient available balance',
            'data' => [
                'available_balance' => $available,
                'required_amount' => $amount,
                'currency' => $currency,
            ],
        ];
    }

    $now = mfs_now();
    $newAvailable = mfs_round_money($available - $amount);
    $newHold = mfs_round_money($hold + $amount);

    $ok = mfs_fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to hold wallet balance',
            'data' => [],
        ];
    }

    $ledgerId = mfs_make_ledger_id();
    $month = mfs_month_key($now);

    mfs_fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'MFS_HOLD',
        'direction' => 'HOLD',
        'amount' => $amount,
        'currency' => $currency,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => $uid,
        'created_by_role' => 'USER',
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet balance held',
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'currency' => $currency,
    ];
}

function mfs_release_hold(string $uid, float $amount, string $currency, string $requestId, string $note, string $ledgerType = 'MFS_FAILED_RELEASE'): array
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $currency = mfs_normalize_currency($currency);
    $amount = mfs_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0 || $currency === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid release data',
            'data' => [],
        ];
    }

    $wallet = mfs_load_wallet($uid);
    $available = mfs_wallet_available_balance($wallet);
    $hold = mfs_wallet_hold_balance($wallet);

    $now = mfs_now();
    $newAvailable = mfs_round_money($available + $amount);
    $newHold = mfs_round_money(max(0, $hold - $amount));

    $ok = mfs_fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'total_refund' => mfs_round_money((float)($wallet['total_refund'] ?? 0) + $amount),
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to release wallet hold',
            'data' => [],
        ];
    }

    $ledgerId = mfs_make_ledger_id();
    $month = mfs_month_key($now);

    mfs_fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => $ledgerType,
        'direction' => 'RELEASE_HOLD',
        'amount' => $amount,
        'currency' => $currency,
        'before_available' => $available,
        'after_available' => $newAvailable,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => 'SYSTEM',
        'created_by_role' => 'SYSTEM',
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet hold released',
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'currency' => $currency,
    ];
}

function mfs_debit_hold_success(string $uid, float $amount, string $currency, string $requestId, string $note): array
{
    $uid = trim($uid);
    $requestId = trim($requestId);
    $currency = mfs_normalize_currency($currency);
    $amount = mfs_round_money($amount);

    if ($uid === '' || $requestId === '' || $amount <= 0 || $currency === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid success settlement data',
            'data' => [],
        ];
    }

    $wallet = mfs_load_wallet($uid);
    $available = mfs_wallet_available_balance($wallet);
    $hold = mfs_wallet_hold_balance($wallet);

    $now = mfs_now();
    $newHold = mfs_round_money(max(0, $hold - $amount));
    $totalMfsSpent = mfs_round_money((float)($wallet['total_mfs_spent'] ?? 0) + $amount);

    $ok = mfs_fb_patch('USER_WALLETS/' . $uid, [
        'hold_balance' => $newHold,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'total_mfs_spent' => $totalMfsSpent,
        'updated_at' => $now,
    ]);

    if (!$ok) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to settle wallet hold',
            'data' => [],
        ];
    }

    $ledgerId = mfs_make_ledger_id();
    $month = mfs_month_key($now);

    mfs_fb_put('WALLET_LEDGER/' . $uid . '/' . $month . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'MFS_SUCCESS',
        'direction' => 'DEBIT_HOLD',
        'amount' => $amount,
        'currency' => $currency,
        'before_available' => $available,
        'after_available' => $available,
        'before_hold' => $hold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'request_id' => $requestId,
        'note' => $note,
        'created_at' => $now,
        'created_by_uid' => 'SYSTEM',
        'created_by_role' => 'SYSTEM',
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet hold settled',
        'available_balance' => $available,
        'hold_balance' => $newHold,
        'currency' => $currency,
    ];
}

/* =========================================================
   Request Logs / Status
========================================================= */

function mfs_create_request_status(string $requestId, string $uid, string $status, string $message): void
{
    $now = mfs_now();

    mfs_fb_put('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'type' => 'MFS',
        'request_type' => 'MFS',
        'uid' => $uid,
        'status' => strtoupper($status),
        'message' => $message,
        'updated_at' => $now,
    ]);
}

function mfs_update_request_status(string $requestId, string $uid, string $status, string $message, int $completedAt = 0): void
{
    $now = mfs_now();

    $patch = [
        'request_id' => $requestId,
        'type' => 'MFS',
        'request_type' => 'MFS',
        'uid' => $uid,
        'status' => strtoupper($status),
        'message' => $message,
        'updated_at' => $now,
    ];

    if ($completedAt > 0) {
        $patch['completed_at'] = $completedAt;
    }

    mfs_fb_patch('REQUEST_STATUS/' . $requestId, $patch);
}

function mfs_public_log_row(array $row): array
{
    $provider = mfs_normalize_provider((string)($row['provider'] ?? ''));
    $serviceType = mfs_normalize_service_type((string)($row['service_type'] ?? 'SEND_MONEY'));

    return [
        'request_id' => (string)($row['request_id'] ?? ''),
        'key_id' => (string)($row['key_id'] ?? $row['source_key_id'] ?? 'PANEL'),
        'action' => 'MFS',
        'request_type' => 'MFS',
        'source' => (string)($row['source'] ?? $row['request_source'] ?? 'USER_PANEL'),
        'request_source' => (string)($row['request_source'] ?? $row['source'] ?? 'USER_PANEL'),

        'status' => (string)($row['status'] ?? 'PENDING'),

        'provider' => $provider,
        'provider_name' => mfs_provider_name($provider),
        'service_type' => $serviceType,
        'service_name' => mfs_service_name($serviceType),
        'account_type' => (string)($row['account_type'] ?? 'PERSONAL'),

        'receiver_number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),
        'number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),

        'country_code' => (string)($row['country_code'] ?? ''),
        'service_mode' => (string)($row['service_mode'] ?? ''),
        'wallet_currency' => (string)($row['wallet_currency'] ?? ''),

        'amount' => (float)($row['total_debit'] ?? 0),
        'amount_bdt' => (float)($row['amount_bdt'] ?? 0),
        'amount_rm' => (float)($row['amount_rm'] ?? 0),

        'fee_bdt' => (float)($row['fee_bdt'] ?? 0),
        'fee_rm' => (float)($row['fee_rm'] ?? 0),

        'total_debit' => (float)($row['total_debit'] ?? 0),
        'total_debit_bdt' => (float)($row['total_debit_bdt'] ?? 0),
        'total_debit_rm' => (float)($row['total_debit_rm'] ?? 0),

        'exchange_rate' => (float)($row['exchange_rate'] ?? 0),

        'reference' => (string)($row['reference'] ?? ''),
        'trxid' => (string)($row['trxid'] ?? ''),
        'sender_details' => (string)($row['sender_details'] ?? $row['sender_last_digit'] ?? $row['last_digit'] ?? ''),
        'sender_last_digit' => (string)($row['sender_last_digit'] ?? ''),
        'message' => (string)($row['final_message'] ?? $row['message'] ?? $row['note'] ?? ''),

        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'completed_at' => (int)($row['completed_at'] ?? 0),
    ];
}

function mfs_write_user_request_log(string $uid, string $requestId, array $row): void
{
    $uid = trim($uid);
    $requestId = trim($requestId);

    if ($uid === '' || $requestId === '') {
        return;
    }

    mfs_fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, mfs_public_log_row($row));
}

function mfs_write_history(string $uid, string $requestId, array $row): void
{
    $uid = trim($uid);
    $requestId = trim($requestId);

    if ($uid === '' || $requestId === '') {
        return;
    }

    $ts = (int)($row['updated_at'] ?? $row['created_at'] ?? mfs_now());
    $month = mfs_month_key($ts);

    mfs_fb_patch('MFS_HISTORY/' . $uid . '/' . $month . '/' . $requestId, mfs_public_log_row($row));
}

/* =========================================================
   Create MFS Request
========================================================= */

function mfs_create_request(string $uid, array $body, string $source = 'USER_PANEL', string $sourceKeyId = 'PANEL', array $actor = []): array
{
    $uid = trim($uid);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    if (!mfs_const_bool('MFS_ENABLED', true)) {
        return [
            'ok' => false,
            'code' => 'MFS_DISABLED',
            'message' => 'MFS service is disabled',
            'data' => [],
        ];
    }

    $user = mfs_load_user($uid);

    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    if (mfs_user_status($user) !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    $wallet = mfs_load_wallet($uid);

    $provider = mfs_normalize_provider((string)($body['provider'] ?? $body['mfs_provider'] ?? ''));
    $serviceType = mfs_normalize_service_type((string)($body['service_type'] ?? $body['service'] ?? 'SEND_MONEY'));
    $accountType = mfs_normalize_account_type((string)($body['account_type'] ?? ''), $serviceType);
    $receiverNumber = mfs_clean_mobile_number((string)($body['receiver_number'] ?? $body['number'] ?? $body['mobile'] ?? ''));
    $pin = trim((string)($body['pin'] ?? $body['transaction_pin'] ?? ''));
    $reference = trim((string)($body['reference'] ?? $body['ref'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));

    if ($provider === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid provider is required',
            'data' => [],
        ];
    }

    if (!mfs_provider_enabled($provider)) {
        return [
            'ok' => false,
            'code' => 'PROVIDER_DISABLED',
            'message' => mfs_provider_name($provider) . ' is disabled',
            'data' => [
                'provider' => $provider,
            ],
        ];
    }

    if ($serviceType === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid service type is required',
            'data' => [],
        ];
    }

    if ($accountType === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid account type is required',
            'data' => [],
        ];
    }

    if (!mfs_valid_bd_mobile($receiverNumber)) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Receiver number must be a valid 11 digit BD mobile number',
            'data' => [
                'required_format' => '01XXXXXXXXX',
            ],
        ];
    }

    $skipPinValidation = !empty($actor['skip_pin_validation'])
        && strtoupper(trim((string)($actor['role'] ?? ''))) === 'ADMIN';

    if (!$skipPinValidation) {
        if ($pin === '') {
            return [
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => 'PIN is required',
                'data' => [],
            ];
        }

        $pinHash = (string)($user['pin_hash'] ?? '');

        if ($pinHash === '' || !password_verify($pin, $pinHash)) {
            return [
                'ok' => false,
                'code' => 'INVALID_PIN',
                'message' => 'Invalid transaction PIN',
                'data' => [],
            ];
        }
    }

    $amounts = mfs_calculate_amounts($user, $wallet, $provider, $serviceType, $body);

    if (empty($amounts['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($amounts['code'] ?? 'VALIDATION_ERROR'),
            'message' => (string)($amounts['message'] ?? 'Invalid amount'),
            'data' => (array)($amounts['data'] ?? []),
        ];
    }

    [$policyOk, $policyMessage] = mfs_validate_policy(
        (string)$amounts['country_code'],
        (string)$amounts['service_mode'],
        $serviceType,
        $accountType
    );

    if (!$policyOk) {
        return [
            'ok' => false,
            'code' => 'SERVICE_NOT_ALLOWED',
            'message' => $policyMessage,
            'data' => [
                'country_code' => (string)$amounts['country_code'],
                'service_mode' => (string)$amounts['service_mode'],
                'service_type' => $serviceType,
                'account_type' => $accountType,
            ],
        ];
    }

    $requestId = mfs_make_request_id();
    $now = mfs_now();
    $walletCurrency = (string)$amounts['wallet_currency'];
    $totalDebit = mfs_round_money((float)$amounts['total_debit']);

    $hold = mfs_hold_wallet(
        $uid,
        $totalDebit,
        $walletCurrency,
        $requestId,
        'Balance held for MFS request'
    );

    if (empty($hold['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($hold['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($hold['message'] ?? 'Failed to hold wallet balance'),
            'data' => (array)($hold['data'] ?? []),
        ];
    }

    if ($reference === '') {
        $reference = 'ZP-' . $requestId;
    }

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => (string)($user['phone'] ?? ''),

        'provider' => $provider,
        'provider_name' => mfs_provider_name($provider),
        'service_type' => $serviceType,
        'service_name' => mfs_service_name($serviceType),
        'account_type' => $accountType,

        'receiver_number' => $receiverNumber,
        'number' => $receiverNumber,

        'country_code' => (string)$amounts['country_code'],
        'service_mode' => (string)$amounts['service_mode'],
        'wallet_currency' => $walletCurrency,

        'amount_bdt' => (float)$amounts['amount_bdt'],
        'amount_rm' => (float)$amounts['amount_rm'],
        'fee_bdt' => (float)$amounts['fee_bdt'],
        'fee_rm' => (float)$amounts['fee_rm'],
        'total_debit_bdt' => (float)$amounts['total_debit_bdt'],
        'total_debit_rm' => (float)$amounts['total_debit_rm'],
        'total_debit' => $totalDebit,
        'exchange_rate' => (float)$amounts['exchange_rate'],

        'reference' => $reference,
        'trxid' => '',

        'status' => 'PENDING',
        'public_status' => 'PENDING',
        'process_status' => 'PENDING',
        'message' => $note !== '' ? $note : 'MFS request created',
        'final_message' => '',

        'request_pin_verified' => true,

        'source' => $source,
        'request_source' => $source,
        'key_id' => $sourceKeyId,
        'source_key_id' => $sourceKeyId,

        'held_amount' => $totalDebit,
        'wallet_hold_amount' => $totalDebit,
        'hold_settlement_status' => 'PENDING',
        'hold_settled_at' => 0,

        'telegram_sent' => false,
        'telegram_queue_id' => '',

        'created_by_uid' => (string)($actor['uid'] ?? $uid),
        'created_by_role' => (string)($actor['role'] ?? mfs_user_role($user)),

        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => 0,
    ];

    $saved = mfs_fb_put('MFS_REQUESTS/PENDING/' . $requestId, $row);

    if (!$saved) {
        mfs_release_hold(
            $uid,
            $totalDebit,
            $walletCurrency,
            $requestId,
            'MFS hold rollback after request save failure',
            'MFS_HOLD_ROLLBACK'
        );

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create MFS request',
            'data' => [],
        ];
    }

    mfs_create_request_status($requestId, $uid, 'PENDING', 'MFS request created');
    mfs_write_user_request_log($uid, $requestId, $row);
    mfs_write_history($uid, $requestId, $row);

    if (function_exists('system_log')) {
        try {
            system_log('MFS_REQUEST_CREATE', $requestId, 'MFS request created', [
                'uid' => $uid,
                'provider' => $provider,
                'service_type' => $serviceType,
                'receiver_number' => $receiverNumber,
                'wallet_currency' => $walletCurrency,
                'total_debit' => $totalDebit,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request created successfully',
        'data' => [
            'request_id' => $requestId,
            'uid' => $uid,
            'status' => 'PENDING',

            'provider' => $provider,
            'provider_name' => mfs_provider_name($provider),
            'service_type' => $serviceType,
            'service_name' => mfs_service_name($serviceType),
            'account_type' => $accountType,

            'receiver_number' => $receiverNumber,
            'number' => $receiverNumber,

            'country_code' => (string)$amounts['country_code'],
            'service_mode' => (string)$amounts['service_mode'],
            'wallet_currency' => $walletCurrency,

            'amount_bdt' => (float)$amounts['amount_bdt'],
            'amount_rm' => (float)$amounts['amount_rm'],
            'fee_bdt' => (float)$amounts['fee_bdt'],
            'fee_rm' => (float)$amounts['fee_rm'],
            'total_debit_bdt' => (float)$amounts['total_debit_bdt'],
            'total_debit_rm' => (float)$amounts['total_debit_rm'],
            'total_debit' => $totalDebit,
            'exchange_rate' => (float)$amounts['exchange_rate'],

            'reference' => $reference,
            'trxid' => '',

            'created_at' => $now,
            'wallet' => [
                'available_balance' => (float)($hold['available_balance'] ?? $hold['after_available'] ?? 0),
                'hold_balance' => (float)($hold['hold_balance'] ?? $hold['after_hold'] ?? 0),
                'currency' => $walletCurrency,
            ],
        ],
    ];
}

/* =========================================================
   Request Lookup / Status Change
========================================================= */

function mfs_allowed_buckets(): array
{
    return ['PENDING', 'PROCESSING', 'DONE'];
}

function mfs_normalize_bucket(string $bucket): string
{
    $bucket = strtoupper(trim($bucket));

    if (in_array($bucket, ['SUCCESS', 'SUCCESSFUL', 'FAILED', 'FAIL'], true)) {
        return 'DONE';
    }

    return $bucket;
}

function mfs_bucket_path(string $bucket): string
{
    $bucket = mfs_normalize_bucket($bucket);

    if (!in_array($bucket, mfs_allowed_buckets(), true)) {
        return '';
    }

    return 'MFS_REQUESTS/' . $bucket;
}

function mfs_find_request(string $requestId): array
{
    $requestId = trim($requestId);

    if ($requestId === '') {
        return [];
    }

    foreach (mfs_allowed_buckets() as $bucket) {
        $row = mfs_fb_get('MFS_REQUESTS/' . $bucket . '/' . $requestId);

        if (is_array($row)) {
            $row['_bucket'] = $bucket;
            $row['request_id'] = (string)($row['request_id'] ?? $requestId);
            return $row;
        }
    }

    return [];
}

function mfs_save_sender_details(string $requestId, string $senderDetails): array
{
    $requestId = trim($requestId);
    $senderDetails = trim($senderDetails);

    if ($requestId === '' || $senderDetails === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'request_id and sender_details are required',
            'data' => [],
        ];
    }

    $row = mfs_find_request($requestId);

    if (!$row) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'MFS request not found',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    $bucket = (string)($row['_bucket'] ?? '');

    if (!in_array($bucket, ['PENDING', 'PROCESSING'], true)) {
        return [
            'ok' => false,
            'code' => 'ALREADY_COMPLETED',
            'message' => 'MFS request already completed',
            'data' => [
                'request_id' => $requestId,
                'status' => (string)($row['status'] ?? ''),
            ],
        ];
    }

    $saved = mfs_fb_patch('MFS_REQUESTS/' . $bucket . '/' . $requestId, [
        'sender_details' => $senderDetails,
        'sender_last_digit' => $senderDetails,
        'sender_last_number_digit' => $senderDetails,
        'last_digit' => $senderDetails,
        'updated_at' => mfs_now(),
    ]);

    if (!$saved) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to save sender details',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Sender details saved',
        'data' => [
            'request_id' => $requestId,
            'sender_details' => $senderDetails,
        ],
    ];
}

function mfs_move_request_bucket(string $requestId, string $fromBucket, string $toBucket, array $row): void
{
    $requestId = trim($requestId);
    $fromBucket = mfs_normalize_bucket($fromBucket);
    $toBucket = mfs_normalize_bucket($toBucket);

    if ($requestId === '' || !in_array($toBucket, mfs_allowed_buckets(), true)) {
        return;
    }

    $saveRow = $row;
    unset($saveRow['_bucket']);

    mfs_fb_put('MFS_REQUESTS/' . $toBucket . '/' . $requestId, $saveRow);

    if ($fromBucket !== '' && $fromBucket !== $toBucket && in_array($fromBucket, mfs_allowed_buckets(), true)) {
        mfs_fb_delete('MFS_REQUESTS/' . $fromBucket . '/' . $requestId);
    }
}

function mfs_mark_processing(string $requestId, string $message = 'Request is processing', array $actor = []): array
{
    $requestId = trim($requestId);

    if ($requestId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'request_id is required',
            'data' => [],
        ];
    }

    $row = mfs_find_request($requestId);

    if (!$row) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'MFS request not found',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    if (($row['_bucket'] ?? '') === 'DONE') {
        return [
            'ok' => false,
            'code' => 'ALREADY_COMPLETED',
            'message' => 'MFS request already completed',
            'data' => [
                'request_id' => $requestId,
                'status' => (string)($row['status'] ?? ''),
            ],
        ];
    }

    $now = mfs_now();
    $uid = (string)($row['uid'] ?? '');

    $row['status'] = 'PROCESSING';
    $row['public_status'] = 'PROCESSING';
    $row['process_status'] = 'PROCESSING';
    $row['message'] = $message;
    $row['updated_at'] = $now;
    $row['processing_at'] = (int)($row['processing_at'] ?? $now);
    $row['processing_by_uid'] = (string)($actor['uid'] ?? '');
    $row['processing_by_role'] = (string)($actor['role'] ?? '');

    mfs_move_request_bucket($requestId, (string)($row['_bucket'] ?? 'PENDING'), 'PROCESSING', $row);
    mfs_update_request_status($requestId, $uid, 'PROCESSING', $message);
    mfs_write_user_request_log($uid, $requestId, $row);
    mfs_write_history($uid, $requestId, $row);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request marked processing',
        'data' => [
            'request_id' => $requestId,
            'status' => 'PROCESSING',
            'updated_at' => $now,
        ],
    ];
}

function mfs_mark_success(string $requestId, string $message = 'Transaction successful', string $trxid = '', array $actor = []): array
{
    $requestId = trim($requestId);
    $trxid = trim($trxid);

    if ($requestId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'request_id is required',
            'data' => [],
        ];
    }

    $row = mfs_find_request($requestId);

    if (!$row) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'MFS request not found',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    $currentStatus = strtoupper(trim((string)($row['status'] ?? '')));

    if (($row['_bucket'] ?? '') === 'DONE' && in_array($currentStatus, ['SUCCESSFUL', 'FAILED'], true)) {
        return [
            'ok' => false,
            'code' => 'ALREADY_COMPLETED',
            'message' => 'MFS request already completed',
            'data' => [
                'request_id' => $requestId,
                'status' => $currentStatus,
            ],
        ];
    }

    $uid = (string)($row['uid'] ?? '');
    $heldAmount = mfs_round_money((float)($row['held_amount'] ?? $row['wallet_hold_amount'] ?? $row['total_debit'] ?? 0));
    $walletCurrency = mfs_normalize_currency((string)($row['wallet_currency'] ?? ''));

    if ($uid === '' || $heldAmount <= 0 || $walletCurrency === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'message' => 'Invalid MFS request settlement data',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    $settled = strtoupper(trim((string)($row['hold_settlement_status'] ?? 'PENDING'))) === 'SUCCESS';

    if (!$settled) {
        $settle = mfs_debit_hold_success(
            $uid,
            $heldAmount,
            $walletCurrency,
            $requestId,
            'MFS request successful'
        );

        if (empty($settle['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($settle['code'] ?? 'SERVER_ERROR'),
                'message' => (string)($settle['message'] ?? 'Failed to settle wallet hold'),
                'data' => (array)($settle['data'] ?? []),
            ];
        }
    }

    $now = mfs_now();

    $row['status'] = 'SUCCESSFUL';
    $row['public_status'] = 'SUCCESSFUL';
    $row['process_status'] = 'SUCCESSFUL';
    $row['final_message'] = $message;
    $row['message'] = $message;
    $row['trxid'] = $trxid;
    $row['hold_settlement_status'] = 'SUCCESS';
    $row['hold_settled_at'] = (int)($row['hold_settled_at'] ?? 0) ?: $now;
    $row['completed_at'] = $now;
    $row['updated_at'] = $now;
    $row['completed_by_uid'] = (string)($actor['uid'] ?? '');
    $row['completed_by_role'] = (string)($actor['role'] ?? '');

    mfs_move_request_bucket($requestId, (string)($row['_bucket'] ?? 'PENDING'), 'DONE', $row);
    mfs_update_request_status($requestId, $uid, 'SUCCESSFUL', $message, $now);
    mfs_write_user_request_log($uid, $requestId, $row);
    mfs_write_history($uid, $requestId, $row);

    if (function_exists('system_log')) {
        try {
            system_log('MFS_REQUEST_SUCCESS', $requestId, 'MFS request successful', [
                'uid' => $uid,
                'trxid' => $trxid,
                'completed_by_uid' => (string)($actor['uid'] ?? ''),
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request marked successful',
        'data' => [
            'request_id' => $requestId,
            'status' => 'SUCCESSFUL',
            'trxid' => $trxid,
            'completed_at' => $now,
        ],
    ];
}

function mfs_mark_failed(string $requestId, string $message = 'Transaction failed', array $actor = []): array
{
    $requestId = trim($requestId);

    if ($requestId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'request_id is required',
            'data' => [],
        ];
    }

    $row = mfs_find_request($requestId);

    if (!$row) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'MFS request not found',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    $currentStatus = strtoupper(trim((string)($row['status'] ?? '')));

    if (($row['_bucket'] ?? '') === 'DONE' && in_array($currentStatus, ['SUCCESSFUL', 'FAILED'], true)) {
        return [
            'ok' => false,
            'code' => 'ALREADY_COMPLETED',
            'message' => 'MFS request already completed',
            'data' => [
                'request_id' => $requestId,
                'status' => $currentStatus,
            ],
        ];
    }

    $uid = (string)($row['uid'] ?? '');
    $heldAmount = mfs_round_money((float)($row['held_amount'] ?? $row['wallet_hold_amount'] ?? $row['total_debit'] ?? 0));
    $walletCurrency = mfs_normalize_currency((string)($row['wallet_currency'] ?? ''));

    if ($uid === '' || $heldAmount <= 0 || $walletCurrency === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'message' => 'Invalid MFS request settlement data',
            'data' => [
                'request_id' => $requestId,
            ],
        ];
    }

    $settled = strtoupper(trim((string)($row['hold_settlement_status'] ?? 'PENDING'))) === 'FAILED_RELEASED';

    if (!$settled) {
        $release = mfs_release_hold(
            $uid,
            $heldAmount,
            $walletCurrency,
            $requestId,
            'MFS request failed - hold released',
            'MFS_FAILED_RELEASE'
        );

        if (empty($release['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($release['code'] ?? 'SERVER_ERROR'),
                'message' => (string)($release['message'] ?? 'Failed to release wallet hold'),
                'data' => (array)($release['data'] ?? []),
            ];
        }
    }

    $now = mfs_now();

    $row['status'] = 'FAILED';
    $row['public_status'] = 'FAILED';
    $row['process_status'] = 'FAILED';
    $row['final_message'] = $message;
    $row['message'] = $message;
    $row['hold_settlement_status'] = 'FAILED_RELEASED';
    $row['hold_settled_at'] = (int)($row['hold_settled_at'] ?? 0) ?: $now;
    $row['completed_at'] = $now;
    $row['updated_at'] = $now;
    $row['completed_by_uid'] = (string)($actor['uid'] ?? '');
    $row['completed_by_role'] = (string)($actor['role'] ?? '');

    mfs_move_request_bucket($requestId, (string)($row['_bucket'] ?? 'PENDING'), 'DONE', $row);
    mfs_update_request_status($requestId, $uid, 'FAILED', $message, $now);
    mfs_write_user_request_log($uid, $requestId, $row);
    mfs_write_history($uid, $requestId, $row);

    if (function_exists('system_log')) {
        try {
            system_log('MFS_REQUEST_FAILED', $requestId, 'MFS request failed', [
                'uid' => $uid,
                'completed_by_uid' => (string)($actor['uid'] ?? ''),
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request marked failed',
        'data' => [
            'request_id' => $requestId,
            'status' => 'FAILED',
            'completed_at' => $now,
        ],
    ];
}

/* =========================================================
   Admin List / Filter / Pagination Helpers
========================================================= */

function mfs_read_bucket(string $bucket): array
{
    $bucket = mfs_normalize_bucket($bucket);
    $path = mfs_bucket_path($bucket);

    if ($path === '') {
        return [];
    }

    $rows = mfs_fb_get($path);

    if (!is_array($rows)) {
        return [];
    }

    $items = [];

    foreach ($rows as $requestId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['request_id'] = (string)($row['request_id'] ?? $requestId);
        $row['_bucket'] = $bucket;
        $row['request_type'] = 'MFS';

        if (empty($row['provider_name']) && !empty($row['provider'])) {
            $row['provider_name'] = mfs_provider_name((string)$row['provider']);
        }

        if (empty($row['service_name']) && !empty($row['service_type'])) {
            $row['service_name'] = mfs_service_name((string)$row['service_type']);
        }

        if (empty($row['number']) && !empty($row['receiver_number'])) {
            $row['number'] = (string)$row['receiver_number'];
        }

        $items[] = $row;
    }

    usort($items, static function (array $a, array $b): int {
        $aTime = (int)(
            ($a['updated_at'] ?? 0)
            ?: ($a['completed_at'] ?? 0)
            ?: ($a['created_at'] ?? 0)
        );

        $bTime = (int)(
            ($b['updated_at'] ?? 0)
            ?: ($b['completed_at'] ?? 0)
            ?: ($b['created_at'] ?? 0)
        );

        return $bTime <=> $aTime;
    });

    return array_values($items);
}

function mfs_apply_filters(array $items, array $filters): array
{
    $requestId = trim((string)($filters['request_id'] ?? ''));
    $uid = trim((string)($filters['uid'] ?? ''));
    $provider = mfs_normalize_provider((string)($filters['provider'] ?? $filters['service'] ?? ''));
    $serviceType = mfs_normalize_service_type((string)($filters['service_type'] ?? ''));
    $country = mfs_normalize_country_code((string)($filters['country'] ?? $filters['country_code'] ?? ''));
    $number = mfs_clean_mobile_number((string)($filters['number'] ?? $filters['receiver_number'] ?? ''));
    $status = strtoupper(trim((string)($filters['status'] ?? '')));

    return array_values(array_filter($items, static function (array $row) use ($requestId, $uid, $provider, $serviceType, $country, $number, $status): bool {
        if ($requestId !== '' && (string)($row['request_id'] ?? '') !== $requestId) {
            return false;
        }

        if ($uid !== '' && (string)($row['uid'] ?? '') !== $uid) {
            return false;
        }

        if ($provider !== '') {
            $rowProvider = mfs_normalize_provider((string)($row['provider'] ?? $row['service'] ?? ''));

            if ($rowProvider !== $provider) {
                return false;
            }
        }

        if ($serviceType !== '') {
            $rowServiceType = mfs_normalize_service_type((string)($row['service_type'] ?? ''));

            if ($rowServiceType !== $serviceType) {
                return false;
            }
        }

        if ($country !== '') {
            $rowCountry = mfs_normalize_country_code((string)($row['country_code'] ?? $row['country'] ?? ''));

            if ($rowCountry !== $country) {
                return false;
            }
        }

        if ($number !== '') {
            $rowNumber = mfs_clean_mobile_number((string)($row['receiver_number'] ?? $row['number'] ?? ''));

            if ($rowNumber !== $number) {
                return false;
            }
        }

        if ($status !== '') {
            $rowStatus = strtoupper(trim((string)($row['status'] ?? '')));

            if ($rowStatus !== $status) {
                return false;
            }
        }

        return true;
    }));
}

function mfs_paginate(array $items, int $page, int $limit): array
{
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));

    $total = count($items);
    $offset = ($page - 1) * $limit;

    return [
        'items' => array_values(array_slice($items, $offset, $limit)),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ],
    ];
}
