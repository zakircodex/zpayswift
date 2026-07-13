<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/notifications.php';

/*
|--------------------------------------------------------------------------
| Z-Pay Swift - MFS Core Helper
|--------------------------------------------------------------------------
| Providers: bKash, Nagad
| Modes:
| - BD user = LOCAL, wallet BDT, official/config fee
| - MY user = REMITTANCE, wallet BDT or MYR, manual RM fee
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

function mfs_money_text($value, string $currency): string
{
    $currency = mfs_normalize_currency($currency);
    $amount = number_format(mfs_round_money((float)$value), 2, '.', '');
    return $currency === 'MYR' ? 'RM ' . $amount : $amount . ' BDT';
}

function mfs_record_user_notification(array $row, string $requestId, string $status): void
{
    $uid = trim((string)($row['uid'] ?? ''));
    $requestId = trim($requestId);
    if ($uid === '' || $requestId === '') {
        return;
    }
    $success = strtoupper($status) === 'SUCCESSFUL';
    $provider = trim((string)($row['provider_name'] ?? $row['provider'] ?? 'MFS'));
    notification_record_user(
        $uid,
        'MFS_STATUS',
        $success ? $provider . ' Successful' : $provider . ' Failed',
        $success ? 'Your ' . $provider . ' request was successful.' : 'Your ' . $provider . ' request could not be completed.',
        'MFS',
        $requestId,
        'MFS_STATUS:' . $requestId . ':' . strtoupper($status),
        [
            'request_id' => $requestId,
            'status' => strtoupper($status),
        ]
    );
}

function mfs_make_request_id(): string
{
    try {
        return 'MF' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    } catch (Throwable $e) {
        return 'MF' . date('YmdHis') . strtoupper(substr(md5((string)microtime(true)), 0, 10));
    }
}

function mfs_telegram_bot_token(): string
{
    foreach (['TELEGRAM_BOT_TOKEN', 'ZAW_TELEGRAM_BOT_TOKEN'] as $constant) {
        if (defined($constant) && trim((string)constant($constant)) !== '') {
            return trim((string)constant($constant));
        }
    }

    return '';
}

function mfs_telegram_chat_id(): string
{
    foreach (['TELEGRAM_CHAT_ID', 'TELEGRAM_BUNDLE_CHAT_ID', 'ZAW_TELEGRAM_CHAT_ID'] as $constant) {
        if (defined($constant) && trim((string)constant($constant)) !== '') {
            return trim((string)constant($constant));
        }
    }

    return '';
}

function mfs_telegram_action_key(): string
{
    if (defined('TELEGRAM_MFS_ACTION_KEY') && trim((string)TELEGRAM_MFS_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_MFS_ACTION_KEY);
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function mfs_telegram_legacy_action_keys(): array
{
    $keys = [];

    foreach (['TELEGRAM_BUNDLE_ACTION_KEY', 'APP_KEY'] as $constant) {
        if (!defined($constant)) {
            continue;
        }

        $key = trim((string)constant($constant));
        if ($key !== '' && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    return $keys;
}

function mfs_telegram_signature(string $requestId, string $actionCode, ?string $key = null): string
{
    $requestId = trim($requestId);
    $actionCode = strtolower(trim($actionCode));
    $key = $key === null ? mfs_telegram_action_key() : trim($key);

    return substr(hash_hmac('sha256', $actionCode . '|' . $requestId, $key), 0, 16);
}

function mfs_telegram_action_details(string $value): array
{
    $value = strtoupper(trim($value));
    if (strpos($value, 'MFS_') === 0) {
        $value = substr($value, 4);
    }

    $map = [
        'P' => ['code' => 'p', 'action' => 'PROCESSING', 'name' => 'processing'],
        'PROCESSING' => ['code' => 'p', 'action' => 'PROCESSING', 'name' => 'processing'],
        'S' => ['code' => 's', 'action' => 'SUCCESS', 'name' => 'success'],
        'SUCCESS' => ['code' => 's', 'action' => 'SUCCESS', 'name' => 'success'],
        'F' => ['code' => 'f', 'action' => 'FAILED', 'name' => 'failed'],
        'FAILED' => ['code' => 'f', 'action' => 'FAILED', 'name' => 'failed'],
    ];

    return $map[$value] ?? [];
}

function mfs_telegram_verify_callback_signature(
    string $requestId,
    array $actionDetails,
    string $signature,
    string $rawAction
): array {
    $currentKey = mfs_telegram_action_key();
    $keyCandidates = [];

    if ($currentKey !== '') {
        $keyCandidates[] = ['key' => $currentKey, 'source' => 'mfs'];
    }

    foreach (mfs_telegram_legacy_action_keys() as $legacyKey) {
        if ($legacyKey !== '' && $legacyKey !== $currentKey) {
            $keyCandidates[] = ['key' => $legacyKey, 'source' => 'legacy'];
        }
    }

    $actionCandidates = [
        (string)($actionDetails['code'] ?? ''),
        (string)($actionDetails['name'] ?? ''),
        strtolower(trim($rawAction)),
    ];
    $actionCandidates = array_values(array_unique(array_filter($actionCandidates, static fn(string $value): bool => $value !== '')));

    foreach ($keyCandidates as $keyCandidate) {
        foreach ($actionCandidates as $signatureAction) {
            $expected = mfs_telegram_signature($requestId, $signatureAction, (string)$keyCandidate['key']);
            if (hash_equals($expected, $signature)) {
                return [
                    'ok' => true,
                    'key_source' => (string)$keyCandidate['source'],
                    'signature_action' => $signatureAction,
                ];
            }
        }
    }

    return [
        'ok' => false,
        'reason' => $currentKey === '' ? 'missing_action_key' : 'invalid_signature',
    ];
}

function mfs_telegram_callback_data(string $actionCode, string $requestId): string
{
    $actionCode = strtolower(trim($actionCode));
    $requestId = trim($requestId);

    if (!in_array($actionCode, ['p', 's', 'f'], true)) {
        throw new InvalidArgumentException('Invalid MFS Telegram action');
    }

    if (!preg_match('/^[A-Za-z0-9_-]{3,41}$/', $requestId)) {
        throw new InvalidArgumentException('Invalid MFS request id for Telegram callback');
    }

    $callbackData = 'mfs|' . $actionCode . '|' . $requestId . '|' . mfs_telegram_signature($requestId, $actionCode);

    if (strlen($callbackData) > 64) {
        throw new LengthException('MFS Telegram callback exceeds 64 bytes');
    }

    return $callbackData;
}

function mfs_telegram_parse_callback_data(string $callbackData): array
{
    $callbackData = trim($callbackData);
    $parts = explode('|', $callbackData);
    $legacyUnsignedActions = [
        'MFS_PROCESSING',
        'MFS_SUCCESS',
        'MFS_FAILED',
    ];

    if (count($parts) === 2 && in_array(strtoupper(trim($parts[0])), $legacyUnsignedActions, true)) {
        $legacyAction = strtoupper(trim($parts[0]));
        $actionDetails = mfs_telegram_action_details($legacyAction);
        $requestId = trim($parts[1]);

        if (!preg_match('/^[A-Za-z0-9_-]{3,120}$/', $requestId)) {
            return [
                'ok' => false,
                'reason' => 'invalid_request_id',
                'callback_action' => $legacyAction,
                'request_id' => $requestId,
                'format' => 'legacy',
            ];
        }

        return [
            'ok' => true,
            'action' => (string)$actionDetails['action'],
            'action_code' => (string)$actionDetails['code'],
            'request_id' => $requestId,
            'format' => 'legacy_unsigned',
            'legacy' => true,
        ];
    }

    $isPipeSigned = count($parts) === 4 && strcasecmp(trim($parts[0]), 'mfs') === 0;
    $isLegacySigned = count($parts) === 3 && in_array(strtoupper(trim($parts[0])), $legacyUnsignedActions, true);

    if (!$isPipeSigned && !$isLegacySigned) {
        return [
            'ok' => false,
            'reason' => 'invalid_format',
            'callback_action' => trim((string)($parts[1] ?? $parts[0] ?? '')),
            'request_id' => trim((string)($parts[2] ?? $parts[1] ?? '')),
            'format' => 'unknown',
        ];
    }

    $rawAction = $isPipeSigned ? trim($parts[1]) : trim($parts[0]);
    $actionDetails = mfs_telegram_action_details($rawAction);
    $requestId = trim($parts[$isPipeSigned ? 2 : 1]);
    $signature = strtolower(trim($parts[$isPipeSigned ? 3 : 2]));
    $format = $isPipeSigned ? 'signed' : 'legacy_signed';

    if (!$actionDetails) {
        return [
            'ok' => false,
            'reason' => 'invalid_action',
            'callback_action' => $rawAction,
            'request_id' => $requestId,
            'format' => $format,
        ];
    }

    if (!preg_match('/^[A-Za-z0-9_-]{3,120}$/', $requestId)) {
        return [
            'ok' => false,
            'reason' => 'invalid_request_id',
            'callback_action' => $rawAction,
            'request_id' => $requestId,
            'format' => $format,
        ];
    }

    if (!preg_match('/^[a-f0-9]{16}$/', $signature)) {
        return [
            'ok' => false,
            'reason' => 'invalid_signature_format',
            'callback_action' => $rawAction,
            'request_id' => $requestId,
            'format' => $format,
        ];
    }

    $verified = mfs_telegram_verify_callback_signature($requestId, $actionDetails, $signature, $rawAction);

    if (empty($verified['ok'])) {
        return [
            'ok' => false,
            'reason' => (string)($verified['reason'] ?? 'invalid_signature'),
            'callback_action' => $rawAction,
            'request_id' => $requestId,
            'format' => $format,
        ];
    }

    return [
        'ok' => true,
        'action' => (string)$actionDetails['action'],
        'action_code' => (string)$actionDetails['code'],
        'request_id' => $requestId,
        'format' => $format,
        'legacy' => $isLegacySigned || ($verified['key_source'] ?? '') === 'legacy',
        'key_source' => (string)($verified['key_source'] ?? ''),
        'signature_action' => (string)($verified['signature_action'] ?? ''),
    ];
}

function mfs_make_receipt_id(): string
{
    try {
        return 'RCP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable $e) {
        return 'RCP' . date('YmdHis') . strtoupper(substr(md5((string)microtime(true)), 0, 8));
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
    $settings = mfs_fb_get('MFS_SETTINGS');

    $cache = is_array($row) ? $row : [];

    if (is_array($settings)) {
        $rate = mfs_nested_value($settings, 'rate_myr_bdt', null);
        if ($rate === null || $rate === '') {
            $rate = mfs_nested_value($settings, 'rate.myr_to_bdt', null);
        }

        if (is_numeric($rate) && (float)$rate > 0) {
            $rate = mfs_round_money($rate);
            $cache['rate_myr_bdt'] = $rate;
            $cache['myr_to_bdt_rate'] = $rate;
            $cache['rates']['myr_to_bdt'] = $rate;
        }

        $fees = mfs_nested_value($settings, 'fees', null);
        if (is_array($fees)) {
            $cache['fees'] = array_replace_recursive(
                is_array($cache['fees'] ?? null) ? $cache['fees'] : [],
                $fees
            );
        }

        $cache['MFS_SETTINGS'] = $settings;
    }

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

function mfs_infer_country_from_phone(string $phone): string
{
    $raw = trim($phone);
    $digits = mfs_clean_mobile_number($raw);

    if ($digits === '') {
        return '';
    }

    if (strpos($raw, '+60') === 0 || preg_match('/^60\d{7,12}$/', $digits)) {
        return 'MY';
    }

    if (strpos($raw, '+880') === 0 || preg_match('/^8801\d{9}$/', $digits)) {
        return 'BD';
    }

    if (preg_match('/^01\d{9}$/', $digits)) {
        return 'BD';
    }

    return '';
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

function mfs_user_country_code(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        return auth_pricing_country_from_user($user, $wallet);
    }

    if (function_exists('security_user_country_code')) {
        $country = (string)security_user_country_code($user, $wallet);
        if ($country !== '') {
            return $country;
        }
    }

    $country = mfs_normalize_country_code((string)(
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
    $country = mfs_user_country_code($user, $wallet);
    $currency = mfs_wallet_currency($user, $wallet);

    if (function_exists('security_validate_country_wallet_lock')) {
        $check = security_validate_country_wallet_lock($user, $wallet);

        $code = is_array($check) ? (string)($check['code'] ?? '') : '';
        $checkCountry = is_array($check) ? (string)($check['country_code'] ?? $country) : $country;
        $checkCurrency = is_array($check) ? (string)($check['wallet_currency'] ?? $currency) : $currency;

        if (is_array($check) && !empty($check) && !in_array($code, ['COUNTRY_MISSING', 'WALLET_CURRENCY_MISSING'], true)) {
            if ($code === 'COUNTRY_CURRENCY_MISMATCH' && $checkCountry === 'MY' && $checkCurrency === 'BDT') {
                // Legacy wallets are still BDT-based; MY MFS calculates and holds the BDT equivalent.
            } else {
                return $check;
            }
        }
    }

    $expected = '';
    if ($country === 'BD') {
        $expected = 'BDT';
    } elseif ($country === 'MY') {
        $expected = 'MYR';
    }

    $serviceMode = $country === 'MY' ? 'REMITTANCE' : mfs_service_mode_from_currency($currency);

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

    if ($expected !== '' && $currency !== $expected && !($country === 'MY' && $currency === 'BDT')) {
        return [
            'ok' => false,
            'code' => 'COUNTRY_CURRENCY_MISMATCH',
            'message' => 'User country and wallet currency mismatch',
            'country_code' => $country,
            'wallet_currency' => $currency,
            'expected_currency' => $expected,
            'service_mode' => $serviceMode,
        ];
    }

    return [
        'ok' => true,
        'code' => 'COUNTRY_WALLET_OK',
        'message' => 'Country and wallet currency valid',
        'country_code' => $country,
        'wallet_currency' => $currency,
        'expected_currency' => $expected,
        'service_mode' => $serviceMode,
    ];
}

function mfs_wallet_display_payload(array $user, array $wallet): array
{
    $country = mfs_user_country_code($user, $wallet);
    $walletCurrency = mfs_wallet_currency($user, $wallet);
    $rate = mfs_myr_to_bdt_rate();
    $available = mfs_round_money((float)($wallet['available_balance'] ?? 0));
    $hold = mfs_round_money((float)($wallet['hold_balance'] ?? 0));

    if ($walletCurrency === 'MYR') {
        $availableMyr = $available;
        $holdMyr = $hold;
        $availableBdt = mfs_round_money($available * $rate);
        $holdBdt = mfs_round_money($hold * $rate);
    } else {
        $walletCurrency = $walletCurrency ?: 'BDT';
        $availableBdt = $available;
        $holdBdt = $hold;
        $availableMyr = $rate > 0 ? mfs_round_money($available / $rate) : 0.0;
        $holdMyr = $rate > 0 ? mfs_round_money($hold / $rate) : 0.0;
    }

    $displayCurrency = $country === 'MY' ? 'MYR' : ($walletCurrency === 'MYR' ? 'MYR' : 'BDT');
    $displayAvailable = $displayCurrency === 'MYR' ? $availableMyr : $availableBdt;
    $displayHold = $displayCurrency === 'MYR' ? $holdMyr : $holdBdt;

    return [
        'country_code' => $country,
        'wallet_currency' => $walletCurrency,
        'currency' => $walletCurrency,
        'display_currency' => $displayCurrency,
        'display_available_balance' => $displayAvailable,
        'display_hold_balance' => $displayHold,
        'available_balance_bdt' => $availableBdt,
        'hold_balance_bdt' => $holdBdt,
        'available_balance_myr' => $availableMyr,
        'hold_balance_myr' => $holdMyr,
        'rate_myr_bdt' => $rate,
        'conversion_note' => ($country === 'MY' && $walletCurrency === 'BDT')
            ? 'Wallet balance is stored in BDT; MYR display uses configured MFS rate.'
            : '',
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
        'BD.' . $provider,
        'LOCAL.' . $provider,
        'official_fees.' . $provider,
        'fees.BD.' . $provider,
        'fees.LOCAL.' . $provider,
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

function mfs_fee_roles(): array
{
    return ['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'];
}

function mfs_default_my_fee_rm(string $role): float
{
    $role = strtoupper(trim($role));

    if ($role === 'SUBADMIN') {
        return mfs_const_float('MY_REMITTANCE_FEE_SUBADMIN_RM', 2.00);
    }

    if ($role === 'RETAILER') {
        return mfs_const_float('MY_REMITTANCE_FEE_RETAILER_RM', 2.00);
    }

    if ($role === 'ADMIN') {
        return mfs_const_float('MY_REMITTANCE_FEE_ADMIN_RM', 0.00);
    }

    return mfs_const_float('MY_REMITTANCE_FEE_USER_RM', 5.00);
}

function mfs_pick_role_fee_from_row(array $row, string $role): float
{
    $roleFee = mfs_nested_value($row, $role, null);

    if (is_numeric($roleFee)) {
        return mfs_round_money($roleFee);
    }

    if (is_array($roleFee)) {
        $pickedRoleFee = mfs_pick_fee_float($roleFee, ['fee_rm', 'fixed', 'fixed_fee', 'amount', 'rm'], -1.0);
        if ($pickedRoleFee >= 0) {
            return mfs_round_money($pickedRoleFee);
        }
    }

    return -1.0;
}

function mfs_remittance_fee_rm(string $role, string $provider = ''): float
{
    $role = strtoupper(trim($role));
    if (!in_array($role, mfs_fee_roles(), true)) {
        $role = 'USER';
    }

    $provider = mfs_normalize_provider($provider);

    $paths = [];

    if ($provider !== '') {
        $providerFee = mfs_config_first(['fees.MY.' . $provider], null);

        if (is_array($providerFee)) {
            $pickedRoleFee = mfs_pick_role_fee_from_row($providerFee, $role);
            if ($pickedRoleFee >= 0) {
                return mfs_round_money($pickedRoleFee);
            }

            $pickedProviderFee = $role === 'USER'
                ? mfs_pick_fee_float($providerFee, ['fee_rm', 'fixed', 'fixed_fee', 'amount', 'rm'], -1.0)
                : -1.0;
            if ($pickedProviderFee >= 0) {
                return mfs_round_money($pickedProviderFee);
            }
        }

        $paths[] = 'MY.' . $provider . '.remittance_fee_rm.' . $role;
        $paths[] = 'REMITTANCE.' . $provider . '.fee_rm.' . $role;
        $paths[] = 'fees.MY.' . $provider . '.' . $role;
        if ($role === 'USER') {
            $paths[] = 'fees.MY.' . $provider . '.fee_rm';
            $paths[] = 'fees.MY.' . $provider . '.fixed';
            $paths[] = 'fees.MY.' . $provider . '.fixed_fee';
            $paths[] = 'fees.MY.' . $provider . '.amount';
        }
    }

    $paths[] = 'MY.remittance_fee_rm.' . $role;
    $paths[] = 'REMITTANCE.fee_rm.' . $role;
    $paths[] = 'remittance_fee_rm.' . $role;
    $paths[] = 'fees.MY.' . $role;

    $fee = mfs_config_float($paths, -1.0);

    if ($fee >= 0) {
        return mfs_round_money($fee);
    }

    return mfs_default_my_fee_rm($role);
}

function mfs_country_label(string $countryCode): string
{
    $countryCode = mfs_normalize_country_code($countryCode);

    if ($countryCode === 'BD') {
        return 'Bangladesh';
    }

    if ($countryCode === 'MY') {
        return 'Malaysia';
    }

    return '';
}

function mfs_public_settings(): array
{
    $rate = mfs_myr_to_bdt_rate();
    $providers = ['BKASH', 'NAGAD'];
    $fees = [
        'BD' => [],
        'MY' => [],
    ];

    foreach ($providers as $provider) {
        $bdRow = mfs_official_fee_row($provider, 'SEND_MONEY');
        $fees['BD'][$provider] = [
            'type' => (string)($bdRow['type'] ?? ((float)mfs_pick_fee_float($bdRow, ['percent_fee', 'percent', 'fee_percent', 'rate_percent'], 0.0) > 0 ? 'percent' : 'fixed')),
            'fixed' => mfs_pick_fee_float($bdRow, ['fixed_fee', 'fixed', 'fee_fixed', 'flat_fee'], 0.0),
            'percent' => mfs_pick_fee_float($bdRow, ['percent_fee', 'percent', 'fee_percent', 'rate_percent'], 0.0),
            'min_fee' => mfs_pick_fee_float($bdRow, ['min_fee', 'minimum_fee'], 0.0),
            'max_fee' => mfs_pick_fee_float($bdRow, ['max_fee', 'maximum_fee'], 0.0),
        ];

        $myRow = mfs_config_first(['fees.MY.' . $provider], null);
        $userFee = mfs_remittance_fee_rm('USER', $provider);
        $fees['MY'][$provider] = [
            'type' => is_array($myRow) ? (string)($myRow['type'] ?? 'fixed') : 'fixed',
            'fixed' => $userFee,
            'fee_rm' => $userFee,
            'USER' => mfs_remittance_fee_rm('USER', $provider),
            'RETAILER' => mfs_remittance_fee_rm('RETAILER', $provider),
            'SUBADMIN' => mfs_remittance_fee_rm('SUBADMIN', $provider),
            'ADMIN' => mfs_remittance_fee_rm('ADMIN', $provider),
        ];
    }

    return [
        'rate_myr_bdt' => $rate,
        'countries' => [
            'BD' => 'Bangladesh',
            'MY' => 'Malaysia',
        ],
        'fees' => $fees,
        'limits' => mfs_bdt_transfer_limits(),
    ];
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

    if ($countryCode === 'BD') {
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
            'wallet_currency' => $walletCurrency,
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

    if ($countryCode === 'MY') {
        if ($amountRm <= 0 && $amount > 0 && $inputCurrency === 'MYR') {
            $amountRm = $amount;
        }

        if ($amountBdt <= 0 && $amount > 0 && $inputCurrency !== 'MYR') {
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
        $feeBdt = mfs_round_money($feeRm * $rate);
        $totalDebitRm = mfs_round_money($amountRm + $feeRm);
        $totalDebitBdt = mfs_round_money($amountBdt + $feeBdt);
        $walletDebit = $walletCurrency === 'MYR' ? $totalDebitRm : $totalDebitBdt;

        return [
            'ok' => true,
            'country_code' => 'MY',
            'wallet_currency' => $walletCurrency,
            'service_mode' => 'REMITTANCE',
            'exchange_rate' => $rate,

            'amount_bdt' => $amountBdt,
            'amount_rm' => $amountRm,

            'fee_bdt' => $feeBdt,
            'fee_rm' => $feeRm,

            'total_debit_bdt' => $totalDebitBdt,
            'total_debit_rm' => $totalDebitRm,
            'total_debit' => $walletDebit,
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

function mfs_preview_payload(string $uid, array $body): array
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
    $serviceType = mfs_normalize_service_type((string)($body['service_type'] ?? $body['service'] ?? $body['mfs_type'] ?? 'SEND_MONEY'));
    $accountType = mfs_normalize_account_type((string)($body['account_type'] ?? ''), $serviceType);
    $receiverNumber = mfs_clean_mobile_number((string)($body['receiver_number'] ?? $body['number'] ?? $body['mobile'] ?? ''));

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
            'data' => ['provider' => $provider],
        ];
    }

    if ($serviceType === '' || $accountType === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Valid service and account type are required',
            'data' => [],
        ];
    }

    if (!mfs_valid_bd_mobile($receiverNumber)) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Receiver number must be a valid 11 digit BD mobile number',
            'data' => ['required_format' => '01XXXXXXXXX'],
        ];
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

    $walletCurrency = (string)$amounts['wallet_currency'];
    $walletHoldAmount = mfs_round_money((float)$amounts['total_debit']);
    $available = mfs_wallet_available_balance($wallet);
    $hold = mfs_wallet_hold_balance($wallet);
    $displayWallet = mfs_wallet_display_payload($user, $wallet);
    $displayCurrency = (string)($displayWallet['display_currency'] ?? $walletCurrency);
    $displayAvailable = mfs_round_money((float)($displayWallet['display_available_balance'] ?? $available));
    $displayHold = mfs_round_money((float)($displayWallet['display_hold_balance'] ?? $hold));
    $displayDebit = $displayCurrency === 'MYR'
        ? mfs_round_money((float)$amounts['total_debit_rm'])
        : mfs_round_money((float)$amounts['total_debit_bdt']);
    $displayBalanceAfter = mfs_round_money($displayAvailable - $displayDebit);
    $feeCurrency = $walletCurrency === 'MYR' ? 'MYR' : 'BDT';
    $feeAmount = $feeCurrency === 'MYR'
        ? mfs_round_money((float)$amounts['fee_rm'])
        : mfs_round_money((float)$amounts['fee_bdt']);
    $totalDebitText = mfs_money_text($walletHoldAmount, $walletCurrency);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS preview ready',
        'data' => [
            'uid' => $uid,
            'role' => mfs_user_role($user),
            'country_code' => (string)$amounts['country_code'],
            'country' => (string)$amounts['country_code'],
            'service_mode' => (string)$amounts['service_mode'],
            'mode' => (string)$amounts['service_mode'],
            'wallet_currency' => $walletCurrency,
            'currency' => $walletCurrency,
            'display_currency' => $displayCurrency,
            'wallet_hold_amount' => $walletHoldAmount,
            'wallet_balance' => $available,
            'display_available_balance' => $displayAvailable,
            'display_hold_balance' => $displayHold,
            'display_total_pay' => $displayDebit,
            'display_balance_after' => $displayBalanceAfter,
            'available_balance_bdt' => (float)($displayWallet['available_balance_bdt'] ?? $available),
            'available_balance_myr' => (float)($displayWallet['available_balance_myr'] ?? 0),
            'hold_balance_bdt' => (float)($displayWallet['hold_balance_bdt'] ?? $hold),
            'hold_balance_myr' => (float)($displayWallet['hold_balance_myr'] ?? 0),

            'provider' => $provider,
            'provider_name' => mfs_provider_name($provider),
            'service_type' => $serviceType,
            'service_name' => mfs_service_name($serviceType),
            'account_type' => $accountType,
            'receiver_number' => $receiverNumber,
            'number' => $receiverNumber,

            'rate_myr_to_bdt' => (float)$amounts['exchange_rate'],
            'exchange_rate' => (float)$amounts['exchange_rate'],
            'amount_bdt' => (float)$amounts['amount_bdt'],
            'amount_rm' => (float)$amounts['amount_rm'],
            'amount_myr' => (float)$amounts['amount_rm'],
            'fee_bdt' => (float)$amounts['fee_bdt'],
            'fee_rm' => (float)$amounts['fee_rm'],
            'fee_myr' => (float)$amounts['fee_rm'],
            'fee_currency' => $feeCurrency,
            'fee_amount' => $feeAmount,
            'total_pay' => $walletHoldAmount,
            'total_debit' => $walletHoldAmount,
            'total_pay_text' => $totalDebitText,
            'total_debit_text' => $totalDebitText,
            'wallet_debit' => $walletHoldAmount,
            'wallet_debit_text' => $totalDebitText,
            'total_pay_bdt' => (float)$amounts['total_debit_bdt'],
            'total_debit_bdt' => (float)$amounts['total_debit_bdt'],
            'total_pay_myr' => (float)$amounts['total_debit_rm'],
            'total_debit_rm' => (float)$amounts['total_debit_rm'],

            'available_balance' => $available,
            'hold_balance' => $hold,
            'can_pay' => $available >= $walletHoldAmount,
            'reference' => trim((string)($body['reference'] ?? '')),
            'message' => 'Preview only. No wallet hold has been created.',
        ],
    ];
}

/* =========================================================
   Preview Token Helpers
========================================================= */

