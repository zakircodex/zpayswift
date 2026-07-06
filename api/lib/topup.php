<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/subadmin_api.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/topup_config.php';

function topup_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function topup_calculation_version(): string
{
    return 'TOPUP_DESTINATION_CURRENCY_V3';
}

function topup_destination_currency(string $countryCode): string
{
    return topup_country_code($countryCode) === 'MY' ? 'MYR' : 'BDT';
}

function topup_legacy_financials(float $amount, array $user = [], array $wallet = [], string $topupCountryCode = 'BD'): array
{
    $amount = round(max(0, $amount), 2);
    $currency = function_exists('wallet_account_currency') ? wallet_account_currency($user, $wallet) : 'BDT';
    $currency = in_array($currency, ['MYR', 'BDT'], true) ? $currency : 'BDT';
    $topupCountryCode = topup_country_code($topupCountryCode);
    $topupCurrency = topup_destination_currency($topupCountryCode);
    $amountBdt = $topupCurrency === 'BDT' ? $amount : 0.0;
    $amountMyr = $topupCurrency === 'MYR' ? $amount : 0.0;

    return [
        'ok' => false,
        'code' => 'CALCULATION_UNAVAILABLE',
        'message' => 'Top-up calculation is unavailable.',
        'role' => strtoupper(trim((string)($user['role'] ?? 'USER'))) ?: 'USER',
        'account_country' => function_exists('wallet_account_country_code') ? wallet_account_country_code($user, $wallet) : '',
        'wallet_currency' => $currency,
        'wallet_debit_currency' => $currency,
        'display_currency' => $currency,
        'topup_country_code' => $topupCountryCode,
        'topup_currency' => $topupCurrency,
        'topup_amount' => $amount,
        'amount_bdt' => $amountBdt,
        'topup_amount_bdt' => $amountBdt,
        'amount_myr' => $amountMyr,
        'topup_amount_myr' => $amountMyr,
        'commission_per_1000' => 0.0,
        'commission_bdt' => 0.0,
        'commission_amount' => 0.0,
        'commission_credit' => 0.0,
        'commission_applicable' => false,
        'commission_type' => 'NONE',
        'commission_value' => 0.0,
        'commission_value_snapshot' => 0.0,
        'wallet_debit_bdt' => $amount,
        'wallet_debit_amount' => $amount,
        'wallet_debit' => $amount,
        'rate_applicable' => false,
        'rate_snapshot' => null,
        'rate_used' => 0.0,
        'converted_amount' => 0.0,
        'fee_amount' => 0.0,
        'gross_amount' => $amount,
        'total_debit_bdt' => $amount,
        'total_debit' => $amount,
        'charged_amount' => $amount,
        'balance_before' => 0.0,
        'balance_after' => 0.0,
        'calculation_version' => topup_calculation_version(),
    ];
}

function topup_account_context(
    string $uid,
    array $user = [],
    array $wallet = [],
    array $roleSettings = []
): array {
    $uid = trim($uid);

    if (!$user && $uid !== '') {
        $loadedUser = fb_get('USERS/' . $uid);
        $user = is_array($loadedUser) ? $loadedUser : [];
    }

    if (!$wallet && $uid !== '') {
        $loadedWallet = fb_get('USER_WALLETS/' . $uid);
        $wallet = is_array($loadedWallet) ? $loadedWallet : [];
    }

    $accountCountry = function_exists('wallet_account_country_code')
        ? wallet_account_country_code($user, $wallet)
        : '';
    $walletCurrency = function_exists('wallet_account_currency')
        ? wallet_account_currency($user, $wallet)
        : '';

    $accountCountry = strtoupper(trim((string)$accountCountry));
    $walletCurrency = strtoupper(trim((string)$walletCurrency));
    $expectedCurrency = $accountCountry === 'MY'
        ? 'MYR'
        : ($accountCountry === 'BD' ? 'BDT' : '');

    if ($expectedCurrency === '' || $walletCurrency !== $expectedCurrency) {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_CURRENCY_INVALID',
            'message' => 'Your account currency could not be verified.',
            'data' => [
                'account_country' => $accountCountry,
                'wallet_currency' => $walletCurrency,
            ],
        ];
    }

    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    if (!in_array($role, ['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'], true)) {
        $role = 'USER';
    }

    if (!$roleSettings && $uid !== '') {
        $loadedSettings = fb_get('USER_ROLE_SETTINGS/' . $uid);
        $roleSettings = is_array($loadedSettings) ? $loadedSettings : [];
    }
    if (function_exists('role_settings_with_defaults')) {
        $roleSettings = role_settings_with_defaults($roleSettings, $role);
    }

    return [
        'ok' => true,
        'uid' => $uid,
        'user' => $user,
        'wallet' => $wallet,
        'role' => $role,
        'role_settings' => $roleSettings,
        'account_country' => $accountCountry,
        'wallet_currency' => $walletCurrency,
        'balance_before' => round((float)($wallet['available_balance'] ?? 0), 2),
    ];
}

function topup_user_topup_access_validation(string $uid, array $user, string $countryCode): array
{
    $countryCode = topup_country_code($countryCode);
    $settings = [];
    if ($uid !== '') {
        $loadedSettings = fb_get('USER_ROLE_SETTINGS/' . $uid);
        $settings = is_array($loadedSettings) ? $loadedSettings : [];
    }

    if (!topup_bool($settings['topup_enabled'] ?? $user['topup_enabled'] ?? true, true)) {
        return [
            'ok' => false,
            'code' => 'TOPUP_ACCOUNT_DISABLED',
            'message' => 'Mobile top-up is disabled for this account.',
        ];
    }

    $fields = $countryCode === 'MY'
        ? ['my_topup_enabled', 'topup_my_enabled']
        : ['bd_topup_enabled', 'topup_bd_enabled'];

    foreach ($fields as $field) {
        if (array_key_exists($field, $settings) || array_key_exists($field, $user)) {
            if (!topup_bool($settings[$field] ?? $user[$field] ?? true, true)) {
                return [
                    'ok' => false,
                    'code' => 'TOPUP_ACCOUNT_COUNTRY_DISABLED',
                    'message' => 'Top-up is disabled for this country on this account.',
                ];
            }
        }
    }

    return ['ok' => true];
}

