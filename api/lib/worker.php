<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/subadmin_api.php';
require_once __DIR__ . '/topup_config.php';
require_once __DIR__ . '/notifications.php';

function worker_get_device(string $deviceId): ?array
{
    $row = fb_get('WORKER_DEVICES/' . $deviceId);
    return is_array($row) ? $row : null;
}

function worker_is_available(array $device): bool
{
    return (bool)($device['online'] ?? false)
        && (bool)($device['worker_enabled'] ?? false)
        && (bool)($device['accessibility_enabled'] ?? false);
}

function worker_get_active_slots(array $device): array
{
    $slots = $device['sim_slots'] ?? [];
    if (!is_array($slots)) {
        return [];
    }

    $result = [];
    foreach ($slots as $slotName => $slotData) {
        if (!is_array($slotData)) {
            continue;
        }

        if (!(bool)($slotData['active'] ?? false)) {
            continue;
        }

        $operator = normalize_operator($slotData['operator'] ?? '');
        if ($operator === '') {
            continue;
        }

        $result[$slotName] = [
            'operator' => $operator,
            'active' => true,
        ];
    }

    return $result;
}

function worker_find_matching_slot(array $device, string $operator): ?string
{
    $operator = normalize_operator($operator);
    $slots = worker_get_active_slots($device);

    foreach ($slots as $slotName => $slotData) {
        if (($slotData['operator'] ?? '') === $operator) {
            return (string)$slotName;
        }
    }

    return null;
}

/**
 * Heartbeat update:
 * - পুরো node overwrite করবে না
 * - existing value preserve করবে
 * - sim_slots only when provided
 * - fb_patch ব্যবহার করবে
 */
function worker_update_heartbeat(
    string $deviceId,
    ?string $deviceName,
    ?bool $workerEnabled,
    ?bool $accessibilityEnabled,
    ?string $appVersion,
    ?array $simSlots
): bool {
    $existing = worker_get_device($deviceId) ?? [];

    $patch = [
        'device_id' => $deviceId,
        'online' => true,
        'last_heartbeat_at' => now_ts(),
    ];

    if (!isset($existing['current_status'])) {
        $patch['current_status'] = 'IDLE';
    }

    if ($deviceName !== null && trim($deviceName) !== '') {
        $patch['device_name'] = trim($deviceName);
    } elseif (!isset($existing['device_name'])) {
        $patch['device_name'] = 'Worker Device';
    }

    if ($workerEnabled !== null) {
        $patch['worker_enabled'] = $workerEnabled;
    } elseif (!isset($existing['worker_enabled'])) {
        $patch['worker_enabled'] = true;
    }

    if ($accessibilityEnabled !== null) {
        $patch['accessibility_enabled'] = $accessibilityEnabled;
    } elseif (!isset($existing['accessibility_enabled'])) {
        $patch['accessibility_enabled'] = false;
    }

    if ($appVersion !== null && trim($appVersion) !== '') {
        $patch['app_version'] = trim($appVersion);
    } elseif (!isset($existing['app_version'])) {
        $patch['app_version'] = '1.0.0';
    }

    if (is_array($simSlots) && !empty($simSlots)) {
        $cleanSlots = [];
        foreach ($simSlots as $slotName => $slotData) {
            if (!is_array($slotData)) {
                continue;
            }

            $cleanSlots[$slotName] = [
                'operator' => normalize_operator($slotData['operator'] ?? ''),
                'active' => (bool)($slotData['active'] ?? false),
            ];
        }

        if (!empty($cleanSlots)) {
            $patch['sim_slots'] = $cleanSlots;
        }
    }

    return fb_patch('WORKER_DEVICES/' . $deviceId, $patch);
}

function worker_mark_status(string $deviceId, string $status): void
{
    fb_patch('WORKER_DEVICES/' . $deviceId, [
        'current_status' => $status,
        'last_heartbeat_at' => now_ts(),
    ]);
}

function worker_sync_api_request_status(string $uid, string $requestId, string $status, string $message): void
{
    if ($uid === '' || $requestId === '') {
        return;
    }

    $apiReq = fb_get('USER_API_REQUESTS/' . $uid . '/' . $requestId);
    if (!is_array($apiReq)) {
        return;
    }

    fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, [
        'status' => $status,
        'message' => $message,
        'updated_at' => now_ts(),
    ]);
}

