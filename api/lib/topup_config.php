<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function topup_money($value): float
{
    return round(max(0, (float)$value), 2);
}

function topup_country_code($value): string
{
    $text = strtoupper(trim((string)$value));
    if (in_array($text, ['BD', 'BGD', 'BANGLADESH', '+880', '880'], true)) {
        return 'BD';
    }
    if (in_array($text, ['MY', 'MYS', 'MALAYSIA', '+60', '60'], true)) {
        return 'MY';
    }
    return $text;
}

function topup_effective_min_amount(string $countryCode, float $configuredMin): float
{
    return 20.0;
}

function topup_clean_text($value, int $max = 80): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $text) ?? '';
    return $max > 0 && strlen($text) > $max ? substr($text, 0, $max) : $text;
}

function topup_bool($value, bool $default = true): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    $text = strtoupper(trim((string)$value));
    if (in_array($text, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'FALSE', 'NO', 'OFF', 'INACTIVE', 'DISABLED'], true)) {
        return false;
    }
    return $default;
}

function topup_int($value, int $default = 0): int
{
    return is_numeric($value) ? (int)$value : $default;
}

function topup_service_type($value): string
{
    $text = strtoupper(trim((string)$value));
    return $text === 'PREPAID' ? 'PREPAID' : $text;
}

function topup_default_operator(
    string $countryCode,
    string $code,
    string $name,
    array $prefixes,
    int $sortOrder,
    bool $active = true,
    float $min = 20.0,
    float $max = 1000.0,
    array $quickAmounts = [20, 50, 100, 200, 500, 1000]
): array {
    $countryCode = topup_country_code($countryCode);
    return [
        'code' => normalize_operator($code),
        'name' => $name,
        'country_code' => $countryCode,
        'service_type' => 'PREPAID',
        'active' => $active,
        'min_amount' => $min,
        'max_amount' => $max,
        'quick_amounts' => $quickAmounts,
        'prefixes' => $prefixes,
        'sort_order' => $sortOrder,
    ];
}

function topup_default_config(): array
{
    return [
        'countries' => [
            [
                'code' => 'BD',
                'name' => 'Bangladesh',
                'currency' => 'BDT',
                'dial_code' => '+880',
                'active' => true,
                'sort_order' => 10,
                'operators' => [
                    topup_default_operator('BD', 'GP', 'Grameenphone', ['017', '013'], 10),
                    topup_default_operator('BD', 'ROBI', 'Robi', ['018'], 20),
                    topup_default_operator('BD', 'AIRTEL', 'Airtel', ['016'], 30),
                    topup_default_operator('BD', 'BL', 'Banglalink', ['019', '014'], 40),
                    topup_default_operator('BD', 'TT', 'Teletalk', ['015'], 50),
                    topup_default_operator('BD', 'SKITTO', 'Skitto', ['013'], 60),
                    topup_default_operator('BD', 'OTHER', 'Other Operator', [], 70),
                ],
            ],
            [
                'code' => 'MY',
                'name' => 'Malaysia',
                'currency' => 'BDT',
                'dial_code' => '+60',
                'active' => true,
                'sort_order' => 20,
                'operators' => [
                    topup_default_operator('MY', 'CELCOM_XPAX', 'Celcom Xpax', [], 10),
                    topup_default_operator('MY', 'DIGI', 'Digi', [], 20),
                    topup_default_operator('MY', 'HOTLINK', 'Hotlink', [], 30),
                    topup_default_operator('MY', 'MAXIS', 'Maxis', [], 40),
                    topup_default_operator('MY', 'UMOBILE', 'U Mobile', [], 50),
                    topup_default_operator('MY', 'XOX', 'XOX', [], 60),
                    topup_default_operator('MY', 'TUNETALK', 'Tune Talk', [], 70),
                    topup_default_operator('MY', 'YES', 'YES Prepaid', [], 80),
                ],
            ],
        ],
    ];
}