function topup_configured_myr_to_bdt_rate(): float
{
    static $rate = null;
    if ($rate !== null) {
        return $rate;
    }

    $app = function_exists('topup_app_config_row') ? topup_app_config_row() : [];
    if (is_array($app)) {
        $rate = topup_rate_from_row($app, [
            ['MYR_TO_BDT_RATE'],
            ['RINGGIT_RATE'],
            ['myr_to_bdt_rate'],
            ['ringgit_rate'],
        ]);
        if ($rate > 0) {
            return $rate;
        }
    }

    foreach (['MFS_SETTINGS', 'MFS_CONFIG'] as $node) {
        $row = fb_get($node);
        if (!is_array($row)) {
            continue;
        }

        $paths = $node === 'MFS_SETTINGS'
            ? [
                ['rate_myr_bdt'],
                ['myr_to_bdt_rate'],
                ['rates', 'myr_to_bdt'],
                ['rates', 'MYR_TO_BDT'],
            ]
            : [
                ['myr_to_bdt_rate'],
                ['rates', 'myr_to_bdt'],
                ['RATE', 'MYR_TO_BDT'],
                ['RATES', 'MYR_TO_BDT'],
            ];

        $rate = topup_rate_from_row($row, $paths);
        if ($rate > 0) {
            return $rate;
        }
    }

    if (defined('MYR_TO_BDT_RATE') && (float)MYR_TO_BDT_RATE > 0) {
        $rate = round((float)MYR_TO_BDT_RATE, 2);
        return $rate;
    }

    $rate = 0.0;
    return $rate;
}

function topup_commission_from_settings(float $amount, string $role, array $roleSettings): array
{
    $defaultRate = function_exists('role_default_commission_per_1000')
        ? role_default_commission_per_1000($role)
        : (in_array($role, ['RETAILER', 'SUBADMIN'], true) ? 18.0 : 0.0);

    if (array_key_exists('commission_per_1000', $roleSettings) && !is_numeric($roleSettings['commission_per_1000'])) {
        return [
            'ok' => false,
            'code' => 'COMMISSION_CONFIG_INVALID',
            'message' => 'Top-up commission could not be calculated. Please contact support.',
        ];
    }

    $rate = array_key_exists('commission_per_1000', $roleSettings)
        ? (float)$roleSettings['commission_per_1000']
        : $defaultRate;
    $rate = round($rate, 2);

    if ($rate < 0) {
        return [
            'ok' => false,
            'code' => 'COMMISSION_CONFIG_INVALID',
            'message' => 'Top-up commission could not be calculated. Please contact support.',
        ];
    }

    $commission = round(($amount * $rate) / 1000, 2);
    if ($commission < 0 || $commission > $amount) {
        return [
            'ok' => false,
            'code' => 'COMMISSION_CONFIG_INVALID',
            'message' => 'Top-up commission could not be calculated. Please contact support.',
        ];
    }

    return [
        'ok' => true,
        'commission_type' => $rate > 0 ? 'PER_1000_DISCOUNT' : 'NONE',
        'commission_value' => $rate,
        'commission_amount' => round(max(0, min($amount, $commission)), 2),
    ];
}