function worker_claim_request(string $deviceId): ?array
{
    $device = worker_get_device($deviceId);
    if (!$device || !worker_is_available($device)) {
        return null;
    }

    $pending = fb_get('TOPUP_REQUESTS/PENDING');
    if (!is_array($pending) || empty($pending)) {
        return null;
    }

    foreach ($pending as $requestId => $request) {
        if (!is_array($request)) {
            continue;
        }

        $operator = normalize_operator($request['operator'] ?? '');
        if ($operator === '') {
            continue;
        }
        $countryCode = topup_country_code($request['country_code'] ?? 'BD');
        if (array_key_exists('worker_claimable', $request) && !topup_bool($request['worker_claimable'], true)) {
            continue;
        }
        if (function_exists('topup_operator_worker_claimable') && !topup_operator_worker_claimable($countryCode, $operator)) {
            continue;
        }

        $slot = worker_find_matching_slot($device, $operator);
        if ($slot === null) {
            continue;
        }

        $current = fb_get('TOPUP_REQUESTS/PENDING/' . $requestId);
        if (!is_array($current)) {
            continue;
        }

        $claimed = $current;
        $claimed['status'] = 'CLAIMED';
        $claimed['assigned_device_id'] = $deviceId;
        $claimed['assigned_slot'] = $slot;
        $claimed['claimed_at'] = now_ts();
        $claimed['updated_at'] = now_ts();

        $ok1 = fb_put('TOPUP_REQUESTS/CLAIMED/' . $requestId, $claimed);
        if (!$ok1) {
            continue;
        }

        $ok2 = fb_delete('TOPUP_REQUESTS/PENDING/' . $requestId);
        if (!$ok2) {
            fb_delete('TOPUP_REQUESTS/CLAIMED/' . $requestId);
            continue;
        }

        update_request_status($requestId, 'CLAIMED', 'Request claimed by worker');
        notification_emit_request_status_notification(
            'TOPUP',
            (string)$requestId,
            (string)($claimed['uid'] ?? ''),
            (string)($current['status'] ?? 'PENDING'),
            'CLAIMED',
            $claimed,
            'WORKER_CLAIM'
        );

        system_log('WORKER_CLAIM', $requestId, 'Request claimed by worker', [
            'device_id' => $deviceId,
            'slot' => $slot,
            'operator' => $operator,
        ]);

        $runtime = get_operator_runtime($operator);
        $private = get_operator_private_config($operator);

        if (!is_array($runtime) || !is_array($private)) {
            return null;
        }

        return [
            'request_id' => (string)$requestId,
            'uid' => (string)($claimed['uid'] ?? ''),
            'topup_number' => (string)($claimed['topup_number'] ?? ''),
            'operator' => $operator,
            'amount' => (float)($claimed['amount'] ?? 0),
            'assigned_slot' => $slot,
            'assigned_device_id' => $deviceId,
            'dial_template' => (string)($runtime['dial_template'] ?? ''),
            'retailer_secret_pin' => (string)($private['retailer_secret_pin'] ?? ''),
            'dial_preview_masked' => (string)($runtime['masked_template'] ?? ''),
        ];
    }

    return null;
}

function worker_mark_processing(string $requestId, string $deviceId, string $slot, string $dialPreview): bool
{
    $claimed = fb_get('TOPUP_REQUESTS/CLAIMED/' . $requestId);
    if (!is_array($claimed)) {
        return false;
    }

    $processing = $claimed;
    $processing['status'] = 'DIALING';
    $processing['assigned_device_id'] = $deviceId;
    $processing['assigned_slot'] = $slot;
    $processing['dial_code_preview'] = $dialPreview;
    $processing['started_at'] = now_ts();
    $processing['updated_at'] = now_ts();

    if (!fb_put('TOPUP_REQUESTS/PROCESSING/' . $requestId, $processing)) {
        return false;
    }

    if (!fb_delete('TOPUP_REQUESTS/CLAIMED/' . $requestId)) {
        return false;
    }

    update_request_status($requestId, 'DIALING', 'Dialing in progress');
    worker_mark_status($deviceId, 'BUSY');

    return true;
}