function topup_array_rows($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $rows = [];
    foreach ($value as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!array_key_exists('code', $row) && is_string($key)) {
            $row['code'] = $key;
        }
        $rows[] = $row;
    }
    return $rows;
}

function topup_normalize_quick_amounts($value, float $min, float $max): array
{
    if (is_string($value)) {
        $source = preg_split('/[,\s]+/', $value) ?: [];
    } else {
        $source = is_array($value) ? $value : [20, 50, 100, 200, 500, 1000];
    }

    $items = [];
    foreach ($source as $amount) {
        if (!is_numeric($amount)) {
            continue;
        }
        $money = topup_money($amount);
        if ($money <= 0) {
            continue;
        }
        if ($min > 0 && $money < $min) {
            continue;
        }
        if ($max > 0 && $money > $max) {
            continue;
        }
        $items[(string)$money] = fmod($money, 1.0) === 0.0 ? (int)$money : $money;
    }

    if (!$items) {
        foreach ([20, 50, 100, 200, 500, 1000] as $amount) {
            $money = topup_money($amount);
            if ($min > 0 && $money < $min) {
                continue;
            }
            if ($max > 0 && $money > $max) {
                continue;
            }
            $items[(string)$money] = fmod($money, 1.0) === 0.0 ? (int)$money : $money;
        }
    }
    if (!$items && $min > 0) {
        $items[(string)$min] = fmod($min, 1.0) === 0.0 ? (int)$min : $min;
    }

    $values = array_values($items);
    usort($values, static fn($a, $b): int => (float)$a <=> (float)$b);
    return $values;
}

function topup_normalize_prefixes($value): array
{
    if (is_string($value)) {
        $source = preg_split('/[,\s]+/', $value) ?: [];
    } else {
        $source = is_array($value) ? $value : [];
    }

    $items = [];
    foreach ($source as $prefix) {
        $digits = preg_replace('/\D+/', '', (string)$prefix) ?? '';
        if ($digits === '' || strlen($digits) > 6) {
            continue;
        }
        $items[$digits] = $digits;
    }
    return array_values($items);
}

function topup_find_country_row(array $rows, string $countryCode): array
{
    foreach (topup_array_rows($rows) as $row) {
        if (topup_country_code($row['code'] ?? $row['country_code'] ?? '') === $countryCode) {
            return $row;
        }
    }
    return [];
}

function topup_find_operator_row(array $rows, string $operator): array
{
    $operator = normalize_operator($operator);
    foreach (topup_array_rows($rows) as $row) {
        if (normalize_operator($row['code'] ?? $row['operator'] ?? '') === $operator) {
            return $row;
        }
    }
    return [];
}

function topup_runtime_public_overrides(string $operator): array
{
    $operator = normalize_operator($operator);
    $runtime = topup_runtime_row($operator);
    if (!is_array($runtime)) {
        return [];
    }

    $allowed = ['active', 'name', 'min_amount', 'max_amount', 'quick_amounts', 'prefixes', 'sort_order', 'service_type'];
    $out = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $runtime)) {
            $out[$field] = $runtime[$field];
        }
    }
    return $out;
}

function topup_runtime_rows(): array
{
    static $loaded = false;
    static $rows = [];

    if (!$loaded) {
        $raw = fb_get('OPERATOR_RUNTIME');
        $rows = is_array($raw) ? $raw : [];
        $loaded = true;
    }

    return $rows;
}

function topup_runtime_row(string $operator): ?array
{
    $operator = normalize_operator($operator);
    if ($operator === '') {
        return null;
    }

    $rows = topup_runtime_rows();
    $row = $rows[$operator] ?? null;

    if (!is_array($row)) {
        foreach ($rows as $key => $candidate) {
            if (normalize_operator($key) === $operator && is_array($candidate)) {
                $row = $candidate;
                break;
            }
        }
    }

    if (!is_array($row)) {
        return null;
    }

    $row['code'] = $operator;
    return $row;
}