function mfs_preview_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function mfs_preview_error(string $code, string $message, array $data = [], int $httpStatus = 422): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'http_status' => $httpStatus,
    ];
}

function mfs_random_preview_token(): string
{
    if (function_exists('random_token')) {
        return random_token(32);
    }

    try {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    } catch (Throwable $e) {
        return hash('sha256', uniqid('mfs_preview_', true) . '|' . mt_rand());
    }
}

function mfs_create_preview_token(array $data): string
{
    $token = mfs_random_preview_token();
    $hash = mfs_preview_token_hash($token);
    $now = mfs_now();

    $data['preview_token_hash'] = $hash;
    $data['created_at'] = (int)($data['created_at'] ?? $now);
    $data['expires_at'] = (int)($data['expires_at'] ?? ($now + 300));
    $data['used'] = false;
    $data['used_at'] = 0;
    $data['status'] = 'READY';
    $data['updated_at'] = $now;

    if (!mfs_fb_put('MFS_PREVIEWS/' . $hash, $data)) {
        return '';
    }

    return $token;
}

function mfs_claim_preview_token(string $tokenHash, string $uid): array
{
    $tokenHash = trim($tokenHash);
    $uid = trim($uid);

    if ($tokenHash === '' || $uid === '') {
        return mfs_preview_error('MFS_PREVIEW_INVALID', 'MFS preview is invalid. Please review again.');
    }

    $path = 'MFS_PREVIEWS/' . $tokenHash;

    for ($i = 0; $i < 5; $i++) {
        if (!function_exists('fb_get_with_etag') || !function_exists('fb_put_if_match')) {
            $row = mfs_fb_get($path);
            if (!is_array($row)) {
                return mfs_preview_error('MFS_PREVIEW_INVALID', 'MFS preview is invalid. Please review again.');
            }
            $etag = '';
        } else {
            $res = fb_get_with_etag($path);
            if (!($res['ok'] ?? false) || !is_array($res['value'] ?? null)) {
                return mfs_preview_error('MFS_PREVIEW_INVALID', 'MFS preview is invalid. Please review again.');
            }
            $row = (array)$res['value'];
            $etag = (string)($res['etag'] ?? '');
            if ($etag === '') {
                return mfs_preview_error('MFS_PREVIEW_CLAIM_FAILED', 'MFS preview could not be locked. Please try again.', [], 409);
            }
        }

        $status = strtoupper(trim((string)($row['status'] ?? 'READY')));
        if (!empty($row['used']) || $status === 'USED') {
            $requestId = trim((string)($row['request_id'] ?? ''));
            if ($requestId !== '') {
                $row['_token_hash'] = $tokenHash;
                return [
                    'ok' => true,
                    'duplicate' => true,
                    'request_id' => $requestId,
                    'preview' => $row,
                ];
            }
            return mfs_preview_error('MFS_ALREADY_SUBMITTED', 'This request has already been submitted.');
        }

        if ($status === 'PROCESSING') {
            return mfs_preview_error('MFS_ALREADY_SUBMITTED', 'This request is already being submitted.', [], 409);
        }

        if ((int)($row['expires_at'] ?? 0) < mfs_now()) {
            @mfs_fb_patch($path, [
                'status' => 'EXPIRED',
                'updated_at' => mfs_now(),
            ]);
            return mfs_preview_error('MFS_PREVIEW_EXPIRED', 'This preview has expired. Please review again.');
        }

        if ((string)($row['uid'] ?? '') !== $uid) {
            return mfs_preview_error('MFS_PREVIEW_INVALID', 'MFS preview does not belong to this account.', [], 403);
        }

        if (!in_array($status, ['READY', 'ACTIVE', 'FAILED'], true)) {
            return mfs_preview_error('MFS_ALREADY_SUBMITTED', 'This request is already being submitted.', [], 409);
        }

        $claimed = $row;
        $claimed['status'] = 'PROCESSING';
        $claimed['processing_at'] = mfs_now();
        $claimed['updated_at'] = mfs_now();

        if ($etag === '') {
            if (!mfs_fb_put($path, $claimed)) {
                return mfs_preview_error('MFS_PREVIEW_CLAIM_FAILED', 'MFS preview could not be locked. Please try again.', [], 409);
            }
        } else {
            $save = fb_put_if_match($path, $claimed, $etag);
            if (($save['status'] ?? 0) === 412) {
                usleep(150000);
                continue;
            }
            if (!($save['ok'] ?? false)) {
                return mfs_preview_error('MFS_PREVIEW_CLAIM_FAILED', 'MFS preview could not be locked. Please try again.', [], 409);
            }
        }

        $claimed['_token_hash'] = $tokenHash;
        return ['ok' => true, 'preview' => $claimed];
    }

    return mfs_preview_error('MFS_ALREADY_SUBMITTED', 'This request is already being submitted.', [], 409);
}