function worker_finalize_success(string $requestId, string $deviceId, string $resultMessage, string $rawResponse): array
{
    $processing = fb_get('TOPUP_REQUESTS/PROCESSING/' . $requestId);
    if (!is_array($processing)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Processing request not found',
        ];
    }

    if ((string)($processing['assigned_device_id'] ?? '') !== $deviceId) {
        return [
            'ok' => false,
            'code' => 'INVALID_RESULT',
            'message' => 'Request not assigned to this device',
        ];
    }

    $uid = (string)($processing['uid'] ?? '');
    $amount = (float)($processing['amount'] ?? 0);
    $walletDebitAmount = (float)(
        $processing['wallet_debit_amount']
        ?? $processing['wallet_hold_amount']
        ?? $processing['held_amount']
        ?? $processing['wallet_debit_bdt']
        ?? $amount
    );
    $resolvedHold = function_exists('subapi_topup_current_hold_amount')
        ? subapi_topup_current_hold_amount($processing)
        : ['amount' => $walletDebitAmount, 'wallet_currency' => (string)($processing['wallet_debit_currency'] ?? $processing['wallet_currency'] ?? 'BDT')];
    $walletDebitAmount = (float)($resolvedHold['amount'] ?? $walletDebitAmount);

    if (function_exists('subapi_is_topup_hold_request') && subapi_is_topup_hold_request($processing)) {
        if (!subapi_settle_topup_success($processing, $resultMessage)) {
            return [
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => 'Failed to settle held balance for API topup',
            ];
        }
    } else {
        $settle = wallet_settle_hold($uid, $walletDebitAmount, $requestId, 'TOPUP_SETTLE');
        if (!($settle['ok'] ?? false)) {
            return $settle;
        }
    }

    $done = $processing;
    $done['status'] = 'SUCCESS';
    $done['final_message'] = $resultMessage;
    $done['raw_response'] = $rawResponse;
    $done['completed_at'] = now_ts();
    $done['updated_at'] = now_ts();
    $done['request_source'] = (string)($done['request_source'] ?? $done['source'] ?? '');
    if (!empty($resolvedHold ?? [])) {
        $done['settled_hold_amount'] = (float)($resolvedHold['amount'] ?? 0);
        $done['settled_hold_currency'] = (string)($resolvedHold['wallet_currency'] ?? $done['wallet_debit_currency'] ?? $done['wallet_currency'] ?? 'BDT');
    }

    fb_put('TOPUP_REQUESTS/DONE/' . $requestId, $done);
    fb_delete('TOPUP_REQUESTS/PROCESSING/' . $requestId);

    if (function_exists('topup_write_history')) {
        topup_write_history($done);
    } else {
        fb_put('TOPUP_HISTORY/' . $uid . '/' . month_key() . '/' . $requestId, [
            'request_id' => $requestId,
            'topup_number' => (string)($done['topup_number'] ?? ''),
            'operator' => (string)($done['operator'] ?? ''),
            'amount' => $amount,
            'amount_bdt' => (float)($done['amount_bdt'] ?? $amount),
            'wallet_debit_bdt' => (float)($done['wallet_debit_bdt'] ?? $amount),
            'wallet_debit_amount' => $walletDebitAmount,
            'wallet_debit_currency' => (string)($done['wallet_debit_currency'] ?? $done['wallet_currency'] ?? 'BDT'),
            'settled_hold_amount' => (float)($done['settled_hold_amount'] ?? 0),
            'settled_hold_currency' => (string)($done['settled_hold_currency'] ?? ''),
            'rate_used' => (float)($done['rate_used'] ?? 0),
            'status' => 'SUCCESS',
            'message' => $resultMessage,
            'device_id' => $deviceId,
            'slot' => (string)($done['assigned_slot'] ?? ''),
            'created_at' => (int)($done['created_at'] ?? now_ts()),
            'completed_at' => (int)($done['completed_at'] ?? now_ts()),
            'request_source' => (string)($done['request_source'] ?? ''),
        ]);
    }

    update_request_status($requestId, 'SUCCESS', $resultMessage);
    worker_sync_api_request_status($uid, $requestId, 'SUCCESS', $resultMessage);
    notification_emit_request_status_notification(
        'TOPUP',
        $requestId,
        $uid,
        (string)($processing['status'] ?? 'PROCESSING'),
        'SUCCESS',
        $done,
        'WORKER_SUCCESS'
    );

    worker_mark_status($deviceId, 'IDLE');

    system_log('WORKER_RESULT_SUCCESS', $requestId, 'Topup completed successfully', [
        'device_id' => $deviceId,
        'message' => $resultMessage,
        'request_source' => (string)($processing['source'] ?? ''),
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Worker result saved',
    ];
}