function topup_calculate_payment_context(
    string $uid,
    float $amount,
    array $user = [],
    array $wallet = [],
    array $roleSettings = [],
    string $topupCountryCode = 'BD'
): array {
    $amount = round(max(0, $amount), 2);
    $topupCountryCode = topup_country_code($topupCountryCode);
    $topupCurrency = topup_destination_currency($topupCountryCode);
    $base = topup_legacy_financials($amount, $user, $wallet, $topupCountryCode);

    if ($amount <= 0) {
        return array_merge($base, [
            'code' => 'TOPUP_AMOUNT_REQUIRED',
            'message' => 'Please enter top-up amount.',
        ]);
    }

    $context = topup_account_context($uid, $user, $wallet, $roleSettings);
    if (empty($context['ok'])) {
        return array_merge($base, $context);
    }

    $role = (string)$context['role'];
    $walletCurrency = (string)$context['wallet_currency'];
    $accountCountry = (string)$context['account_country'];
    $balanceBefore = round((float)$context['balance_before'], 2);

    $commissionApplicable = false;
    $commissionType = 'NONE';
    $commissionValue = 0.0;
    $commissionAmount = 0.0;
    $commissionCredit = 0.0;
    $feeAmount = 0.0;
    $rateApplicable = false;
    $rateSnapshot = null;
    $convertedAmount = 0.0;
    $walletDebit = $amount;
    $walletDebitBdt = $topupCurrency === 'BDT' ? $amount : 0.0;
    $walletDebitMyr = $topupCurrency === 'MYR' ? $amount : 0.0;
    $topupAmountBdt = $topupCurrency === 'BDT' ? $amount : 0.0;
    $topupAmountMyr = $topupCurrency === 'MYR' ? $amount : 0.0;

    if ($topupCountryCode === 'MY') {
        if ($accountCountry !== 'MY' || $walletCurrency !== 'MYR') {
            return array_merge($base, [
                'ok' => false,
                'code' => 'ACCOUNT_CURRENCY_INVALID',
                'message' => 'Your account currency could not be verified.',
                'account_country' => $accountCountry,
                'wallet_currency' => $walletCurrency,
                'topup_country_code' => $topupCountryCode,
                'topup_currency' => $topupCurrency,
            ]);
        }

        $rateApplicable = false;
        $rateSnapshot = null;
        $convertedAmount = 0.0;
        $walletDebit = $amount;
        $walletDebitBdt = 0.0;
        $walletDebitMyr = $amount;
    } elseif ($accountCountry === 'MY' && $walletCurrency === 'MYR') {
        $rate = topup_configured_myr_to_bdt_rate();
        if ($rate <= 0) {
            return array_merge($base, [
                'ok' => false,
                'code' => 'RATE_UNAVAILABLE',
                'message' => 'Current exchange rate is unavailable. Please try again later.',
                'account_country' => $accountCountry,
                'wallet_currency' => $walletCurrency,
            ]);
        }

        if ($rate < 1 || $rate > 1000) {
            return array_merge($base, [
                'ok' => false,
                'code' => 'RATE_INVALID',
                'message' => 'The current exchange rate is invalid.',
                'account_country' => $accountCountry,
                'wallet_currency' => $walletCurrency,
                'rate_snapshot' => $rate,
            ]);
        }

        $rateApplicable = true;
        $rateSnapshot = round($rate, 2);
        $convertedAmount = round($amount / $rateSnapshot, 2);
        $walletDebit = $convertedAmount;
        $walletDebitBdt = $amount;
        $walletDebitMyr = $convertedAmount;
    } elseif ($accountCountry === 'BD' && $walletCurrency === 'BDT') {
        $commission = topup_commission_from_settings($amount, $role, (array)$context['role_settings']);
        if (empty($commission['ok'])) {
            return array_merge($base, $commission, [
                'account_country' => $accountCountry,
                'wallet_currency' => $walletCurrency,
            ]);
        }

        $commissionType = (string)$commission['commission_type'];
        $commissionValue = round((float)$commission['commission_value'], 2);
        $commissionAmount = round((float)$commission['commission_amount'], 2);
        $commissionApplicable = $commissionAmount > 0;
        $walletDebitBdt = round(max(0, $amount - $commissionAmount), 2);
        $walletDebit = $walletDebitBdt;
    } else {
        return array_merge($base, [
            'ok' => false,
            'code' => 'ACCOUNT_CURRENCY_INVALID',
            'message' => 'Your account currency could not be verified.',
            'account_country' => $accountCountry,
            'wallet_currency' => $walletCurrency,
            'topup_country_code' => $topupCountryCode,
            'topup_currency' => $topupCurrency,
        ]);
    }

    $balanceAfter = round($balanceBefore - $walletDebit, 2);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Top-up payment calculated.',
        'role' => $role,
        'account_country' => $accountCountry,
        'wallet_currency' => $walletCurrency,
        'wallet_debit_currency' => $walletCurrency,
        'display_currency' => $walletCurrency,
        'topup_country_code' => $topupCountryCode,
        'topup_currency' => $topupCurrency,
        'topup_amount' => $amount,
        'amount_bdt' => $topupAmountBdt,
        'topup_amount_bdt' => $topupAmountBdt,
        'amount_myr' => $topupAmountMyr,
        'topup_amount_myr' => $topupAmountMyr,
        'rate_applicable' => $rateApplicable,
        'rate_snapshot' => $rateSnapshot,
        'rate_used' => $rateSnapshot !== null ? $rateSnapshot : 0.0,
        'converted_amount' => $convertedAmount,
        'commission_applicable' => $commissionApplicable,
        'commission_type' => $commissionType,
        'commission_value' => $commissionValue,
        'commission_value_snapshot' => $commissionValue,
        'commission_per_1000' => $commissionValue,
        'commission_amount' => $commissionAmount,
        'commission_bdt' => $commissionAmount,
        'commission_credit' => $commissionCredit,
        'fee_amount' => $feeAmount,
        'gross_amount' => $walletDebit,
        'wallet_debit' => $walletDebit,
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_bdt' => $walletDebitBdt,
        'wallet_debit_myr' => $walletDebitMyr,
        'total_debit_bdt' => $walletDebitBdt,
        'total_debit' => $walletDebit,
        'charged_amount' => $walletDebit,
        'balance_before' => $balanceBefore,
        'balance_after' => $balanceAfter,
        'calculation_version' => topup_calculation_version(),
    ];
}

function topup_commission_breakdown(
    string $uid,
    float $amount,
    array $user = [],
    array $roleSettings = [],
    array $wallet = []
): array {
    return topup_calculate_payment_context($uid, $amount, $user, $wallet, $roleSettings);
}

function topup_fast_myr_to_bdt_rate(): float
{
    return topup_configured_myr_to_bdt_rate();
}

function topup_rate_from_row(array $row, array $paths): float
{
    foreach ($paths as $path) {
        $value = $row;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                $value = null;
                break;
            }
            $value = $value[$key];
        }

        if (is_numeric($value) && (float)$value > 0) {
            return round((float)$value, 2);
        }

        if (is_array($value)) {
            foreach (['rate', 'value', 'amount', 'bdt'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key]) && (float)$value[$key] > 0) {
                    return round((float)$value[$key], 2);
                }
            }
        }
    }

    return 0.0;
}

function topup_telegram_bot_token(): string
{
    return defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
}

function topup_telegram_chat_id(): string
{
    return defined('TELEGRAM_CHAT_ID') ? trim((string)TELEGRAM_CHAT_ID) : '';
}

