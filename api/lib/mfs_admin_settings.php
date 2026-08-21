<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function mfs_admin_settings_float($value, float $default = 0.0): float
{
    if (is_string($value)) {
        $value = trim(str_replace(',', '', $value));
    }

    return is_numeric($value) ? round((float)$value, 2) : $default;
}

function mfs_admin_settings_role_value(array $row, string $role)
{
    $value = $row[strtoupper(trim($role))] ?? null;

    if (is_array($value)) {
        $value = $value['fee_rm'] ?? $value['fixed'] ?? $value['amount'] ?? $value['rm'] ?? null;
    }

    return $value;
}

function mfs_admin_settings_role_fee(array $row, string $role, float $default): float
{
    return max(0.0, mfs_admin_settings_float(mfs_admin_settings_role_value($row, $role), $default));
}

function mfs_admin_settings_fee_input_row(array $body, string $country, string $provider): array
{
    $country = strtoupper(trim($country));
    $provider = strtoupper(trim($provider));
    $key = strtolower($country . '_' . $provider);

    return is_array($body[$country][$provider] ?? null)
        ? (array)$body[$country][$provider]
        : (is_array($body[$key] ?? null) ? (array)$body[$key] : []);
}

function mfs_admin_settings_validate_number($value, string $field): array
{
    if (is_string($value)) {
        $value = trim(str_replace(',', '', $value));
    }

    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_FEE',
            'message' => 'Fee values must be valid numbers equal to or greater than zero',
            'data' => ['field' => $field],
        ];
    }

    return ['ok' => true];
}