function mfs_mark_preview_used(string $tokenHash, string $requestId): void
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return;
    }

    @mfs_fb_patch('MFS_PREVIEWS/' . $tokenHash, [
        'used' => true,
        'used_at' => mfs_now(),
        'status' => 'USED',
        'request_id' => trim($requestId),
        'updated_at' => mfs_now(),
    ]);
}

function mfs_mark_preview_failed(string $tokenHash, string $code, string $message = ''): void
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return;
    }

    @mfs_fb_patch('MFS_PREVIEWS/' . $tokenHash, [
        'status' => 'FAILED',
        'failed_code' => substr(trim($code), 0, 80),
        'failed_message' => substr(trim($message), 0, 180),
        'updated_at' => mfs_now(),
    ]);
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
    $walletCurrency = mfs_normalize_currency((string)($row['wallet_currency'] ?? $row['wallet_debit_currency'] ?? 'BDT'));
    $amountBdt = (float)($row['amount_bdt'] ?? 0);
    $amountRm = (float)($row['amount_rm'] ?? $row['amount_myr'] ?? 0);
    $exchangeRate = (float)($row['exchange_rate'] ?? $row['rate_snapshot'] ?? $row['rate_myr_to_bdt'] ?? 0);
    $feeCurrency = mfs_normalize_currency((string)($row['fee_currency'] ?? ($walletCurrency === 'MYR' ? 'MYR' : 'BDT')));
    $feeAmount = (float)($row['fee_amount'] ?? ($feeCurrency === 'MYR' ? ($row['fee_rm'] ?? 0) : ($row['fee_bdt'] ?? 0)));
    $totalDebit = (float)($row['total_debit'] ?? $row['total_pay'] ?? $row['wallet_debit'] ?? $row['wallet_hold_amount'] ?? 0);
    $balanceAfter = (float)($row['balance_after'] ?? $row['display_balance_after'] ?? $row['last_balance'] ?? 0);
    $balanceAfterText = (string)($row['balance_after_text'] ?? '');
    if ($balanceAfterText === '' && $balanceAfter > 0 && function_exists('mfs_money_text')) {
        $balanceAfterText = mfs_money_text($balanceAfter, $walletCurrency);
    }
    $totalDebitText = (string)($row['total_debit_text'] ?? $row['total_pay_text'] ?? $row['wallet_debit_text'] ?? '');
    if ($totalDebitText === '' && $totalDebit > 0 && function_exists('mfs_money_text')) {
        $totalDebitText = mfs_money_text($totalDebit, $walletCurrency);
    }
    $rateApplicable = strtoupper((string)($row['service_mode'] ?? '')) === 'REMITTANCE'
        || $walletCurrency === 'MYR'
        || $amountRm > 0;

    return [
        'request_id' => (string)($row['request_id'] ?? ''),
        'key_id' => (string)($row['key_id'] ?? $row['source_key_id'] ?? 'PANEL'),
        'action' => 'MFS',
        'request_type' => 'MFS',
        'source' => (string)($row['source'] ?? $row['request_source'] ?? 'USER_PANEL'),
        'request_source' => (string)($row['request_source'] ?? $row['source'] ?? 'USER_PANEL'),

        'status' => (string)($row['status'] ?? 'PENDING'),
        'role' => (string)($row['user_role'] ?? $row['role'] ?? ''),
        'user_role' => (string)($row['user_role'] ?? $row['role'] ?? ''),

        'provider' => $provider,
        'provider_name' => mfs_provider_name($provider),
        'service_type' => $serviceType,
        'service_name' => mfs_service_name($serviceType),
        'account_type' => (string)($row['account_type'] ?? 'PERSONAL'),

        'receiver_number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),
        'number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),

        'country_code' => (string)($row['country_code'] ?? ''),
        'service_mode' => (string)($row['service_mode'] ?? ''),
        'wallet_currency' => $walletCurrency,
        'wallet_debit_currency' => $walletCurrency,

        'amount' => $totalDebit,
        'amount_bdt' => $amountBdt,
        'amount_rm' => $amountRm,
        'amount_myr' => $amountRm,

        'fee_bdt' => (float)($row['fee_bdt'] ?? 0),
        'fee_rm' => (float)($row['fee_rm'] ?? $row['fee_myr'] ?? 0),
        'fee_currency' => $feeCurrency,
        'fee_amount' => $feeAmount,

        'total_debit' => $totalDebit,
        'total_pay' => (float)($row['total_pay'] ?? $totalDebit),
        'total_paid' => (float)($row['total_paid'] ?? $row['total_pay'] ?? $totalDebit),
        'wallet_debit' => (float)($row['wallet_debit'] ?? $totalDebit),
        'wallet_debit_amount' => (float)($row['wallet_debit'] ?? $row['wallet_debit_amount'] ?? $totalDebit),
        'total_debit_text' => $totalDebitText,
        'total_pay_text' => (string)($row['total_pay_text'] ?? $totalDebitText),
        'wallet_debit_text' => (string)($row['wallet_debit_text'] ?? $totalDebitText),
        'total_debit_bdt' => (float)($row['total_debit_bdt'] ?? $row['total_pay_bdt'] ?? 0),
        'total_debit_rm' => (float)($row['total_debit_rm'] ?? $row['total_pay_myr'] ?? 0),

        'exchange_rate' => $exchangeRate,
        'rate_snapshot' => $exchangeRate,
        'rate_applicable' => $rateApplicable,
        'balance_after' => $balanceAfter,
        'balance_after_text' => $balanceAfterText,

        'reference' => (string)($row['reference'] ?? ''),
        'trxid' => (string)($row['trxid'] ?? ''),
        'sender_details' => (string)($row['sender_details'] ?? $row['sender_last_digit'] ?? $row['last_digit'] ?? ''),
        'sender_last_digit' => (string)($row['sender_last_digit'] ?? $row['sender_details'] ?? $row['last_digit'] ?? ''),
        'message' => (string)($row['final_message'] ?? $row['message'] ?? $row['note'] ?? ''),

        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'completed_at' => (int)($row['completed_at'] ?? 0),

        'receipt_id' => (string)($row['receipt_id'] ?? ''),
        'receipt_url' => (string)($row['receipt_url'] ?? ''),
        'tracking_url' => (string)($row['tracking_url'] ?? $row['receipt_url'] ?? ''),
        'receipt_created_at' => (int)($row['receipt_created_at'] ?? 0),
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
   Receipt Helpers
========================================================= */

function mfs_receipt_token(): string
{
    try {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    } catch (Throwable $e) {
        return hash('sha256', uniqid('mfs_receipt_', true) . '|' . mt_rand());
    }
}

function mfs_api_base_url_for_receipt(): string
{
    if (function_exists('app_api_url')) {
        return rtrim((string)app_api_url(), '/');
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $https = true;
    }

    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/api/mfs/receipt.php');
    $apiPath = dirname(dirname($script));

    if ($apiPath === '/' || $apiPath === '\\' || $apiPath === '.') {
        $apiPath = '/api';
    }

    return rtrim($scheme . '://' . $host . '/' . trim(str_replace('\\', '/', $apiPath), '/'), '/');
}

function mfs_receipt_url(string $token): string
{
    return mfs_api_base_url_for_receipt() . '/mfs/receipt.php?t=' . rawurlencode($token);
}

function mfs_receipt_display_money($value): float
{
    return mfs_round_money((float)$value);
}

function mfs_save_receipt_for_request(string $requestId, array $row, string $status = 'PENDING'): array
{
    $requestId = trim($requestId);
    $status = strtoupper(trim($status));

    if ($requestId === '') {
        return [];
    }

    if (!in_array($status, ['PENDING', 'PROCESSING', 'SUCCESSFUL', 'FAILED'], true)) {
        $status = strtoupper(trim((string)($row['status'] ?? 'PENDING'))) ?: 'PENDING';
    }

    $isSuccess = $status === 'SUCCESSFUL';
    $receiptId = trim((string)($row['receipt_id'] ?? ''));
    $existingToken = trim((string)($row['receipt_token'] ?? ''));
    $existingUrl = trim((string)($row['receipt_url'] ?? ''));

    $uid = trim((string)($row['uid'] ?? ''));
    $user = $uid !== '' ? mfs_load_user($uid) : [];
    $now = mfs_now();
    $receiptCreatedAt = (int)($row['receipt_created_at'] ?? 0);
    if ($receiptCreatedAt <= 0) {
        $receiptCreatedAt = $now;
    }

    if ($receiptId === '') {
        $receiptId = mfs_make_receipt_id();
    }

    $token = $existingToken !== '' ? $existingToken : mfs_receipt_token();
    $url = $existingUrl !== '' ? $existingUrl : mfs_receipt_url($token);

    $receipt = [
        'receipt_id' => $receiptId,
        'receipt_token' => $token,
        'receipt_url' => $url,
        'request_id' => $requestId,
        'title' => 'Z-Pay Swift Remittance Receipt',

        'uid' => $uid,
        'sender_name' => (string)($row['user_name'] ?? $user['name'] ?? ''),
        'sender_phone' => (string)($row['user_phone'] ?? $user['phone'] ?? ''),
        'sender_role' => (string)($row['user_role'] ?? $row['role'] ?? $user['role'] ?? 'USER'),

        'provider' => mfs_normalize_provider((string)($row['provider'] ?? '')),
        'provider_name' => (string)($row['provider_name'] ?? mfs_provider_name((string)($row['provider'] ?? ''))),
        'receiver_number' => (string)($row['receiver_number'] ?? $row['number'] ?? ''),
        'country_code' => (string)($row['country_code'] ?? ''),
        'country' => mfs_country_label((string)($row['country_code'] ?? '')),
        'service_mode' => (string)($row['service_mode'] ?? ''),
        'mode' => (string)($row['service_mode'] ?? ''),

        'amount_bdt' => mfs_receipt_display_money($row['amount_bdt'] ?? 0),
        'amount_rm' => mfs_receipt_display_money($row['amount_rm'] ?? $row['amount_myr'] ?? 0),
        'rate_myr_to_bdt' => mfs_receipt_display_money($row['exchange_rate'] ?? $row['rate_myr_to_bdt'] ?? 0),
        'exchange_rate' => mfs_receipt_display_money($row['exchange_rate'] ?? $row['rate_myr_to_bdt'] ?? 0),
        'fee_bdt' => mfs_receipt_display_money($row['fee_bdt'] ?? 0),
        'fee_rm' => mfs_receipt_display_money($row['fee_rm'] ?? $row['fee_myr'] ?? 0),
        'fee_currency' => (string)($row['fee_currency'] ?? (mfs_normalize_currency((string)($row['wallet_currency'] ?? 'BDT')) === 'MYR' ? 'MYR' : 'BDT')),
        'fee_amount' => mfs_receipt_display_money($row['fee_amount'] ?? (mfs_normalize_currency((string)($row['wallet_currency'] ?? 'BDT')) === 'MYR' ? ($row['fee_rm'] ?? 0) : ($row['fee_bdt'] ?? 0))),
        'total_debit_bdt' => mfs_receipt_display_money($row['total_debit_bdt'] ?? 0),
        'total_debit_rm' => mfs_receipt_display_money($row['total_debit_rm'] ?? 0),
        'total_pay' => mfs_receipt_display_money($row['total_debit'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? 0),
        'total_debit' => mfs_receipt_display_money($row['total_debit'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? 0),
        'wallet_debit' => mfs_receipt_display_money($row['wallet_debit'] ?? $row['total_debit'] ?? $row['wallet_hold_amount'] ?? $row['held_amount'] ?? 0),
        'total_pay_text' => (string)($row['total_pay_text'] ?? $row['total_debit_text'] ?? ''),
        'total_debit_text' => (string)($row['total_debit_text'] ?? $row['total_pay_text'] ?? ''),
        'wallet_debit_text' => (string)($row['wallet_debit_text'] ?? $row['total_debit_text'] ?? $row['total_pay_text'] ?? ''),
        'wallet_currency' => mfs_normalize_currency((string)($row['wallet_currency'] ?? 'BDT')),
        'balance_after' => mfs_receipt_display_money($row['balance_after'] ?? $row['display_balance_after'] ?? $row['last_balance'] ?? 0),
        'balance_after_text' => (string)($row['balance_after_text'] ?? ''),

        'reference' => (string)($row['reference'] ?? ''),
        'sender_details' => (string)($row['sender_details'] ?? $row['sender_last_digit'] ?? $row['last_digit'] ?? ''),
        'sender_last_digit' => (string)($row['sender_last_digit'] ?? $row['sender_details'] ?? $row['last_digit'] ?? ''),
        'trxid' => (string)($row['trxid'] ?? ''),
        'status' => $status,
        'created_at' => (int)($row['created_at'] ?? 0),
        'success_at' => $isSuccess ? (int)($row['completed_at'] ?? $now) : 0,
        'completed_at' => $isSuccess ? (int)($row['completed_at'] ?? $now) : 0,
        'approved_by_uid' => $isSuccess ? (string)($row['completed_by_uid'] ?? '') : '',
        'approved_by_role' => $isSuccess ? (string)($row['completed_by_role'] ?? '') : '',
        'receipt_created_at' => $receiptCreatedAt,
        'updated_at' => $now,
    ];

    $saved = mfs_fb_put('MFS_RECEIPTS/' . $receiptId, $receipt);
    $indexed = mfs_fb_put('MFS_RECEIPT_INDEX/' . $token, [
        'receipt_id' => $receiptId,
        'request_id' => $requestId,
        'created_at' => $receiptCreatedAt,
        'updated_at' => $now,
    ]);

    if (!$saved || !$indexed) {
        return [
            'receipt_error' => 'Receipt save failed',
        ];
    }

    return [
        'receipt_id' => $receiptId,
        'receipt_token' => $token,
        'receipt_url' => $url,
        'receipt_created_at' => $receiptCreatedAt,
    ];
}

function mfs_create_receipt_for_tracking(string $requestId, array $row): array
{
    return mfs_save_receipt_for_request($requestId, $row, (string)($row['status'] ?? 'PENDING'));
}

function mfs_create_receipt_for_success(string $requestId, array $row): array
{
    return mfs_save_receipt_for_request($requestId, $row, 'SUCCESSFUL');
}

function mfs_public_receipt(array $receipt): array
{
    return [
        'receipt_id' => (string)($receipt['receipt_id'] ?? ''),
        'request_id' => (string)($receipt['request_id'] ?? ''),
        'title' => (string)($receipt['title'] ?? 'Z-Pay Swift Remittance Receipt'),
        'provider' => (string)($receipt['provider'] ?? ''),
        'provider_name' => (string)($receipt['provider_name'] ?? ''),
        'sender_name' => (string)($receipt['sender_name'] ?? ''),
        'sender_phone' => (string)($receipt['sender_phone'] ?? ''),
        'sender_role' => strtoupper((string)($receipt['sender_role'] ?? '')),
        'receiver_number' => (string)($receipt['receiver_number'] ?? ''),
        'country_code' => (string)($receipt['country_code'] ?? ''),
        'country' => (string)($receipt['country'] ?? ''),
        'mode' => (string)($receipt['mode'] ?? $receipt['service_mode'] ?? ''),
        'service_mode' => (string)($receipt['service_mode'] ?? $receipt['mode'] ?? ''),
        'amount_bdt' => (float)($receipt['amount_bdt'] ?? 0),
        'amount_myr' => (float)($receipt['amount_rm'] ?? $receipt['amount_myr'] ?? 0),
        'amount_rm' => (float)($receipt['amount_rm'] ?? $receipt['amount_myr'] ?? 0),
        'rate_myr_bdt' => (float)($receipt['rate_myr_to_bdt'] ?? $receipt['exchange_rate'] ?? 0),
        'rate_myr_to_bdt' => (float)($receipt['rate_myr_to_bdt'] ?? $receipt['exchange_rate'] ?? 0),
        'exchange_rate' => (float)($receipt['exchange_rate'] ?? $receipt['rate_myr_to_bdt'] ?? 0),
        'fee_amount' => (float)(strtoupper((string)($receipt['wallet_currency'] ?? 'BDT')) === 'MYR' ? ($receipt['fee_rm'] ?? 0) : ($receipt['fee_bdt'] ?? 0)),
        'fee_currency' => strtoupper((string)($receipt['wallet_currency'] ?? 'BDT')) === 'MYR' ? 'MYR' : 'BDT',
        'fee_bdt' => (float)($receipt['fee_bdt'] ?? 0),
        'fee_rm' => (float)($receipt['fee_rm'] ?? 0),
        'total_debit_bdt' => (float)($receipt['total_debit_bdt'] ?? 0),
        'total_debit_rm' => (float)($receipt['total_debit_rm'] ?? 0),
        'total_pay' => (float)($receipt['total_pay'] ?? 0),
        'total_debit' => (float)($receipt['total_pay'] ?? $receipt['total_debit'] ?? 0),
        'wallet_debit' => (float)($receipt['wallet_debit'] ?? $receipt['total_pay'] ?? $receipt['total_debit'] ?? 0),
        'total_pay_text' => (string)($receipt['total_pay_text'] ?? $receipt['total_debit_text'] ?? ''),
        'total_debit_text' => (string)($receipt['total_debit_text'] ?? $receipt['total_pay_text'] ?? ''),
        'wallet_debit_text' => (string)($receipt['wallet_debit_text'] ?? $receipt['total_debit_text'] ?? $receipt['total_pay_text'] ?? ''),
        'wallet_currency' => (string)($receipt['wallet_currency'] ?? ''),
        'reference' => (string)($receipt['reference'] ?? ''),
        'sender_details' => (string)($receipt['sender_details'] ?? ''),
        'sender_last_digit' => (string)($receipt['sender_last_digit'] ?? ''),
        'trxid' => (string)($receipt['trxid'] ?? ''),
        'status' => (string)($receipt['status'] ?? 'SUCCESSFUL'),
        'created_at' => (int)($receipt['created_at'] ?? 0),
        'success_at' => (int)($receipt['success_at'] ?? $receipt['completed_at'] ?? 0),
        'receipt_url' => (string)($receipt['receipt_url'] ?? ''),
    ];
}

function mfs_load_receipt_by_token(string $token): array
{
    $token = trim($token);

    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{24,128}$/', $token)) {
        return [];
    }

    $index = mfs_fb_get('MFS_RECEIPT_INDEX/' . $token);

    if (!is_array($index)) {
        return [];
    }

    $receiptId = trim((string)($index['receipt_id'] ?? ''));

    if ($receiptId === '') {
        return [];
    }

    $receipt = mfs_fb_get('MFS_RECEIPTS/' . $receiptId);

    if (!is_array($receipt)) {
        return [];
    }

    if (trim((string)($receipt['receipt_token'] ?? '')) !== $token) {
        return [];
    }

    return $receipt;
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
    $authMethod = strtoupper(trim((string)($body['auth_method'] ?? $body['verification_method'] ?? 'PIN')));
    $biometricVerified = in_array($authMethod, ['BIOMETRIC', 'FINGERPRINT'], true)
        && !empty($body['biometric_verified'])
        && !empty($actor['allow_biometric_validation'])
        && strtoupper(trim($source)) === 'USER_API';
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
    $skipPinValidation = $skipPinValidation || $biometricVerified;

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

    $previewSnapshot = is_array($actor['preview_data'] ?? null) ? (array)$actor['preview_data'] : [];
    $amounts = mfs_calculate_amounts($user, $wallet, $provider, $serviceType, $body);

    if (empty($amounts['ok']) && $previewSnapshot) {
        $currentWallet = mfs_country_wallet_check($user, $wallet);
        if (!empty($currentWallet['ok'])) {
            $snapshotCountry = mfs_normalize_country_code((string)($previewSnapshot['country_code'] ?? ''));
            $snapshotCurrency = mfs_normalize_currency((string)($previewSnapshot['wallet_currency'] ?? ''));
            if ($snapshotCountry === (string)$currentWallet['country_code']
                && $snapshotCurrency === (string)$currentWallet['wallet_currency']
            ) {
                $amounts = [
                    'ok' => true,
                    'country_code' => $snapshotCountry,
                    'wallet_currency' => $snapshotCurrency,
                    'service_mode' => (string)($previewSnapshot['service_mode'] ?? $previewSnapshot['mode'] ?? $currentWallet['service_mode'] ?? ''),
                    'exchange_rate' => mfs_round_money((float)($previewSnapshot['exchange_rate'] ?? 0)),
                    'amount_bdt' => mfs_round_money((float)($previewSnapshot['amount_bdt'] ?? 0)),
                    'amount_rm' => mfs_round_money((float)($previewSnapshot['amount_rm'] ?? $previewSnapshot['amount_myr'] ?? 0)),
                    'fee_bdt' => mfs_round_money((float)($previewSnapshot['fee_bdt'] ?? 0)),
                    'fee_rm' => mfs_round_money((float)($previewSnapshot['fee_rm'] ?? 0)),
                    'total_debit_bdt' => mfs_round_money((float)($previewSnapshot['total_debit_bdt'] ?? $previewSnapshot['total_pay_bdt'] ?? 0)),
                    'total_debit_rm' => mfs_round_money((float)($previewSnapshot['total_debit_rm'] ?? $previewSnapshot['total_pay_myr'] ?? 0)),
                    'total_debit' => mfs_round_money((float)($previewSnapshot['total_debit'] ?? $previewSnapshot['wallet_hold_amount'] ?? 0)),
                ];
            }
        }
    }

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

    if ($previewSnapshot) {
        $snapshotProvider = mfs_normalize_provider((string)($previewSnapshot['provider'] ?? ''));
        $snapshotService = mfs_normalize_service_type((string)($previewSnapshot['service_type'] ?? 'SEND_MONEY'));
        $snapshotAccount = mfs_normalize_account_type((string)($previewSnapshot['account_type'] ?? 'PERSONAL'), $snapshotService);
        $snapshotNumber = mfs_clean_mobile_number((string)($previewSnapshot['receiver_number'] ?? $previewSnapshot['number'] ?? ''));
        $snapshotCountry = mfs_normalize_country_code((string)($previewSnapshot['country_code'] ?? ''));
        $snapshotCurrency = mfs_normalize_currency((string)($previewSnapshot['wallet_currency'] ?? ''));

        if ($snapshotProvider !== $provider
            || $snapshotService !== $serviceType
            || $snapshotAccount !== $accountType
            || $snapshotNumber !== $receiverNumber
            || $snapshotCountry !== (string)$amounts['country_code']
            || $snapshotCurrency !== (string)$amounts['wallet_currency']
        ) {
            return [
                'ok' => false,
                'code' => 'MFS_PREVIEW_MISMATCH',
                'message' => 'MFS preview does not match this request. Please review again.',
                'data' => [],
            ];
        }

        $snapshotTotalDebit = mfs_round_money((float)($previewSnapshot['total_debit'] ?? $previewSnapshot['wallet_hold_amount'] ?? 0));
        $snapshotAmountBdt = mfs_round_money((float)($previewSnapshot['amount_bdt'] ?? 0));
        $snapshotAmountRm = mfs_round_money((float)($previewSnapshot['amount_rm'] ?? $previewSnapshot['amount_myr'] ?? 0));
        if ($snapshotTotalDebit <= 0 || $snapshotAmountBdt <= 0) {
            return [
                'ok' => false,
                'code' => 'MFS_PREVIEW_INVALID',
                'message' => 'MFS preview is invalid. Please review again.',
                'data' => [],
            ];
        }

        $amounts = array_replace($amounts, [
            'exchange_rate' => mfs_round_money((float)($previewSnapshot['exchange_rate'] ?? $amounts['exchange_rate'] ?? 0)),
            'amount_bdt' => $snapshotAmountBdt,
            'amount_rm' => $snapshotAmountRm,
            'fee_bdt' => mfs_round_money((float)($previewSnapshot['fee_bdt'] ?? 0)),
            'fee_rm' => mfs_round_money((float)($previewSnapshot['fee_rm'] ?? 0)),
            'total_debit_bdt' => mfs_round_money((float)($previewSnapshot['total_debit_bdt'] ?? $previewSnapshot['total_pay_bdt'] ?? $amounts['total_debit_bdt'] ?? 0)),
            'total_debit_rm' => mfs_round_money((float)($previewSnapshot['total_debit_rm'] ?? $previewSnapshot['total_pay_myr'] ?? $amounts['total_debit_rm'] ?? 0)),
            'total_debit' => $snapshotTotalDebit,
        ]);
    }

    $requestId = mfs_make_request_id();
    $now = mfs_now();
    $walletCurrency = (string)$amounts['wallet_currency'];
    $totalDebit = mfs_round_money((float)$amounts['total_debit']);
    $feeCurrency = $walletCurrency === 'MYR' ? 'MYR' : 'BDT';
    $feeAmount = $feeCurrency === 'MYR'
        ? mfs_round_money((float)$amounts['fee_rm'])
        : mfs_round_money((float)$amounts['fee_bdt']);
    $totalDebitText = mfs_money_text($totalDebit, $walletCurrency);
    $userRole = mfs_user_role($user);

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

    $balanceAfter = mfs_round_money((float)($hold['available_balance'] ?? $hold['after_available'] ?? 0));
    $balanceAfterText = mfs_money_text($balanceAfter, $walletCurrency);

    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => (string)($user['phone'] ?? ''),
        'user_name' => (string)($user['name'] ?? ''),
        'user_role' => $userRole,
        'role' => $userRole,

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
        'fee_currency' => $feeCurrency,
        'fee_amount' => $feeAmount,
        'total_debit_bdt' => (float)$amounts['total_debit_bdt'],
        'total_debit_rm' => (float)$amounts['total_debit_rm'],
        'total_debit' => $totalDebit,
        'total_pay' => $totalDebit,
        'wallet_debit' => $totalDebit,
        'total_debit_text' => $totalDebitText,
        'total_pay_text' => $totalDebitText,
        'wallet_debit_text' => $totalDebitText,
        'exchange_rate' => (float)$amounts['exchange_rate'],
        'balance_after' => $balanceAfter,
        'balance_after_text' => $balanceAfterText,

        'reference' => $reference,
        'trxid' => '',

        'status' => 'PENDING',
        'public_status' => 'PENDING',
        'process_status' => 'PENDING',
        'message' => $note !== '' ? $note : 'MFS request created',
        'final_message' => '',

        'request_pin_verified' => !$biometricVerified,
        'request_biometric_verified' => $biometricVerified,
        'request_auth_method' => $biometricVerified ? 'BIOMETRIC' : 'PIN',

        'source' => $source,
        'request_source' => $source,
        'key_id' => $sourceKeyId,
        'source_key_id' => $sourceKeyId,
        'preview_token_hash' => (string)($actor['preview_token_hash'] ?? ''),
        'preview_id' => (string)($previewSnapshot['preview_id'] ?? ''),
        'preview_created_at' => (int)($previewSnapshot['created_at'] ?? 0),
        'preview_expires_at' => (int)($previewSnapshot['expires_at'] ?? 0),

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

    $receipt = function_exists('mfs_create_receipt_for_tracking') ? mfs_create_receipt_for_tracking($requestId, $row) : [];
    if (!empty($receipt['receipt_id'])) {
        $row['receipt_id'] = (string)$receipt['receipt_id'];
        $row['receipt_token'] = (string)($receipt['receipt_token'] ?? '');
        $row['receipt_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['tracking_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['receipt_created_at'] = (int)($receipt['receipt_created_at'] ?? $now);

        mfs_fb_patch('MFS_REQUESTS/PENDING/' . $requestId, [
            'receipt_id' => $row['receipt_id'],
            'receipt_token' => $row['receipt_token'],
            'receipt_url' => $row['receipt_url'],
            'tracking_url' => $row['tracking_url'],
            'receipt_created_at' => $row['receipt_created_at'],
            'updated_at' => $now,
        ]);
    } elseif (!empty($receipt['receipt_error'])) {
        $row['receipt_error'] = (string)$receipt['receipt_error'];
        mfs_fb_patch('MFS_REQUESTS/PENDING/' . $requestId, [
            'receipt_error' => $row['receipt_error'],
            'updated_at' => $now,
        ]);
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

    $responseWallet = [
        'available_balance' => (float)($hold['available_balance'] ?? $hold['after_available'] ?? 0),
        'hold_balance' => (float)($hold['hold_balance'] ?? $hold['after_hold'] ?? 0),
        'currency' => $walletCurrency,
        'wallet_currency' => $walletCurrency,
    ];
    $responseWallet += mfs_wallet_display_payload($user, $responseWallet);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request created successfully',
        'data' => [
            'request_id' => $requestId,
            'uid' => $uid,
            'role' => $userRole,
            'user_role' => $userRole,
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
            'fee_currency' => $feeCurrency,
            'fee_amount' => $feeAmount,
            'total_debit_bdt' => (float)$amounts['total_debit_bdt'],
            'total_debit_rm' => (float)$amounts['total_debit_rm'],
            'total_debit' => $totalDebit,
            'total_pay' => $totalDebit,
            'wallet_debit' => $totalDebit,
            'total_debit_text' => $totalDebitText,
            'total_pay_text' => $totalDebitText,
            'wallet_debit_text' => $totalDebitText,
            'exchange_rate' => (float)$amounts['exchange_rate'],
            'balance_after' => $balanceAfter,
            'balance_after_text' => $balanceAfterText,

            'reference' => $reference,
            'trxid' => '',

            'created_at' => $now,
            'receipt_id' => (string)($row['receipt_id'] ?? ''),
            'receipt_token' => (string)($row['receipt_token'] ?? ''),
            'receipt_url' => (string)($row['receipt_url'] ?? ''),
            'tracking_url' => (string)($row['tracking_url'] ?? $row['receipt_url'] ?? ''),
            'receipt_created_at' => (int)($row['receipt_created_at'] ?? 0),
            'wallet' => $responseWallet,
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

    $receipt = mfs_save_receipt_for_request($requestId, $row, 'PROCESSING');
    if (!empty($receipt['receipt_id'])) {
        $row['receipt_id'] = (string)$receipt['receipt_id'];
        $row['receipt_token'] = (string)($receipt['receipt_token'] ?? '');
        $row['receipt_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['tracking_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['receipt_created_at'] = (int)($receipt['receipt_created_at'] ?? $now);
    } elseif (!empty($receipt['receipt_error'])) {
        $row['receipt_error'] = (string)$receipt['receipt_error'];
    }

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

    $receipt = mfs_create_receipt_for_success($requestId, $row);
    if (!empty($receipt['receipt_id'])) {
        $row['receipt_id'] = (string)$receipt['receipt_id'];
        $row['receipt_token'] = (string)($receipt['receipt_token'] ?? '');
        $row['receipt_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['tracking_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['receipt_created_at'] = (int)($receipt['receipt_created_at'] ?? $now);
    } elseif (!empty($receipt['receipt_error'])) {
        $row['receipt_error'] = (string)$receipt['receipt_error'];
    }

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
    mfs_record_user_notification($row, $requestId, 'SUCCESSFUL');

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request marked successful',
        'data' => [
            'request_id' => $requestId,
            'status' => 'SUCCESSFUL',
            'trxid' => $trxid,
            'completed_at' => $now,
            'receipt_id' => (string)($row['receipt_id'] ?? ''),
            'receipt_url' => (string)($row['receipt_url'] ?? ''),
            'tracking_url' => (string)($row['tracking_url'] ?? $row['receipt_url'] ?? ''),
            'receipt_created_at' => (int)($row['receipt_created_at'] ?? 0),
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

    $receipt = function_exists('mfs_save_receipt_for_request') ? mfs_save_receipt_for_request($requestId, $row, 'FAILED') : [];
    if (!empty($receipt['receipt_id'])) {
        $row['receipt_id'] = (string)$receipt['receipt_id'];
        $row['receipt_token'] = (string)($receipt['receipt_token'] ?? '');
        $row['receipt_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['tracking_url'] = (string)($receipt['receipt_url'] ?? '');
        $row['receipt_created_at'] = (int)($receipt['receipt_created_at'] ?? $now);
    } elseif (!empty($receipt['receipt_error'])) {
        $row['receipt_error'] = (string)$receipt['receipt_error'];
    }

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
    mfs_record_user_notification($row, $requestId, 'FAILED');

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS request marked failed',
        'data' => [
            'request_id' => $requestId,
            'status' => 'FAILED',
            'completed_at' => $now,
            'receipt_url' => (string)($row['receipt_url'] ?? ''),
            'tracking_url' => (string)($row['tracking_url'] ?? $row['receipt_url'] ?? ''),
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
