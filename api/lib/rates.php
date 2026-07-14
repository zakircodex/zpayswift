<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function zpay_rate_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function zpay_rate_round(float $rate): float
{
    return round($rate, 2);
}

function zpay_rate_log_id(): string
{
    if (function_exists('make_log_id')) {
        return (string)make_log_id();
    }

    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return 'RATE_' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function zpay_myr_to_bdt_rate(bool $includeConfigConstant = true): float
{
    $paths = [
        'MFS_SETTINGS/rate_myr_bdt',
        'MFS_SETTINGS/myr_to_bdt_rate',
        'MFS_SETTINGS/rates/myr_to_bdt',
        'MFS_CONFIG/myr_to_bdt_rate',
        'MFS_CONFIG/rates/myr_to_bdt',
        'MFS_CONFIG/RATE/MYR_TO_BDT',
        'MFS_CONFIG/RATES/MYR_TO_BDT',
        'APP_CONFIG/MYR_TO_BDT_RATE',
        'APP_CONFIG/RINGGIT_RATE',
    ];

    foreach ($paths as $path) {
        $value = fb_get($path);

        if (is_numeric($value) && (float)$value > 0) {
            return zpay_rate_round((float)$value);
        }

        if (is_array($value)) {
            foreach (['rate', 'value', 'amount', 'bdt'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key]) && (float)$value[$key] > 0) {
                    return zpay_rate_round((float)$value[$key]);
                }
            }
        }
    }

    if ($includeConfigConstant && defined('MYR_TO_BDT_RATE') && (float)MYR_TO_BDT_RATE > 0) {
        return zpay_rate_round((float)MYR_TO_BDT_RATE);
    }

    return 0.0;
}

function zpay_validate_myr_to_bdt_rate(float $rate): array
{
    if ($rate <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_RATE',
            'message' => 'Exchange rate must be greater than zero',
        ];
    }

    if ($rate < 20 || $rate > 50) {
        return [
            'ok' => false,
            'code' => 'INVALID_RATE_RANGE',
            'message' => 'Exchange rate must be between 20 and 50',
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Exchange rate valid',
    ];
}

function zpay_save_myr_to_bdt_rate(float $rate, string $changedBy = '', string $source = 'ADMIN'): array
{
    $rate = zpay_rate_round($rate);
    $valid = zpay_validate_myr_to_bdt_rate($rate);

    if (empty($valid['ok'])) {
        return $valid;
    }

    $oldRate = zpay_myr_to_bdt_rate(true);
    $now = zpay_rate_now();
    $changedBy = trim($changedBy);
    $source = strtoupper(trim($source)) ?: 'ADMIN';

    $settingsPatch = [
        'rate_myr_bdt' => $rate,
        'myr_to_bdt_rate' => $rate,
        'updated_at' => $now,
        'updated_by_uid' => $changedBy,
        'updated_by_role' => $source,
    ];

    if (!fb_patch('MFS_SETTINGS', $settingsPatch)) {
        return [
            'ok' => false,
            'code' => 'RATE_SAVE_FAILED',
            'message' => 'Failed to save Ringgit rate',
        ];
    }

    fb_put('MFS_SETTINGS/rates/myr_to_bdt', $rate);
    fb_put('MFS_CONFIG/myr_to_bdt_rate', $rate);
    fb_put('MFS_CONFIG/rates/myr_to_bdt', $rate);
    fb_patch('MFS_CONFIG', ['updated_at' => $now]);
    fb_put('APP_CONFIG/MYR_TO_BDT_RATE', $rate);
    fb_put('APP_CONFIG/RINGGIT_RATE', $rate);
    fb_patch('APP_CONFIG', ['updated_at' => $now]);

    $logId = zpay_rate_log_id();
    $logRow = [
        'log_id' => $logId,
        'old_rate' => $oldRate,
        'new_rate' => $rate,
        'changed_by' => $changedBy,
        'changed_at' => $now,
        'source' => $source,
    ];
    if (!fb_put('RATE_CHANGE_LOG/' . $logId, $logRow) && function_exists('system_log')) {
        system_log('RATE_CHANGE_LOG_WARNING', $logId, 'Failed to write Ringgit rate change log', $logRow);
    }

    if (function_exists('mfs_config')) {
        mfs_config(true);
    }

    $notificationResult = [];
    $notificationLib = __DIR__ . '/notifications.php';
    if (is_file($notificationLib)) {
        require_once $notificationLib;
    }
    if (function_exists('notification_broadcast_rate_update')) {
        $notificationResult = notification_broadcast_rate_update($rate, $logId, $changedBy, $source);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Ringgit rate updated',
        'data' => [
            'old_rate' => $oldRate,
            'new_rate' => $rate,
            'rate' => $rate,
            'rate_path' => 'MFS_SETTINGS/rate_myr_bdt',
            'log_id' => $logId,
            'notification' => $notificationResult,
        ],
    ];
}