function topup_telegram_action_key(): string
{
    if (defined('TELEGRAM_TOPUP_ACTION_KEY') && trim((string)TELEGRAM_TOPUP_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_TOPUP_ACTION_KEY);
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function topup_telegram_enabled(): bool
{
    return topup_telegram_bot_token() !== ''
        && topup_telegram_chat_id() !== ''
        && topup_telegram_action_key() !== '';
}

function topup_telegram_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function topup_telegram_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function topup_telegram_amount_line(array $row): string
{
    $currency = strtoupper(trim((string)($row['topup_currency'] ?? $row['currency'] ?? '')));
    if ($currency === '') {
        $currency = topup_destination_currency((string)($row['country_code'] ?? 'BD'));
    }

    if ($currency === 'MYR') {
        $amount = (float)($row['topup_amount_myr'] ?? $row['amount_myr'] ?? $row['topup_amount'] ?? $row['amount'] ?? 0);
        return 'Amount MYR: RM ' . topup_telegram_money($amount);
    }

    $amount = (float)($row['topup_amount_bdt'] ?? $row['amount_bdt'] ?? $row['topup_amount'] ?? $row['amount'] ?? 0);
    return 'Amount BDT: BDT ' . topup_telegram_money($amount);
}

function topup_telegram_time($ts = null): string
{
    $time = (int)($ts ?: topup_now());
    return date('Y-m-d H:i:s', $time);
}

function topup_telegram_action_details(string $value): array
{
    $action = strtolower(trim($value));
    $map = [
        'p' => 'PROCESSING',
        's' => 'SUCCESS',
        'f' => 'FAILED',
    ];

    if (!isset($map[$action])) {
        return [
            'ok' => false,
            'code' => '',
            'status' => '',
        ];
    }

    return [
        'ok' => true,
        'code' => $action,
        'status' => $map[$action],
    ];
}

function topup_telegram_signature(string $requestId, string $actionCode): string
{
    $details = topup_telegram_action_details($actionCode);
    $code = (string)($details['code'] ?? strtolower(trim($actionCode)));

    return substr(hash_hmac('sha256', $code . '|' . trim($requestId), topup_telegram_action_key()), 0, 16);
}

function topup_telegram_callback_data(string $actionCode, string $requestId): string
{
    $requestId = trim($requestId);
    $details = topup_telegram_action_details($actionCode);

    if (!($details['ok'] ?? false) || $requestId === '' || !preg_match('/^[A-Za-z0-9_-]{6,39}$/', $requestId)) {
        return '';
    }

    $code = (string)$details['code'];
    $callbackData = 'topup|' . $code . '|' . $requestId . '|' . topup_telegram_signature($requestId, $code);

    return strlen($callbackData) <= 64 ? $callbackData : '';
}

function topup_telegram_parse_callback_data(string $callbackData): array
{
    $parts = explode('|', trim($callbackData));

    if (count($parts) !== 4 || strtolower((string)$parts[0]) !== 'topup') {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid callback data',
            'data' => ['reason' => 'format'],
        ];
    }

    $details = topup_telegram_action_details((string)$parts[1]);
    $requestId = trim((string)$parts[2]);
    $signature = trim((string)$parts[3]);

    if (!($details['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid callback data',
            'data' => ['reason' => 'action'],
        ];
    }

    if ($requestId === '' || !preg_match('/^[A-Za-z0-9_-]{6,39}$/', $requestId)) {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid callback data',
            'data' => ['reason' => 'request_id'],
        ];
    }

    if ($signature === '' || !hash_equals(topup_telegram_signature($requestId, (string)$details['code']), $signature)) {
        return [
            'ok' => false,
            'code' => 'INVALID_SIGNATURE',
            'message' => 'Invalid callback signature',
            'data' => [
                'reason' => 'signature',
                'request_id' => $requestId,
                'action' => (string)$details['status'],
            ],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Callback verified',
        'data' => [
            'request_id' => $requestId,
            'action' => (string)$details['status'],
            'action_code' => (string)$details['code'],
        ],
    ];
}

function topup_telegram_keyboard_active(string $requestId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '🔄 Processing', 'callback_data' => topup_telegram_callback_data('p', $requestId)],
            ],
            [
                ['text' => '✅ Success', 'callback_data' => topup_telegram_callback_data('s', $requestId)],
                ['text' => '❌ Failed', 'callback_data' => topup_telegram_callback_data('f', $requestId)],
            ],
        ],
    ];
}

function topup_telegram_keyboard_after_processing(string $requestId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '✅ Success', 'callback_data' => topup_telegram_callback_data('s', $requestId)],
                ['text' => '❌ Failed', 'callback_data' => topup_telegram_callback_data('f', $requestId)],
            ],
        ],
    ];
}

function topup_telegram_effective_role(array $row): string
{
    $role = strtoupper(trim((string)($row['user_role'] ?? $row['role'] ?? '')));
    if ($role !== '') {
        return $role;
    }

    $uid = trim((string)($row['uid'] ?? ''));
    if ($uid !== '') {
        $user = fb_get('USERS/' . $uid);
        if (is_array($user)) {
            $role = strtoupper(trim((string)($user['role'] ?? '')));
            if ($role !== '') {
                return $role;
            }
        }
    }

    return '-';
}

function topup_telegram_build_request_message(array $row): string
{
    $requestId = (string)($row['request_id'] ?? '');
    $uid = (string)($row['uid'] ?? '');
    $userPhone = (string)($row['user_phone'] ?? '');
    $role = topup_telegram_effective_role($row);
    $topupNumber = (string)($row['topup_number'] ?? '');
    $operator = (string)($row['operator'] ?? '');
    $amountLine = topup_telegram_amount_line($row);
    $createdAt = (int)($row['created_at'] ?? topup_now());

    return "🔔 <b>New Topup Request</b>\n\n"
        . "Request ID: <code>" . topup_telegram_h($requestId) . "</code>\n"
        . "UID: <code>" . topup_telegram_h($uid) . "</code>\n"
        . "User Phone: <code>" . topup_telegram_h($userPhone) . "</code>\n"
        . "User Role: <b>" . topup_telegram_h($role) . "</b>\n\n"
        . "Topup Number: <code>" . topup_telegram_h($topupNumber) . "</code>\n"
        . "Operator: <b>" . topup_telegram_h($operator) . "</b>\n"
        . topup_telegram_h($amountLine) . "\n"
        . "Status: <b>PENDING</b>\n"
        . "Created: " . topup_telegram_h(topup_telegram_time($createdAt));
}