function topup_normalize_operator_row(array $row, array $fallback = [], string $countryCode = ''): array
{
    $result = $row;
    $code = normalize_operator($row['code'] ?? $row['operator'] ?? $fallback['code'] ?? '');
    $countryCode = topup_country_code($row['country_code'] ?? $row['country'] ?? $countryCode ?: ($fallback['country_code'] ?? 'BD'));
    $name = topup_clean_text($row['name'] ?? $row['label'] ?? $fallback['name'] ?? $code, 80);
    if ($countryCode === 'MY' && $code === 'HOTLINK' && strtoupper($name) === 'MAXIS HOTLINK') {
        $name = 'Hotlink';
    } elseif ($countryCode === 'MY' && $code === 'MAXIS' && ($name === '' || strtoupper($name) === 'MAXIS_HOTLINK')) {
        $name = 'Maxis';
    }
    $serviceType = topup_service_type($row['service_type'] ?? $fallback['service_type'] ?? 'PREPAID');
    $min = topup_money($row['min_amount'] ?? $fallback['min_amount'] ?? 20);
    $max = topup_money($row['max_amount'] ?? $fallback['max_amount'] ?? 1000);

    if ($min <= 0) {
        $min = (float)($fallback['min_amount'] ?? 20);
    }
    $min = topup_effective_min_amount($countryCode, $min);
    if ($max <= 0 || $max < $min) {
        $max = (float)($fallback['max_amount'] ?? max(1000, $min));
        $max = max($min, $max);
    }

    $result['code'] = $code;
    $result['name'] = $name !== '' ? $name : $code;
    $result['country_code'] = $countryCode;
    $result['service_type'] = $serviceType;
    $result['active'] = topup_bool($row['active'] ?? $fallback['active'] ?? true, true);
    $result['min_amount'] = $min;
    $result['max_amount'] = $max;
    $result['quick_amounts'] = topup_normalize_quick_amounts($row['quick_amounts'] ?? $fallback['quick_amounts'] ?? [], $min, $max);
    $result['prefixes'] = topup_normalize_prefixes($row['prefixes'] ?? $fallback['prefixes'] ?? []);
    $result['sort_order'] = topup_int($row['sort_order'] ?? $fallback['sort_order'] ?? 999, 999);

    return $result;
}

