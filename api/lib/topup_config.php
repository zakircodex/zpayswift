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

function topup_default_operator(string $code, string $name): array
{
    return [
        'code' => normalize_operator($code),
        'name' => $name,
        'active' => true,
        'min_amount' => 20.0,
        'max_amount' => 1000.0,
        'quick_amounts' => [20, 50, 100, 200, 500, 1000],
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
                'operators' => [
                    topup_default_operator('GP', 'Grameenphone'),
                    topup_default_operator('ROBI', 'Robi'),
                    topup_default_operator('AIRTEL', 'Airtel'),
                    topup_default_operator('BANGLALINK', 'Banglalink'),
                    topup_default_operator('TELETALK', 'Teletalk'),
                ],
            ],
        ],
    ];
}

function topup_normalize_quick_amounts($value, float $min, float $max): array
{
    $source = is_array($value) ? $value : [20, 50, 100, 200, 500, 1000];
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

    return array_values($items ?: [20, 50, 100, 200, 500, 1000]);
}

function topup_normalize_operator_row(array $row, array $fallback = []): array
{
    $code = normalize_operator($row['code'] ?? $row['operator'] ?? $fallback['code'] ?? '');
    $name = topup_clean_text($row['name'] ?? $row['label'] ?? $fallback['name'] ?? $code, 80);
    $min = topup_money($row['min_amount'] ?? $fallback['min_amount'] ?? 20);
    $max = topup_money($row['max_amount'] ?? $fallback['max_amount'] ?? 1000);

    if ($min <= 0) {
        $min = 20.0;
    }
    if ($max <= 0 || $max < $min) {
        $max = 1000.0;
    }

    return [
        'code' => $code,
        'name' => $name !== '' ? $name : $code,
        'active' => topup_bool($row['active'] ?? $fallback['active'] ?? true, true),
        'min_amount' => $min,
        'max_amount' => $max,
        'quick_amounts' => topup_normalize_quick_amounts($row['quick_amounts'] ?? $fallback['quick_amounts'] ?? [], $min, $max),
    ];
}

function topup_config_payload(array $stored = []): array
{
    $defaults = topup_default_config();
    $storedCountries = $stored['countries'] ?? [];
    $countries = [];

    foreach ($defaults['countries'] as $defaultCountry) {
        $countryCode = topup_country_code($defaultCountry['code']);
        $storedCountry = [];

        if (is_array($storedCountries)) {
            foreach ($storedCountries as $key => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateCode = topup_country_code($candidate['code'] ?? $key);
                if ($candidateCode === $countryCode) {
                    $storedCountry = $candidate;
                    break;
                }
            }
        }

        $storedOps = is_array($storedCountry['operators'] ?? null) ? $storedCountry['operators'] : [];
        $operators = [];

        foreach ($defaultCountry['operators'] as $fallbackOperator) {
            $opCode = normalize_operator($fallbackOperator['code']);
            $storedOperator = [];
            foreach ($storedOps as $key => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateCode = normalize_operator($candidate['code'] ?? $candidate['operator'] ?? $key);
                if ($candidateCode === $opCode) {
                    $storedOperator = $candidate;
                    break;
                }
            }

            $runtime = get_operator_runtime($opCode);
            if (is_array($runtime)) {
                $storedOperator = array_merge($storedOperator, [
                    'active' => $runtime['active'] ?? ($storedOperator['active'] ?? true),
                    'name' => $runtime['name'] ?? $runtime['label'] ?? ($storedOperator['name'] ?? $fallbackOperator['name']),
                    'min_amount' => $runtime['min_amount'] ?? ($storedOperator['min_amount'] ?? $fallbackOperator['min_amount']),
                    'max_amount' => $runtime['max_amount'] ?? ($storedOperator['max_amount'] ?? $fallbackOperator['max_amount']),
                    'quick_amounts' => $runtime['quick_amounts'] ?? ($storedOperator['quick_amounts'] ?? $fallbackOperator['quick_amounts']),
                ]);
            }

            $operators[] = topup_normalize_operator_row($storedOperator, $fallbackOperator);
        }

        $countries[] = [
            'code' => $countryCode,
            'name' => topup_clean_text($storedCountry['name'] ?? $defaultCountry['name'], 80),
            'currency' => strtoupper(topup_clean_text($storedCountry['currency'] ?? $defaultCountry['currency'], 10)),
            'dial_code' => topup_clean_text($storedCountry['dial_code'] ?? $defaultCountry['dial_code'], 10),
            'operators' => $operators,
        ];
    }

    return [
        'countries' => $countries,
        'updated_at' => (int)($stored['updated_at'] ?? 0),
        'updated_by' => (string)($stored['updated_by'] ?? ''),
    ];
}

function topup_config(): array
{
    $stored = fb_get('TOPUP_CONFIG');
    return topup_config_payload(is_array($stored) ? $stored : []);
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

function topup_amount_validation(string $countryCode, string $operator, float $amount): array
{
    $config = topup_operator_config($countryCode, $operator);
    if (!$config) {
        return [
            'ok' => false,
            'code' => 'TOPUP_OPERATOR_INVALID',
            'message' => 'Selected operator is not available.',
            'http_status' => 422,
        ];
    }

    if (empty($config['active'])) {
        return [
            'ok' => false,
            'code' => 'TOPUP_OPERATOR_INACTIVE',
            'message' => 'This operator is currently inactive.',
            'http_status' => 422,
        ];
    }

    $min = topup_money($config['min_amount'] ?? 20);
    $max = topup_money($config['max_amount'] ?? 1000);

    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'TOPUP_AMOUNT_REQUIRED',
            'message' => 'Please enter top-up amount.',
            'http_status' => 422,
            'min_amount' => $min,
            'max_amount' => $max,
        ];
    }

    if ($amount < $min) {
        return [
            'ok' => false,
            'code' => 'TOPUP_AMOUNT_MIN',
            'message' => 'Minimum top-up amount is ' . topup_amount_text($min, 'BDT') . '.',
            'http_status' => 422,
            'min_amount' => $min,
            'max_amount' => $max,
        ];
    }

    if ($amount > $max) {
        return [
            'ok' => false,
            'code' => 'TOPUP_AMOUNT_MAX',
            'message' => 'Maximum top-up amount is ' . topup_amount_text($max, 'BDT') . '.',
            'http_status' => 422,
            'min_amount' => $min,
            'max_amount' => $max,
        ];
    }

    return [
        'ok' => true,
        'operator' => $config,
        'min_amount' => $min,
        'max_amount' => $max,
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
    $data['status'] = 'ACTIVE';

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