function topup_telegram_build_status_message(array $row, string $status, string $message): string
{
    $status = strtoupper(trim($status));
    $icon = $status === 'SUCCESS' ? '✅' : ($status === 'FAILED' ? '❌' : '🔄');
    $requestId = (string)($row['request_id'] ?? '');
    $uid = (string)($row['uid'] ?? '');
    $userPhone = (string)($row['user_phone'] ?? '');
    $topupNumber = (string)($row['topup_number'] ?? '');
    $operator = (string)($row['operator'] ?? '');
    $amountLine = topup_telegram_amount_line($row);

    return $icon . " <b>Topup " . topup_telegram_h($status) . "</b>\n\n"
        . "Request ID: <code>" . topup_telegram_h($requestId) . "</code>\n"
        . "UID: <code>" . topup_telegram_h($uid) . "</code>\n"
        . "User Phone: <code>" . topup_telegram_h($userPhone) . "</code>\n\n"
        . "Topup Number: <code>" . topup_telegram_h($topupNumber) . "</code>\n"
        . "Operator: <b>" . topup_telegram_h($operator) . "</b>\n"
        . topup_telegram_h($amountLine) . "\n\n"
        . "Message: " . topup_telegram_h($message !== '' ? $message : $status) . "\n"
        . "Updated: " . topup_telegram_h(topup_telegram_time());
}

function topup_telegram_api(string $method, array $payload): array
{
    if (!topup_telegram_enabled()) {
        return [
            'ok' => false,
            'code' => 'CONFIG_ERROR',
            'message' => 'Telegram topup config missing',
            'data' => [],
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'code' => 'CURL_MISSING',
            'message' => 'PHP cURL extension is not available',
            'data' => [],
        ];
    }

    $ch = curl_init('https://api.telegram.org/bot' . topup_telegram_bot_token() . '/' . ltrim($method, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    $ok = $http >= 200 && $http < 300 && is_array($json) && !empty($json['ok']);
    $description = is_array($json) ? (string)($json['description'] ?? '') : '';

    return [
        'ok' => $ok,
        'code' => $ok ? 'SUCCESS' : 'TELEGRAM_ERROR',
        'message' => $ok ? 'Telegram request sent' : ($description !== '' ? $description : ($err !== '' ? $err : 'Telegram request failed')),
        'data' => [
            'http' => $http,
            'response' => is_array($json) ? $json : [],
        ],
    ];
}

function topup_telegram_patch_request(string $requestId, array $patch): void
{
    $row = topup_find_request($requestId);
    if (!is_array($row)) {
        return;
    }

    $bucket = (string)($row['_bucket'] ?? '');
    if ($bucket === '') {
        return;
    }

    $patch['updated_at'] = topup_now();
    fb_patch('TOPUP_REQUESTS/' . $bucket . '/' . $requestId, $patch);
}

function topup_notify_telegram_request(array $row): array
{
    $requestId = trim((string)($row['request_id'] ?? ''));
    if ($requestId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Missing request id for Telegram topup notification',
            'data' => [],
        ];
    }

    if (!topup_telegram_enabled()) {
        topup_telegram_patch_request($requestId, [
            'telegram_sent' => false,
            'telegram_error' => 'Telegram topup config missing',
        ]);

        return [
            'ok' => false,
            'code' => 'CONFIG_ERROR',
            'message' => 'Telegram topup config missing',
            'data' => [],
        ];
    }

    $res = topup_telegram_api('sendMessage', [
        'chat_id' => topup_telegram_chat_id(),
        'text' => topup_telegram_build_request_message($row),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => topup_telegram_keyboard_active($requestId),
    ]);

    if (!($res['ok'] ?? false)) {
        topup_telegram_patch_request($requestId, [
            'telegram_sent' => false,
            'telegram_error' => substr((string)($res['message'] ?? 'Telegram send failed'), 0, 400),
        ]);
        return $res;
    }

    $result = (array)($res['data']['response']['result'] ?? []);
    topup_telegram_patch_request($requestId, [
        'telegram_sent' => true,
        'telegram_error' => '',
        'telegram_message_id' => (int)($result['message_id'] ?? 0),
        'telegram_chat_id' => (string)($result['chat']['id'] ?? topup_telegram_chat_id()),
        'telegram_sent_at' => topup_now(),
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Topup Telegram notification sent',
        'data' => [
            'message_id' => (int)($result['message_id'] ?? 0),
            'chat_id' => (string)($result['chat']['id'] ?? topup_telegram_chat_id()),
        ],
    ];
}

function create_request_status(
    string $requestId,
    string $type,
    string $uid,
    string $status,
    string $message
): bool {
    return fb_put('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'type' => $type,
        'uid' => $uid,
        'status' => $status,
        'message' => $message,
        'updated_at' => now_ts(),
    ]);
}

function update_request_status(
    string $requestId,
    string $status,
    string $message
): bool {
    return fb_patch('REQUEST_STATUS/' . $requestId, [
        'status' => $status,
        'message' => $message,
        'updated_at' => now_ts(),
    ]);
}

function topup_pending_request_row(
    string $requestId,
    string $uid,
    string $userPhone,
    string $topupNumber,
    string $operator,
    float $amount,
    array $financials = [],
    array $extra = []
): array {
    $financials = array_replace([
        'amount_bdt' => $amount,
        'topup_amount_bdt' => $amount,
        'amount_myr' => 0,
        'topup_amount_myr' => 0,
        'topup_amount' => $amount,
        'topup_currency' => 'BDT',
        'account_country' => '',
        'commission_per_1000' => 0,
        'commission_bdt' => 0,
        'wallet_debit_bdt' => $amount,
        'wallet_debit_amount' => $amount,
        'wallet_debit_currency' => 'BDT',
        'wallet_currency' => 'BDT',
        'display_currency' => 'BDT',
        'rate_applicable' => false,
        'rate_snapshot' => null,
        'rate_used' => 0,
        'commission_applicable' => false,
        'commission_type' => 'NONE',
        'commission_value' => 0,
        'commission_value_snapshot' => 0,
        'commission_amount' => 0,
        'commission_credit' => 0,
        'fee_amount' => 0,
        'balance_before' => 0,
        'balance_after' => 0,
        'calculation_version' => topup_calculation_version(),
        'total_debit_bdt' => $amount,
        'total_debit' => $amount,
        'charged_amount' => $amount,
    ], $financials);
    $countryCode = topup_country_code($extra['country_code'] ?? $extra['country'] ?? ($financials['country_code'] ?? 'BD'));
    $executionMode = function_exists('topup_operator_execution_mode')
        ? topup_operator_execution_mode($countryCode, $operator)
        : 'WORKER_USSD';
    $workerClaimable = function_exists('topup_operator_worker_claimable')
        ? topup_operator_worker_claimable($countryCode, $operator)
        : true;

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => $userPhone,
        'topup_number' => $topupNumber,
        'operator' => $operator,
        'country_code' => $countryCode,
        'execution_mode' => $executionMode,
        'worker_claimable' => $workerClaimable,
        'WORKER_CLAIMABLE' => $workerClaimable,
        'manual_telegram_required' => !$workerClaimable,
        'amount' => $amount,
        'topup_amount' => (float)$financials['topup_amount'],
        'topup_currency' => (string)$financials['topup_currency'],
        'TOPUP_CURRENCY' => (string)$financials['topup_currency'],
        'amount_bdt' => (float)$financials['amount_bdt'],
        'topup_amount_bdt' => (float)$financials['topup_amount_bdt'],
        'TOPUP_AMOUNT_BDT' => (float)$financials['topup_amount_bdt'],
        'amount_myr' => (float)$financials['amount_myr'],
        'topup_amount_myr' => (float)$financials['topup_amount_myr'],
        'TOPUP_AMOUNT_MYR' => (float)$financials['topup_amount_myr'],
        'account_country' => (string)$financials['account_country'],
        'ACCOUNT_COUNTRY' => (string)$financials['account_country'],
        'display_currency' => (string)$financials['display_currency'],
        'commission_per_1000' => (float)$financials['commission_per_1000'],
        'commission_bdt' => (float)$financials['commission_bdt'],
        'commission_applicable' => (bool)$financials['commission_applicable'],
        'commission_type' => (string)$financials['commission_type'],
        'commission_value' => (float)$financials['commission_value'],
        'commission_value_snapshot' => (float)$financials['commission_value_snapshot'],
        'commission_amount' => (float)$financials['commission_amount'],
        'COMMISSION_AMOUNT' => (float)$financials['commission_amount'],
        'COMMISSION_TYPE' => (string)$financials['commission_type'],
        'commission_credit' => (float)$financials['commission_credit'],
        'fee_amount' => (float)$financials['fee_amount'],
        'FEE_AMOUNT' => (float)$financials['fee_amount'],
        'wallet_debit_bdt' => (float)$financials['wallet_debit_bdt'],
        'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
        'wallet_debit_amount' => (float)$financials['wallet_debit_amount'],
        'wallet_debit' => (float)$financials['wallet_debit_amount'],
        'WALLET_DEBIT' => (float)$financials['wallet_debit_amount'],
        'wallet_debit_currency' => (string)$financials['wallet_debit_currency'],
        'wallet_currency' => (string)$financials['wallet_currency'],
        'WALLET_CURRENCY' => (string)$financials['wallet_currency'],
        'rate_applicable' => (bool)$financials['rate_applicable'],
        'rate_snapshot' => $financials['rate_snapshot'],
        'RATE_SNAPSHOT' => $financials['rate_snapshot'],
        'rate_used' => (float)$financials['rate_used'],
        'total_debit_bdt' => (float)$financials['total_debit_bdt'],
        'total_debit' => (float)$financials['total_debit'],
        'charged_amount' => (float)$financials['charged_amount'],
        'balance_before' => (float)$financials['balance_before'],
        'balance_after' => (float)$financials['balance_after'],
        'calculation_version' => (string)$financials['calculation_version'],
        'request_pin_verified' => true,
        'wallet_hold_amount' => (float)$financials['wallet_debit_amount'],
        'status' => 'PENDING',
        'assigned_device_id' => '',
        'assigned_slot' => '',
        'created_at' => now_ts(),
        'updated_at' => now_ts(),
    ];

    foreach ($extra as $key => $value) {
        if (is_string($key) && preg_match('/^[A-Za-z0-9_]+$/', $key) === 1) {
            $row[$key] = $value;
        }
    }

    return $row;
}