function topup_normalize_country_row(array $row, array $fallback = [], bool $mergeRuntime = true): array
{
    $countryCode = topup_country_code($row['code'] ?? $row['country_code'] ?? $fallback['code'] ?? '');
    $result = $row;
    unset($result['operators']);

    $result['code'] = $countryCode;
    $result['name'] = topup_clean_text($row['name'] ?? $fallback['name'] ?? $countryCode, 80);
    $result['currency'] = 'BDT';
    $result['dial_code'] = topup_clean_text($row['dial_code'] ?? $fallback['dial_code'] ?? ($countryCode === 'MY' ? '+60' : '+880'), 10);
    $result['active'] = topup_bool($row['active'] ?? $fallback['active'] ?? true, true);
    $result['sort_order'] = topup_int($row['sort_order'] ?? $fallback['sort_order'] ?? 999, 999);

    $storedOperators = topup_array_rows($row['operators'] ?? []);
    $operators = [];
    foreach (topup_array_rows($fallback['operators'] ?? []) as $fallbackOperator) {
        $opCode = normalize_operator($fallbackOperator['code'] ?? '');
        $storedOperator = topup_find_operator_row($storedOperators, $opCode);

        if ($mergeRuntime) {
            $runtime = topup_runtime_public_overrides($opCode);
            if ($runtime !== []) {
                $storedOperator = array_merge($storedOperator, $runtime);
            }
        }

        $operators[] = topup_normalize_operator_row($storedOperator, $fallbackOperator, $countryCode);
    }

    usort($operators, static function (array $a, array $b): int {
        $order = (int)($a['sort_order'] ?? 999) <=> (int)($b['sort_order'] ?? 999);
        return $order !== 0 ? $order : strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $result['operators'] = $operators;
    return $result;
}

function topup_config_payload(array $stored = [], bool $mergeRuntime = true): array
{
    $defaults = topup_default_config();
    $storedCountries = topup_array_rows($stored['countries'] ?? []);
    $countries = [];

    foreach (topup_array_rows($defaults['countries'] ?? []) as $defaultCountry) {
        $countryCode = topup_country_code($defaultCountry['code'] ?? '');
        $storedCountry = topup_find_country_row($storedCountries, $countryCode);
        $countries[] = topup_normalize_country_row($storedCountry, $defaultCountry, $mergeRuntime);
    }

    usort($countries, static function (array $a, array $b): int {
        $order = (int)($a['sort_order'] ?? 999) <=> (int)($b['sort_order'] ?? 999);
        return $order !== 0 ? $order : strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $payload = $stored;
    $payload['countries'] = $countries;
    $payload['updated_at'] = (int)($stored['updated_at'] ?? 0);
    $payload['updated_by'] = (string)($stored['updated_by'] ?? '');
    $payload['updated_by_role'] = (string)($stored['updated_by_role'] ?? '');
    return $payload;
}

function topup_app_config(): array
{
    $row = topup_app_config_row();
    return [
        'topup_enabled' => topup_bool($row['topup_enabled'] ?? true, true),
        'maintenance_mode' => topup_bool($row['maintenance_mode'] ?? false, false),
    ];
}

function topup_app_config_row(): array
{
    static $loaded = false;
    static $row = [];

    if (!$loaded) {
        $raw = fb_get('APP_CONFIG');
        $row = is_array($raw) ? $raw : [];
        $loaded = true;
    }

    return $row;
}

function topup_config(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $stored = fb_get('TOPUP_CONFIG');
    $payload = topup_config_payload(is_array($stored) ? $stored : [], true);
    $app = topup_app_config();
    $cache = [
        'topup_enabled' => $app['topup_enabled'],
        'maintenance_mode' => $app['maintenance_mode'],
        'countries' => $payload['countries'],
        'updated_at' => $payload['updated_at'],
        'updated_by' => $payload['updated_by'],
        'updated_by_role' => $payload['updated_by_role'],
    ];

    return $cache;
}

function topup_country_config(string $countryCode): ?array
{
    $countryCode = topup_country_code($countryCode);
    foreach (topup_config()['countries'] as $country) {
        if ((string)($country['code'] ?? '') === $countryCode) {
            return $country;
        }
    }
    return null;
}

function topup_operator_config(string $countryCode, string $operator): ?array
{
    $country = topup_country_config($countryCode);
    if (!$country) {
        return null;
    }

    $operator = normalize_operator($operator);
    foreach ((array)($country['operators'] ?? []) as $row) {
        if (normalize_operator($row['code'] ?? '') === $operator) {
            return $row;
        }
    }

    return null;
}

function topup_operator_worker_dial_ready(string $countryCode, string $operator): bool
{
    $countryCode = topup_country_code($countryCode);
    $operator = normalize_operator($operator);
    return $countryCode === 'BD' && in_array($operator, ['GP', 'ROBI', 'AIRTEL', 'BL', 'TT'], true);
}

function topup_operator_ready_for_submit(string $countryCode, string $operator): bool
{
    $countryCode = topup_country_code($countryCode);
    $operator = normalize_operator($operator);
    if ($countryCode === 'BD') {
        return in_array($operator, ['GP', 'ROBI', 'AIRTEL', 'BL', 'TT', 'SKITTO', 'OTHER'], true);
    }
    if ($countryCode === 'MY') {
        return in_array($operator, ['CELCOM_XPAX', 'DIGI', 'HOTLINK', 'MAXIS', 'UMOBILE', 'XOX', 'TUNETALK', 'YES'], true);
    }
    return false;
}

function topup_operator_execution_mode(string $countryCode, string $operator): string
{
    return topup_operator_worker_dial_ready($countryCode, $operator) ? 'WORKER_USSD' : 'TELEGRAM_MANUAL';
}

function topup_operator_worker_claimable(string $countryCode, string $operator): bool
{
    return topup_operator_worker_dial_ready($countryCode, $operator);
}

function topup_validation_error(string $code, string $message, array $data = [], int $httpStatus = 422): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'http_status' => $httpStatus,
    ];
}

function topup_global_validation(): array
{
    $app = topup_app_config();
    if (empty($app['topup_enabled'])) {
        return topup_validation_error('TOPUP_DISABLED', 'Top-up service is currently disabled.');
    }
    if (!empty($app['maintenance_mode'])) {
        return topup_validation_error('TOPUP_MAINTENANCE', 'Top-up service is under maintenance.');
    }
    return ['ok' => true];
}

function topup_country_validation(string $countryCode, bool $requireActive = true): array
{
    $country = topup_country_config($countryCode);
    if (!$country) {
        return topup_validation_error('TOPUP_COUNTRY_UNSUPPORTED', 'Selected country is not supported.');
    }
    if ($requireActive && empty($country['active'])) {
        return topup_validation_error('TOPUP_COUNTRY_DISABLED', 'Top-up is currently disabled for this country.');
    }
    return ['ok' => true, 'country' => $country];
}

function topup_operator_validation(string $countryCode, string $operator, bool $requireActive = true, bool $requireReady = true): array
{
    $countryResult = topup_country_validation($countryCode, $requireActive);
    if (empty($countryResult['ok'])) {
        return $countryResult;
    }

    $operatorConfig = topup_operator_config($countryCode, $operator);
    if (!$operatorConfig) {
        return topup_validation_error('TOPUP_OPERATOR_UNSUPPORTED', 'Selected operator is not supported.');
    }

    if (topup_service_type($operatorConfig['service_type'] ?? '') !== 'PREPAID') {
        return topup_validation_error('TOPUP_SERVICE_TYPE_UNSUPPORTED', 'Only prepaid top-up is supported.');
    }

    if ($requireActive && empty($operatorConfig['active'])) {
        return topup_validation_error('TOPUP_OPERATOR_DISABLED', 'This operator is currently disabled.');
    }

    if ($requireReady && !topup_operator_ready_for_submit($countryCode, (string)$operatorConfig['code'])) {
        $code = topup_country_code($countryCode) === 'MY' ? 'TOPUP_COUNTRY_NOT_READY' : 'TOPUP_OPERATOR_NOT_READY';
        return topup_validation_error($code, 'This operator is not ready for live top-up yet.');
    }

    return [
        'ok' => true,
        'country' => (array)($countryResult['country'] ?? []),
        'operator' => $operatorConfig,
    ];
}

function topup_amount_validation(string $countryCode, string $operator, float $amount): array
{
    $countryCode = topup_country_code($countryCode);
    $operatorResult = topup_operator_validation($countryCode, $operator, true, false);
    if (empty($operatorResult['ok'])) {
        $operatorResult['min_amount'] = topup_effective_min_amount($countryCode, 20.0);
        $operatorResult['max_amount'] = 1000;
        return $operatorResult;
    }

    $config = (array)$operatorResult['operator'];
    $min = topup_effective_min_amount($countryCode, topup_money($config['min_amount'] ?? 20));
    $max = topup_money($config['max_amount'] ?? 1000);
    $currency = 'BDT';

    if ($amount <= 0) {
        $error = topup_validation_error('TOPUP_AMOUNT_REQUIRED', 'Please enter top-up amount.', [
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
        $error['min_amount'] = $min;
        $error['max_amount'] = $max;
        return $error;
    }

    if ($amount < $min) {
        $error = topup_validation_error('TOPUP_AMOUNT_MIN', 'Minimum top-up amount is ' . topup_amount_text($min, $currency) . '.', [
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
        $error['min_amount'] = $min;
        $error['max_amount'] = $max;
        return $error;
    }

    if ($amount > $max) {
        $error = topup_validation_error('TOPUP_AMOUNT_MAX', 'Maximum top-up amount is ' . topup_amount_text($max, $currency) . '.', [
            'min_amount' => $min,
            'max_amount' => $max,
        ]);
        $error['min_amount'] = $min;
        $error['max_amount'] = $max;
        return $error;
    }

    return [
        'ok' => true,
        'country' => (array)($operatorResult['country'] ?? []),
        'operator' => $config,
        'min_amount' => $min,
        'max_amount' => $max,
    ];
}

function topup_validate_request(string $countryCode, string $operator, float $amount, bool $checkGlobal = true, bool $requireReady = true): array
{
    if ($checkGlobal) {
        $global = topup_global_validation();
        if (empty($global['ok'])) {
            return $global;
        }
    }

    $operatorResult = topup_operator_validation($countryCode, $operator, true, $requireReady);
    if (empty($operatorResult['ok'])) {
        return $operatorResult;
    }

    $amountResult = topup_amount_validation($countryCode, $operator, $amount);
    if (empty($amountResult['ok'])) {
        return $amountResult;
    }

    $amountResult['country'] = (array)($operatorResult['country'] ?? []);
    $amountResult['operator'] = (array)($operatorResult['operator'] ?? []);
    return $amountResult;
}

function topup_api_error(array $error): void
{
    api_response(
        false,
        (string)($error['code'] ?? 'TOPUP_ERROR'),
        (string)($error['message'] ?? 'Top-up request failed.'),
        (array)($error['data'] ?? []),
        (int)($error['http_status'] ?? 422)
    );
}

function topup_normalize_number_for_country(string $countryCode, $number): string
{
    $countryCode = topup_country_code($countryCode);
    if ($countryCode === 'BD') {
        return normalize_bd_topup_number((string)$number);
    }
    return preg_replace('/\D+/', '', (string)$number) ?? '';
}

function topup_is_valid_number_for_country(string $countryCode, string $number): bool
{
    $countryCode = topup_country_code($countryCode);
    if ($countryCode === 'BD') {
        return is_valid_bd_topup_number($number);
    }
    if ($countryCode === 'MY') {
        return preg_match('/^(?:0?1\d{7,9}|60?1\d{7,9})$/', $number) === 1;
    }
    return false;
}

function topup_suggest_operator_by_number(string $countryCode, string $number): array
{
    $countryCode = topup_country_code($countryCode);
    if ($countryCode !== 'BD') {
        return ['suggested_operator' => '', 'ambiguous' => false, 'candidates' => []];
    }

    $number = normalize_bd_topup_number($number);
    $matches = [];
    foreach ((array)(topup_country_config('BD')['operators'] ?? []) as $operator) {
        foreach ((array)($operator['prefixes'] ?? []) as $prefix) {
            if ($prefix !== '' && str_starts_with($number, (string)$prefix)) {
                $matches[] = (string)($operator['code'] ?? '');
                break;
            }
        }
    }
    $matches = array_values(array_unique(array_filter($matches)));

    return [
        'suggested_operator' => count($matches) === 1 ? $matches[0] : '',
        'ambiguous' => count($matches) > 1,
        'candidates' => $matches,
    ];
}

function topup_amount_text(float $amount, string $currency): string
{
    $currency = strtoupper(trim($currency));
    if ($currency === 'BDT') {
        return number_format($amount, 0, '.', '') . ' BDT';
    }
    if ($currency === 'MYR' || $currency === 'RM') {
        return 'RM ' . number_format($amount, 2, '.', '');
    }
    return number_format($amount, 2, '.', '') . ' ' . $currency;
}

function topup_mask_number(string $number): string
{
    $digits = preg_replace('/\D+/', '', trim($number)) ?? '';
    if (strlen($digits) <= 5) {
        return $digits;
    }
    return substr($digits, 0, 3) . str_repeat('*', max(3, strlen($digits) - 6)) . substr($digits, -3);
}

function topup_preview_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function topup_create_preview_token(array $data): string
{
    $token = random_token(32);
    $hash = topup_preview_token_hash($token);
    $now = now_ts();
    $data['preview_token_hash'] = $hash;
    $data['created_at'] = $now;
    $data['expires_at'] = (int)($data['expires_at'] ?? ($now + 300));
    $data['used'] = false;
    $data['used_at'] = 0;
    $data['status'] = 'READY';

    if (!fb_put('TOPUP_PREVIEWS/' . $hash, $data)) {
        return '';
    }

    return $token;
}

function topup_load_preview_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $hash = topup_preview_token_hash($token);
    $row = fb_get('TOPUP_PREVIEWS/' . $hash);
    if (!is_array($row)) {
        return null;
    }

    $row['_token_hash'] = $hash;
    return $row;
}

function topup_claim_preview_token(string $tokenHash, string $uid): array
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return topup_validation_error('TOPUP_PREVIEW_INVALID', 'Top-up preview is invalid. Please preview again.');
    }

    $path = 'TOPUP_PREVIEWS/' . $tokenHash;
    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || !is_array($res['value'] ?? null) || empty($res['etag'])) {
            return topup_validation_error('TOPUP_PREVIEW_INVALID', 'Top-up preview is invalid. Please preview again.');
        }

        $row = $res['value'];
        $status = strtoupper((string)($row['status'] ?? 'READY'));

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
            return topup_validation_error('TOPUP_ALREADY_SUBMITTED', 'This top-up preview was already submitted.');
        }
        if ($status === 'PROCESSING') {
            return topup_validation_error('TOPUP_ALREADY_SUBMITTED', 'This top-up request is already being submitted.');
        }
        if ((int)($row['expires_at'] ?? 0) < now_ts()) {
            @fb_patch($path, [
                'status' => 'EXPIRED',
                'updated_at' => now_ts(),
            ]);
            return topup_validation_error('TOPUP_PREVIEW_EXPIRED', 'Top-up preview expired. Please preview again.');
        }
        if ((string)($row['uid'] ?? '') !== $uid) {
            return topup_validation_error('TOPUP_PREVIEW_INVALID', 'Top-up preview does not belong to this account.', [], 403);
        }
        if (!in_array($status, ['READY', 'ACTIVE', 'FAILED'], true)) {
            return topup_validation_error('TOPUP_ALREADY_SUBMITTED', 'This top-up request is already being submitted.');
        }

        $claimed = $row;
        $claimed['status'] = 'PROCESSING';
        $claimed['processing_at'] = now_ts();
        $claimed['updated_at'] = now_ts();

        $save = fb_put_if_match($path, $claimed, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }
        if (!($save['ok'] ?? false)) {
            return topup_validation_error('TOPUP_PREVIEW_CLAIM_FAILED', 'Top-up preview could not be locked. Please try again.', [], 409);
        }

        $claimed['_token_hash'] = $tokenHash;
        return ['ok' => true, 'preview' => $claimed];
    }

    return topup_validation_error('TOPUP_ALREADY_SUBMITTED', 'This top-up request is already being submitted.', [], 409);
}

function topup_mark_preview_used(string $tokenHash, string $requestId): void
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return;
    }

    @fb_patch('TOPUP_PREVIEWS/' . $tokenHash, [
        'used' => true,
        'used_at' => now_ts(),
        'status' => 'USED',
        'request_id' => $requestId,
        'updated_at' => now_ts(),
    ]);
}

function topup_mark_preview_failed(string $tokenHash, string $code, string $message = ''): void
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return;
    }

    @fb_patch('TOPUP_PREVIEWS/' . $tokenHash, [
        'status' => 'FAILED',
        'failed_code' => topup_clean_text($code, 80),
        'failed_message' => topup_clean_text($message, 160),
        'updated_at' => now_ts(),
    ]);
}