function mfs_admin_settings_validate_fee_row(array $row, string $country, string $provider): array
{
    $country = strtoupper(trim($country));
    $provider = strtoupper(trim($provider));
    $fieldPrefix = 'fees.' . $country . '.' . $provider . '.';

    if ($country === 'MY') {
        foreach (['fixed', 'fee_rm', 'amount'] as $key) {
            if (array_key_exists($key, $row)) {
                $valid = mfs_admin_settings_validate_number($row[$key], $fieldPrefix . $key);
                if (empty($valid['ok'])) {
                    return $valid;
                }
            }
        }

        foreach (['USER', 'RETAILER', 'SUBADMIN', 'ADMIN'] as $role) {
            if (!array_key_exists($role, $row)) {
                continue;
            }
            $valid = mfs_admin_settings_validate_number(
                mfs_admin_settings_role_value($row, $role),
                $fieldPrefix . $role
            );
            if (empty($valid['ok'])) {
                return $valid;
            }
        }

        return ['ok' => true];
    }

    if (array_key_exists('type', $row)) {
        $type = strtolower(trim((string)$row['type']));
        if (!in_array($type, ['fixed', 'percent'], true)) {
            return [
                'ok' => false,
                'code' => 'INVALID_FEE_TYPE',
                'message' => 'Fee type must be fixed or percent',
                'data' => ['field' => $fieldPrefix . 'type'],
            ];
        }
    }

    foreach (['fixed', 'fixed_fee', 'percent', 'percent_fee', 'min_fee', 'max_fee'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $valid = mfs_admin_settings_validate_number($row[$key], $fieldPrefix . $key);
        if (empty($valid['ok'])) {
            return $valid;
        }
    }

    return ['ok' => true];
}

function mfs_admin_settings_fee_row(array $body, string $country, string $provider): array
{
    $country = strtoupper(trim($country));
    $provider = strtoupper(trim($provider));
    $row = mfs_admin_settings_fee_input_row($body, $country, $provider);

    if ($country === 'MY') {
        $legacy = mfs_admin_settings_float($row['fixed'] ?? $row['fee_rm'] ?? $row['amount'] ?? -1.0, -1.0);
        $userFee = mfs_admin_settings_role_fee($row, 'USER', $legacy >= 0 ? $legacy : 5.00);

        return [
            'type' => 'fixed',
            'fixed' => $userFee,
            'fee_rm' => $userFee,
            'USER' => $userFee,
            'RETAILER' => mfs_admin_settings_role_fee($row, 'RETAILER', 2.00),
            'SUBADMIN' => mfs_admin_settings_role_fee($row, 'SUBADMIN', 2.00),
            'ADMIN' => mfs_admin_settings_role_fee($row, 'ADMIN', 0.00),
        ];
    }

    $type = strtolower(trim((string)($row['type'] ?? 'fixed')));

    return [
        'type' => in_array($type, ['fixed', 'percent'], true) ? $type : 'fixed',
        'fixed' => max(0.0, mfs_admin_settings_float($row['fixed'] ?? $row['fixed_fee'] ?? 0.0)),
        'percent' => max(0.0, mfs_admin_settings_float($row['percent'] ?? $row['percent_fee'] ?? 0.0)),
        'min_fee' => max(0.0, mfs_admin_settings_float($row['min_fee'] ?? 0.0)),
        'max_fee' => max(0.0, mfs_admin_settings_float($row['max_fee'] ?? 0.0)),
    ];
}

function mfs_admin_settings_build_fees(array $body): array
{
    $feesBody = is_array($body['fees'] ?? null) ? (array)$body['fees'] : $body;

    foreach (['MY', 'BD'] as $country) {
        foreach (['BKASH', 'NAGAD'] as $provider) {
            $valid = mfs_admin_settings_validate_fee_row(
                mfs_admin_settings_fee_input_row($feesBody, $country, $provider),
                $country,
                $provider
            );
            if (empty($valid['ok'])) {
                return $valid;
            }
        }
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'fees' => [
            'MY' => [
                'BKASH' => mfs_admin_settings_fee_row($feesBody, 'MY', 'BKASH'),
                'NAGAD' => mfs_admin_settings_fee_row($feesBody, 'MY', 'NAGAD'),
            ],
            'BD' => [
                'BKASH' => mfs_admin_settings_fee_row($feesBody, 'BD', 'BKASH'),
                'NAGAD' => mfs_admin_settings_fee_row($feesBody, 'BD', 'NAGAD'),
            ],
        ],
    ];
}

function mfs_admin_rate_state(): array
{
    $settings = fb_get('MFS_SETTINGS');
    $settings = is_array($settings) ? $settings : [];

    return [
        'rate_myr_bdt' => function_exists('zpay_myr_to_bdt_rate') ? zpay_myr_to_bdt_rate(true) : 0.0,
        'updated_at' => max(0, (int)($settings['updated_at'] ?? 0)),
        'updated_source' => trim((string)($settings['updated_by_role'] ?? '')),
    ];
}

function mfs_admin_fee_state(): array
{
    if (function_exists('mfs_public_settings')) {
        $settings = mfs_public_settings();
        return is_array($settings['fees'] ?? null) ? (array)$settings['fees'] : [];
    }

    $fees = fb_get('MFS_SETTINGS/fees');
    return is_array($fees) ? $fees : [];
}

function mfs_admin_save_rate(float $rate, string $changedBy): array
{
    if (!function_exists('zpay_save_myr_to_bdt_rate')) {
        return ['ok' => false, 'code' => 'RATE_SAVE_FAILED', 'message' => 'Failed to save Ringgit rate'];
    }

    $result = zpay_save_myr_to_bdt_rate($rate, trim($changedBy), 'ADMIN_PANEL');
    if (empty($result['ok'])) {
        return $result;
    }

    $result['data'] = array_merge((array)($result['data'] ?? []), [
        'rate_state' => mfs_admin_rate_state(),
    ]);
    return $result;
}

function mfs_admin_save_fees(array $body): array
{
    $built = mfs_admin_settings_build_fees($body);
    if (empty($built['ok'])) {
        return $built;
    }

    if (!fb_patch('MFS_SETTINGS', ['fees' => (array)$built['fees']])) {
        return ['ok' => false, 'code' => 'FEE_SAVE_FAILED', 'message' => 'Failed to save MFS fee settings'];
    }

    if (function_exists('mfs_config')) {
        mfs_config(true);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'MFS fee settings saved',
        'data' => ['fees' => mfs_admin_fee_state()],
    ];
}