function create_topup_pending_request(
    string $requestId,
    string $uid,
    string $userPhone,
    string $topupNumber,
    string $operator,
    float $amount,
    array $financials = [],
    array $extra = []
): bool {
    $row = topup_pending_request_row(
        $requestId,
        $uid,
        $userPhone,
        $topupNumber,
        $operator,
        $amount,
        $financials,
        $extra
    );

    return fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $row);
}

function topup_find_request(string $requestId): ?array
{
    foreach (['PENDING', 'CLAIMED', 'PROCESSING', 'DONE'] as $bucket) {
        $row = fb_get('TOPUP_REQUESTS/' . $bucket . '/' . $requestId);

        if (is_array($row)) {
            $row['_bucket'] = $bucket;
            $row['request_id'] = (string)($row['request_id'] ?? $requestId);
            return $row;
        }
    }

    return null;
}

function topup_write_history(array $done): void
{
    $uid = (string)($done['uid'] ?? '');
    $requestId = (string)($done['request_id'] ?? '');

    if ($uid === '' || $requestId === '') {
        return;
    }

    $requestSource = (string)($done['request_source'] ?? $done['source'] ?? '');

    fb_put('TOPUP_HISTORY/' . $uid . '/' . month_key() . '/' . $requestId, [
        'request_id' => $requestId,
        'topup_number' => (string)($done['topup_number'] ?? ''),
        'operator' => (string)($done['operator'] ?? ''),
        'amount' => (float)($done['amount'] ?? 0),
        'topup_amount' => (float)($done['topup_amount'] ?? $done['amount'] ?? 0),
        'topup_currency' => (string)($done['topup_currency'] ?? $done['currency'] ?? 'BDT'),
        'amount_bdt' => (float)($done['amount_bdt'] ?? $done['amount'] ?? 0),
        'topup_amount_bdt' => (float)($done['topup_amount_bdt'] ?? $done['amount_bdt'] ?? $done['amount'] ?? 0),
        'amount_myr' => (float)($done['amount_myr'] ?? 0),
        'topup_amount_myr' => (float)($done['topup_amount_myr'] ?? $done['amount_myr'] ?? 0),
        'account_country' => (string)($done['account_country'] ?? ''),
        'display_currency' => (string)($done['display_currency'] ?? $done['wallet_currency'] ?? $done['wallet_debit_currency'] ?? 'BDT'),
        'commission_per_1000' => (float)($done['commission_per_1000'] ?? 0),
        'commission_bdt' => (float)($done['commission_bdt'] ?? $done['commission_amount'] ?? 0),
        'commission_applicable' => (bool)($done['commission_applicable'] ?? false),
        'commission_type' => (string)($done['commission_type'] ?? 'NONE'),
        'commission_amount' => (float)($done['commission_amount'] ?? $done['commission_bdt'] ?? 0),
        'commission_credit' => (float)($done['commission_credit'] ?? 0),
        'fee_amount' => (float)($done['fee_amount'] ?? 0),
        'wallet_debit_bdt' => (float)($done['wallet_debit_bdt'] ?? $done['wallet_hold_amount'] ?? $done['amount'] ?? 0),
        'wallet_debit_myr' => (float)($done['wallet_debit_myr'] ?? 0),
        'wallet_debit_amount' => (float)($done['wallet_debit_amount'] ?? $done['wallet_hold_amount'] ?? $done['wallet_debit_bdt'] ?? $done['amount'] ?? 0),
        'wallet_debit' => (float)($done['wallet_debit_amount'] ?? $done['wallet_hold_amount'] ?? $done['wallet_debit_bdt'] ?? $done['amount'] ?? 0),
        'wallet_debit_currency' => (string)($done['wallet_debit_currency'] ?? $done['wallet_currency'] ?? 'BDT'),
        'wallet_currency' => (string)($done['wallet_currency'] ?? $done['wallet_debit_currency'] ?? 'BDT'),
        'rate_applicable' => (bool)($done['rate_applicable'] ?? false),
        'rate_snapshot' => $done['rate_snapshot'] ?? $done['rate_used'] ?? null,
        'rate_used' => (float)($done['rate_used'] ?? 0),
        'total_debit_bdt' => (float)($done['total_debit_bdt'] ?? $done['wallet_debit_bdt'] ?? $done['amount'] ?? 0),
        'total_debit' => (float)($done['total_debit'] ?? $done['wallet_hold_amount'] ?? $done['amount'] ?? 0),
        'balance_before' => (float)($done['balance_before'] ?? 0),
        'balance_after' => (float)($done['balance_after'] ?? 0),
        'calculation_version' => (string)($done['calculation_version'] ?? ''),
        'original_wallet_debit' => (float)($done['original_wallet_debit'] ?? 0),
        'original_wallet_debit_currency' => (string)($done['original_wallet_debit_currency'] ?? ''),
        'commission_reversed' => (bool)($done['commission_reversed'] ?? false),
        'refund_reason' => (string)($done['refund_reason'] ?? ''),
        'refunded_at' => (int)($done['refunded_at'] ?? 0),
        'status' => (string)($done['status'] ?? ''),
        'message' => (string)($done['final_message'] ?? ''),
        'created_at' => (int)($done['created_at'] ?? now_ts()),
        'completed_at' => (int)($done['completed_at'] ?? now_ts()),
        'created_by_admin' => (bool)($done['created_by_admin'] ?? false),
        'request_source' => $requestSource,
    ]);
}