function worker_finalize_failed(string $requestId, string $deviceId, string $resultMessage, string $rawResponse): array
{
    $processing = fb_get('TOPUP_REQUESTS/PROCESSING/' . $requestId);
    if (!is_array($processing)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'Processing request not found',
        ];
    }

    if ((string)($processing['assigned_device_id'] ?? '') !== $deviceId) {
        return [
            'ok' => false,
            'code' => 'INVALID_RESULT',
            'message' => 'Request not assigned to this device',
        ];
    }

    $uid = (string)($processing['uid'] ?? '');
    $amount = (float)($processing['amount'] ?? 0);
    $walletDebitAmount = (float)(
        $processing['wallet_debit_amount']
        ?? $processing['wallet_hold_amount']
        ?? $processing['held_amount']
        ?? $processing['wallet_debit_bdt']
        ?? $amount
    );
    $resolvedHold = function_exists('subapi_topup_current_hold_amount')
        ? subapi_topup_current_hold_amount($processing)
        : ['amount' => $walletDebitAmount, 'wallet_currency' => (string)($processing['wallet_debit_currency'] ?? $processing['wallet_currency'] ?? 'BDT')];
    $walletDebitAmount = (float)($resolvedHold['amount'] ?? $walletDebitAmount);

    if (function_exists('subapi_is_topup_hold_request') && subapi_is_topup_hold_request($processing)) {
        if (!subapi_settle_topup_failed($processing, $resultMessage)) {
            return [
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => 'Failed to release held balance for API topup',
            ];
        }
    } else {
        $refund = wallet_refund_hold($uid, $walletDebitAmount, $requestId, 'TOPUP_REFUND');
        if (!($refund['ok'] ?? false)) {
            return $refund;
        }
    }

    $done = $processing;
    $done['status'] = 'FAILED';
    $done['final_message'] = $resultMessage;
    $done['raw_response'] = $rawResponse;
    $done['completed_at'] = now_ts();
    $done['updated_at'] = now_ts();
    $done['request_source'] = (string)($done['request_source'] ?? $done['source'] ?? '');
    if (!empty($resolvedHold ?? [])) {
        $done['refund_amount'] = (float)($resolvedHold['amount'] ?? 0);
        $done['refund_currency'] = (string)($resolvedHold['wallet_currency'] ?? $done['wallet_debit_currency'] ?? $done['wallet_currency'] ?? 'BDT');
    }

    fb_put('TOPUP_REQUESTS/DONE/' . $requestId, $done);
    fb_delete('TOPUP_REQUESTS/PROCESSING/' . $requestId);

    if (function_exists('topup_write_history')) {
        topup_write_history($done);
    } else {
        fb_put('TOPUP_HISTORY/' . $uid . '/' . month_key() . '/' . $requestId, [
            'request_id' => $requestId,
            'topup_number' => (string)($done['topup_number'] ?? ''),
            'operator' => (string)($done['operator'] ?? ''),
            'amount' => $amount,
            'amount_bdt' => (float)($done['amount_bdt'] ?? $amount),
            'wallet_debit_bdt' => (float)($done['wallet_debit_bdt'] ?? $amount),
            'wallet_debit_amount' => $walletDebitAmount,
            'wallet_debit_currency' => (string)($done['wallet_debit_currency'] ?? $done['wallet_currency'] ?? 'BDT'),
            'refund_amount' => (float)($done['refund_amount'] ?? 0),
            'refund_currency' => (string)($done['refund_currency'] ?? ''),
            'rate_used' => (float)($done['rate_used'] ?? 0),
            'status' => 'FAILED',
            'message' => $resultMessage,
            'device_id' => $deviceId,
            'slot' => (string)($done['assigned_slot'] ?? ''),
            'created_at' => (int)($done['created_at'] ?? now_ts()),
            'completed_at' => (int)($done['completed_at'] ?? now_ts()),
            'request_source' => (string)($done['request_source'] ?? ''),
        ]);
    }

    update_request_status($requestId, 'FAILED', $resultMessage);
    worker_sync_api_request_status($uid, $requestId, 'FAILED', $resultMessage);
    notification_emit_request_status_notification(
        'TOPUP',
        $requestId,
        $uid,
        (string)($processing['status'] ?? 'PROCESSING'),
        'FAILED',
        $done,
        'WORKER_FAILED'
    );

    worker_mark_status($deviceId, 'IDLE');

    system_log('WORKER_RESULT_FAILED', $requestId, 'Topup failed and refunded', [
        'device_id' => $deviceId,
        'message' => $resultMessage,
        'request_source' => (string)($processing['source'] ?? ''),
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Worker result saved',
    ];
}