function topup_mark_processing(string $requestId, string $message): array
{
    $row = topup_find_request($requestId);

    if (!is_array($row)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Topup request not found',
        ];
    }

    $bucket = (string)($row['_bucket'] ?? '');
    if ($bucket === 'DONE') {
        return [
            'ok' => false,
            'code' => 'ALREADY_DONE',
            'message' => 'Topup request already completed',
        ];
    }

    if ($bucket === 'PROCESSING') {
        return [
            'ok' => true,
            'code' => 'ALREADY_PROCESSING',
            'message' => 'Topup request is already processing',
            'data' => [
                'request' => $row,
            ],
        ];
    }

    if (!in_array($bucket, ['PENDING', 'CLAIMED'], true)) {
        return [
            'ok' => false,
            'code' => 'INVALID_STATE',
            'message' => 'Topup request cannot be moved to processing',
        ];
    }

    $processing = $row;
    unset($processing['_bucket']);

    $now = topup_now();
    $processing['status'] = 'PROCESSING';
    $processing['message'] = $message;
    $processing['final_message'] = $message;
    $processing['manual_processing'] = true;
    $processing['processing_source'] = 'TELEGRAM';
    $processing['processing_at'] = $now;
    $processing['assigned_device_id'] = '';
    $processing['assigned_slot'] = '';
    $processing['updated_at'] = $now;

    if (!fb_put('TOPUP_REQUESTS/PROCESSING/' . $requestId, $processing)) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to processing bucket',
        ];
    }

    if (!fb_delete('TOPUP_REQUESTS/' . $bucket . '/' . $requestId)) {
        fb_delete('TOPUP_REQUESTS/PROCESSING/' . $requestId);
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to clear previous request bucket',
        ];
    }

    update_request_status($requestId, 'PROCESSING', $message);

    if (
        strtoupper(trim((string)($row['source'] ?? ''))) === 'SUBADMIN_API'
        && trim((string)($row['uid'] ?? '')) !== ''
    ) {
        fb_patch('USER_API_REQUESTS/' . (string)$row['uid'] . '/' . $requestId, [
            'status' => 'PROCESSING',
            'message' => $message,
            'updated_at' => $now,
        ]);
    }

    if (function_exists('system_log')) {
        system_log('TOPUP_PROCESSING', $requestId, 'Topup marked as processing manually from Telegram', [
            'uid' => (string)($row['uid'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'previous_bucket' => $bucket,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'TOPUP_PROCESSING',
        'message' => 'Topup request marked as processing',
        'data' => [
            'request' => $processing,
        ],
    ];
}

function topup_mark_success(string $requestId, string $message): array
{
    $row = topup_find_request($requestId);

    if (!is_array($row)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Topup request not found',
        ];
    }

    $bucket = (string)($row['_bucket'] ?? '');
    if ($bucket === 'DONE') {
        return [
            'ok' => false,
            'code' => 'ALREADY_DONE',
            'message' => 'Topup request already completed',
        ];
    }

    $uid = (string)($row['uid'] ?? '');
    $amount = (float)($row['amount'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Settle wallet hold
    |--------------------------------------------------------------------------
    | SUBADMIN_API requests use subapi hold/release logic
    | Normal requests use wallet_hold_amount logic
    */
    if (function_exists('subapi_is_topup_hold_request') && subapi_is_topup_hold_request($row)) {
        if (!subapi_settle_topup_success($row, $message)) {
            return [
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => 'Failed to settle held balance for API topup',
            ];
        }
    } else {
        $holdAmount = (float)($row['wallet_hold_amount'] ?? 0);

        if ($holdAmount > 0) {
            if (function_exists('wallet_settle_hold_topup')) {
                $settle = wallet_settle_hold_topup($uid, $holdAmount, $requestId, 'TOPUP_SETTLE');
            } elseif (function_exists('wallet_settle_hold')) {
                $settle = wallet_settle_hold($uid, $holdAmount, $requestId, 'TOPUP_SETTLE');
            } else {
                return [
                    'ok' => false,
                    'code' => 'SERVER_ERROR',
                    'message' => 'Missing wallet settle function for topup',
                ];
            }

            if (!($settle['ok'] ?? false)) {
                return $settle;
            }
        }
    }

    $done = $row;
    unset($done['_bucket']);

    $done['status'] = 'SUCCESS';
    $done['final_message'] = $message;
    $done['completed_at'] = now_ts();
    $done['updated_at'] = now_ts();
    $done['request_source'] = (string)($done['request_source'] ?? $done['source'] ?? '');

    if (!fb_put('TOPUP_REQUESTS/DONE/' . $requestId, $done)) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to done bucket',
        ];
    }

    if ($bucket !== '') {
        fb_delete('TOPUP_REQUESTS/' . $bucket . '/' . $requestId);
    }

    topup_write_history($done);
    update_request_status($requestId, 'SUCCESS', $message);

    if (
        strtoupper(trim((string)($row['source'] ?? ''))) === 'SUBADMIN_API' &&
        $uid !== ''
    ) {
        fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, [
            'status' => 'SUCCESS',
            'message' => $message,
            'updated_at' => now_ts(),
        ]);
    }

    system_log('TOPUP_SUCCESS', $requestId, 'Topup marked as success', [
        'uid' => $uid,
        'amount' => $amount,
        'wallet_hold_amount' => (float)($row['wallet_hold_amount'] ?? 0),
        'subapi_held_amount' => (float)($row['held_amount'] ?? 0),
        'request_source' => (string)($row['source'] ?? ''),
    ]);

    return [
        'ok' => true,
        'code' => 'TOPUP_SUCCESS',
        'message' => 'Topup marked as success',
    ];
}

function topup_mark_failed(string $requestId, string $message): array
{
    $row = topup_find_request($requestId);

    if (!is_array($row)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Topup request not found',
        ];
    }

    $bucket = (string)($row['_bucket'] ?? '');
    if ($bucket === 'DONE') {
        return [
            'ok' => false,
            'code' => 'ALREADY_DONE',
            'message' => 'Topup request already completed',
        ];
    }

    $uid = (string)($row['uid'] ?? '');
    $amount = (float)($row['amount'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | Release wallet hold
    |--------------------------------------------------------------------------
    | SUBADMIN_API requests use subapi hold/release logic
    | Normal requests use wallet_hold_amount logic
    */
    if (function_exists('subapi_is_topup_hold_request') && subapi_is_topup_hold_request($row)) {
        if (!subapi_settle_topup_failed($row, $message)) {
            return [
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => 'Failed to release held balance for API topup',
            ];
        }
    } else {
        $holdAmount = (float)($row['wallet_hold_amount'] ?? 0);

        if ($holdAmount > 0) {
            $refund = wallet_refund_hold($uid, $holdAmount, $requestId, 'TOPUP_REFUND');
            if (!($refund['ok'] ?? false)) {
                return $refund;
            }
        }
    }

    $done = $row;
    unset($done['_bucket']);

    $done['status'] = 'FAILED';
    $done['final_message'] = $message;
    $done['completed_at'] = now_ts();
    $done['updated_at'] = now_ts();
    $done['request_source'] = (string)($done['request_source'] ?? $done['source'] ?? '');
    $done['original_request_id'] = $requestId;
    $done['original_wallet_debit'] = (float)($row['wallet_debit_amount'] ?? $row['wallet_hold_amount'] ?? 0);
    $done['original_wallet_debit_currency'] = (string)($row['wallet_debit_currency'] ?? $row['wallet_currency'] ?? 'BDT');
    $done['refund_reason'] = $message;
    $done['refunded_at'] = now_ts();
    $done['commission_reversed'] = false;

    if (!fb_put('TOPUP_REQUESTS/DONE/' . $requestId, $done)) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to done bucket',
        ];
    }

    if ($bucket !== '') {
        fb_delete('TOPUP_REQUESTS/' . $bucket . '/' . $requestId);
    }

    topup_write_history($done);
    update_request_status($requestId, 'FAILED', $message);

    if (
        strtoupper(trim((string)($row['source'] ?? ''))) === 'SUBADMIN_API' &&
        $uid !== ''
    ) {
        fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, [
            'status' => 'FAILED',
            'message' => $message,
            'updated_at' => now_ts(),
        ]);
    }

    system_log('TOPUP_FAILED', $requestId, 'Topup marked as failed', [
        'uid' => $uid,
        'amount' => $amount,
        'wallet_hold_amount' => (float)($row['wallet_hold_amount'] ?? 0),
        'subapi_held_amount' => (float)($row['held_amount'] ?? 0),
        'request_source' => (string)($row['source'] ?? ''),
    ]);

    return [
        'ok' => true,
        'code' => 'TOPUP_FAILED',
        'message' => 'Topup marked as failed',
    ];
}
