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

function worker_claim_lease_seconds(): int
{
    $seconds = defined('WORKER_CLAIM_LEASE_SECONDS') ? (int)WORKER_CLAIM_LEASE_SECONDS : 180;
    return max(60, min(900, $seconds));
}

function worker_request_is_z_builder(array $request): bool
{
    return !empty($request['test_mode'])
        || trim((string)($request['z_builder_owner_id'] ?? $request['tenant_owner_id'] ?? '')) !== ''
        || strtoupper(trim((string)($request['request_source'] ?? $request['source'] ?? ''))) === 'Z_BUILDER_TEST';
}

function worker_claim_pending_request(
    string $requestId,
    string $deviceId,
    string $slot,
    string $scope = 'MAIN',
    string $ownerId = ''
): ?array {
    $requestId = trim($requestId);
    $deviceId = trim($deviceId);
    $scope = strtoupper(trim($scope));
    $ownerId = trim($ownerId);
    if ($requestId === '' || $deviceId === '' || $slot === '') {
        return null;
    }

    $path = 'TOPUP_REQUESTS/PENDING/' . $requestId;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || !is_array($snapshot['value'] ?? null)) {
            return null;
        }

        $current = (array)$snapshot['value'];
        $isBuilder = worker_request_is_z_builder($current);
        if (($scope === 'Z_BUILDER') !== $isBuilder) {
            return null;
        }
        if ($scope === 'Z_BUILDER') {
            $requestOwner = trim((string)($current['z_builder_owner_id'] ?? $current['tenant_owner_id'] ?? ''));
            if ($ownerId === '' || $requestOwner !== $ownerId) {
                return null;
            }
        }

        if (is_array(fb_get('TOPUP_REQUESTS/PROCESSING/' . $requestId))) {
            return null;
        }
        $existingClaimed = fb_get('TOPUP_REQUESTS/CLAIMED/' . $requestId);
        if (is_array($existingClaimed)) {
            return null;
        }

        $now = now_ts();
        $status = strtoupper(trim((string)($current['status'] ?? 'PENDING')));
        $leaseExpiresAt = (int)($current['worker_claim_lease_expires_at'] ?? 0);
        if (in_array($status, ['CLAIMING', 'CLAIMED'], true) && $leaseExpiresAt > $now) {
            return null;
        }
        if ($status !== 'PENDING' && !in_array($status, ['CLAIMING', 'CLAIMED'], true)) {
            return null;
        }

        $ownerTokenHash = hash('sha256', random_bytes(32));
        $claimed = $current;
        $claimed['status'] = 'CLAIMED';
        $claimed['assigned_device_id'] = $deviceId;
        $claimed['assigned_slot'] = $slot;
        $claimed['worker_claim_scope'] = $scope;
        $claimed['worker_claim_owner_hash'] = $ownerTokenHash;
        $claimed['worker_claim_lease_expires_at'] = $now + worker_claim_lease_seconds();
        $claimed['worker_claim_attempt_count'] = max(0, (int)($current['worker_claim_attempt_count'] ?? 0)) + 1;
        $claimed['claimed_at'] = $now;
        $claimed['updated_at'] = $now;

        $claimWrite = fb_put_if_match($path, $claimed, (string)$snapshot['etag']);
        if ((int)($claimWrite['status'] ?? 0) === 412) {
            continue;
        }
        if (empty($claimWrite['ok'])) {
            return null;
        }

        $claimedPath = 'TOPUP_REQUESTS/CLAIMED/' . $requestId;
        $claimedSnapshot = fb_get_with_etag($claimedPath);
        if (empty($claimedSnapshot['ok']) || !is_string($claimedSnapshot['etag'] ?? null)) {
            return null;
        }
        $claimedValue = $claimedSnapshot['value'] ?? null;
        if ($claimedValue !== null) {
            return null;
        }
        $copy = fb_put_if_match($claimedPath, $claimed, (string)$claimedSnapshot['etag']);
        if (empty($copy['ok'])) {
            return null;
        }

        $pendingSnapshot = fb_get_with_etag($path);
        $pendingValue = is_array($pendingSnapshot['value'] ?? null) ? (array)$pendingSnapshot['value'] : [];
        if (!empty($pendingSnapshot['ok'])
            && is_string($pendingSnapshot['etag'] ?? null)
            && hash_equals($ownerTokenHash, (string)($pendingValue['worker_claim_owner_hash'] ?? ''))) {
            @fb_delete_if_match($path, (string)$pendingSnapshot['etag']);
        }

        return $claimed;
    }

    return null;
}

function worker_reclaim_stale_request(string $deviceId, array $device, string $scope = 'MAIN', string $ownerId = ''): ?array
{
    $scope = strtoupper(trim($scope));
    $claimedRows = fb_get('TOPUP_REQUESTS/CLAIMED');
    if (!is_array($claimedRows)) {
        return null;
    }

    foreach ($claimedRows as $requestId => $request) {
        if (!is_array($request) || is_array(fb_get('TOPUP_REQUESTS/PROCESSING/' . $requestId))) {
            continue;
        }
        $isBuilder = worker_request_is_z_builder($request);
        if (($scope === 'Z_BUILDER') !== $isBuilder) {
            continue;
        }
        if ($scope === 'Z_BUILDER') {
            $requestOwner = trim((string)($request['z_builder_owner_id'] ?? $request['tenant_owner_id'] ?? ''));
            if ($ownerId === '' || $requestOwner !== $ownerId) {
                continue;
            }
        }
        if ((int)($request['worker_claim_lease_expires_at'] ?? 0) > now_ts()) {
            continue;
        }

        $operator = normalize_operator($request['operator'] ?? '');
        $slot = $operator !== '' ? worker_find_matching_slot($device, $operator) : null;
        if ($slot === null) {
            continue;
        }

        $path = 'TOPUP_REQUESTS/CLAIMED/' . $requestId;
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || !is_array($snapshot['value'] ?? null)) {
            continue;
        }
        $current = (array)$snapshot['value'];
        if ((int)($current['worker_claim_lease_expires_at'] ?? 0) > now_ts()) {
            continue;
        }

        $now = now_ts();
        $current['assigned_device_id'] = $deviceId;
        $current['assigned_slot'] = $slot;
        $current['worker_claim_scope'] = $scope;
        $current['worker_claim_owner_hash'] = hash('sha256', random_bytes(32));
        $current['worker_claim_lease_expires_at'] = $now + worker_claim_lease_seconds();
        $current['worker_claim_attempt_count'] = max(0, (int)($current['worker_claim_attempt_count'] ?? 0)) + 1;
        $current['request_id'] = (string)($current['request_id'] ?? $requestId);
        $current['claimed_at'] = $now;
        $current['updated_at'] = $now;

        $takeover = fb_put_if_match($path, $current, (string)$snapshot['etag']);
        if (!empty($takeover['ok'])) {
            return $current;
        }
    }

    return null;
}

function worker_claim_payload(array $claimed): ?array
{
    $operator = normalize_operator($claimed['operator'] ?? '');
    $runtime = $operator !== '' ? get_operator_runtime($operator) : null;
    $private = $operator !== '' ? get_operator_private_config($operator) : null;
    if (!is_array($runtime) || !is_array($private)) {
        return null;
    }

    return [
        'request_id' => (string)($claimed['request_id'] ?? ''),
        'uid' => (string)($claimed['uid'] ?? ''),
        'topup_number' => (string)($claimed['topup_number'] ?? ''),
        'operator' => $operator,
        'amount' => (float)($claimed['topup_amount_bdt'] ?? $claimed['amount_bdt'] ?? $claimed['amount'] ?? 0),
        'assigned_slot' => (string)($claimed['assigned_slot'] ?? ''),
        'assigned_device_id' => (string)($claimed['assigned_device_id'] ?? ''),
        'dial_template' => (string)($runtime['dial_template'] ?? ''),
        'retailer_secret_pin' => (string)($private['retailer_secret_pin'] ?? ''),
        'dial_preview_masked' => (string)($runtime['masked_template'] ?? ''),
    ];
}

function worker_claim_request(string $deviceId): ?array
{
    $device = worker_get_device($deviceId);
    if (!$device || !worker_is_available($device)) {
        return null;
    }

    $stale = worker_reclaim_stale_request($deviceId, $device, 'MAIN');
    if (is_array($stale)) {
        $stale['request_id'] = (string)($stale['request_id'] ?? '');
        return worker_claim_payload($stale);
    }

    $pending = fb_get('TOPUP_REQUESTS/PENDING');
    if (!is_array($pending) || empty($pending)) {
        return null;
    }

    foreach ($pending as $requestId => $request) {
        if (!is_array($request)) {
            continue;
        }

        if (worker_request_is_z_builder($request)) {
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

        $claimed = worker_claim_pending_request((string)$requestId, $deviceId, $slot, 'MAIN');
        if (!is_array($claimed)) {
            continue;
        }

        update_request_status($requestId, 'CLAIMED', 'Request claimed by worker');
        notification_emit_request_status_notification(
            'TOPUP',
            (string)$requestId,
            (string)($claimed['uid'] ?? ''),
            (string)($request['status'] ?? 'PENDING'),
            'CLAIMED',
            $claimed,
            'WORKER_CLAIM'
        );

        system_log('WORKER_CLAIM', $requestId, 'Request claimed by worker', [
            'device_id' => $deviceId,
            'slot' => $slot,
            'operator' => $operator,
        ]);

        $claimed['request_id'] = (string)$requestId;
        return worker_claim_payload($claimed);
    }

    return null;
}

function worker_mark_processing(string $requestId, string $deviceId, string $slot, string $dialPreview): bool
{
    $processingPath = 'TOPUP_REQUESTS/PROCESSING/' . $requestId;
    $existingProcessing = fb_get($processingPath);
    if (is_array($existingProcessing)) {
        return hash_equals((string)($existingProcessing['assigned_device_id'] ?? ''), $deviceId);
    }

    $claimedPath = 'TOPUP_REQUESTS/CLAIMED/' . $requestId;
    $snapshot = fb_get_with_etag($claimedPath);
    $claimed = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || $claimed === []) {
        return false;
    }

    if (!hash_equals((string)($claimed['assigned_device_id'] ?? ''), $deviceId)) {
        return false;
    }

    $processing = $claimed;
    $processing['status'] = 'DIALING';
    $processing['assigned_device_id'] = $deviceId;
    $processing['assigned_slot'] = $slot;
    $processing['dial_code_preview'] = $dialPreview;
    $processing['started_at'] = now_ts();
    $processing['updated_at'] = now_ts();

    $processingSnapshot = fb_get_with_etag($processingPath);
    if (empty($processingSnapshot['ok']) || !is_string($processingSnapshot['etag'] ?? null)) {
        return false;
    }
    if (($processingSnapshot['value'] ?? null) !== null) {
        $row = is_array($processingSnapshot['value']) ? (array)$processingSnapshot['value'] : [];
        return hash_equals((string)($row['assigned_device_id'] ?? ''), $deviceId);
    }
    $processingWrite = fb_put_if_match($processingPath, $processing, (string)$processingSnapshot['etag']);
    if (empty($processingWrite['ok'])) {
        return false;
    }

    $latestClaim = fb_get_with_etag($claimedPath);
    $latestValue = is_array($latestClaim['value'] ?? null) ? (array)$latestClaim['value'] : [];
    if (!empty($latestClaim['ok'])
        && is_string($latestClaim['etag'] ?? null)
        && hash_equals((string)($latestValue['assigned_device_id'] ?? ''), $deviceId)
        && hash_equals(
            (string)($latestValue['worker_claim_owner_hash'] ?? ''),
            (string)($claimed['worker_claim_owner_hash'] ?? '')
        )) {
        @fb_delete_if_match($claimedPath, (string)$latestClaim['etag']);
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
    $walletCurrency = (string)($resolvedHold['wallet_currency'] ?? $processing['wallet_debit_currency'] ?? $processing['wallet_currency'] ?? 'BDT');
    $operation = function_exists('wallet_financial_operation_begin')
        ? wallet_financial_operation_begin($requestId, 'TOPUP_SUCCESS', 'REQUEST_FINAL', $uid, $walletDebitAmount, $walletCurrency, [
            'request_type' => 'TOPUP',
            'source' => 'WORKER',
            'device_id' => $deviceId,
        ])
        : ['ok' => true, 'claim' => []];
    if (empty($operation['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_FAILED'),
            'message' => (string)($operation['message'] ?? 'Topup financial operation is already being processed'),
        ];
    }
    if (!empty($operation['duplicate'])) {
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Worker result already saved',
        ];
    }
    $financialClaim = (array)($operation['claim'] ?? []);

    if (function_exists('subapi_is_topup_hold_request') && subapi_is_topup_hold_request($processing)) {
        if (!subapi_settle_topup_success($processing, $resultMessage, $financialClaim)) {
            if (function_exists('wallet_financial_operation_mark_failed')) {
                wallet_financial_operation_mark_failed($financialClaim, 'SERVER_ERROR', 'Failed to settle held balance for API topup');
            }
            return [
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => 'Failed to settle held balance for API topup',
            ];
        }
    } else {
        $settle = wallet_settle_hold($uid, $walletDebitAmount, $requestId, 'TOPUP_SETTLE', [
            'financial_operation' => $financialClaim,
        ]);
        if (!($settle['ok'] ?? false)) {
            if (function_exists('wallet_financial_operation_mark_failed')) {
                wallet_financial_operation_mark_failed($financialClaim, (string)($settle['code'] ?? 'WALLET_SETTLE_FAILED'), (string)($settle['message'] ?? 'Wallet settle failed'));
            }
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

    if (!fb_put('TOPUP_REQUESTS/DONE/' . $requestId, $done)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to move worker request to done bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to done bucket',
        ];
    }
    if (!fb_delete('TOPUP_REQUESTS/PROCESSING/' . $requestId)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to remove processing request bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to done bucket',
        ];
    }

    $historyWritten = false;
    if (function_exists('topup_write_history')) {
        $historyWritten = topup_write_history($done);
    } else {
        $historyWritten = fb_put('TOPUP_HISTORY/' . $uid . '/' . month_key() . '/' . $requestId, [
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

    if (function_exists('wallet_financial_operation_mark_applied')) {
        wallet_financial_operation_mark_applied($financialClaim, [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_finalized' => true,
            'final_status' => 'SUCCESS',
            'completed_bucket' => 'DONE',
            'source' => 'WORKER',
        ]);
    }

    update_request_status($requestId, 'SUCCESS', $resultMessage);
    worker_sync_api_request_status($uid, $requestId, 'SUCCESS', $resultMessage);
    $notification = notification_emit_request_status_notification(
        'TOPUP',
        $requestId,
        $uid,
        (string)($processing['status'] ?? 'PROCESSING'),
        'SUCCESS',
        $done,
        'WORKER_SUCCESS'
    );
    $notificationWritten = !empty($notification['ok']) || !empty($notification['notification_id']);

    worker_mark_status($deviceId, 'IDLE');

    system_log('WORKER_RESULT_SUCCESS', $requestId, 'Topup completed successfully', [
        'device_id' => $deviceId,
        'message' => $resultMessage,
        'request_source' => (string)($processing['source'] ?? ''),
    ]);
    if (function_exists('wallet_financial_operation_mark_completed')) {
        wallet_financial_operation_mark_completed($financialClaim, [
            'final_status' => 'SUCCESS',
            'completed_bucket' => 'DONE',
            'source' => 'WORKER',
            'request_finalized' => true,
            'history_written' => $historyWritten,
            'notification_written' => $notificationWritten,
        ]);
    }

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
    $walletCurrency = (string)($resolvedHold['wallet_currency'] ?? $processing['wallet_debit_currency'] ?? $processing['wallet_currency'] ?? 'BDT');
    $operation = function_exists('wallet_financial_operation_begin')
        ? wallet_financial_operation_begin($requestId, 'TOPUP_REFUND', 'REQUEST_FINAL', $uid, $walletDebitAmount, $walletCurrency, [
            'request_type' => 'TOPUP',
            'source' => 'WORKER',
            'device_id' => $deviceId,
        ])
        : ['ok' => true, 'claim' => []];
    if (empty($operation['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($operation['code'] ?? 'FINANCIAL_OPERATION_FAILED'),
            'message' => (string)($operation['message'] ?? 'Topup financial operation is already being processed'),
        ];
    }
    if (!empty($operation['duplicate'])) {
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Worker result already saved',
        ];
    }
    $financialClaim = (array)($operation['claim'] ?? []);

    if (function_exists('subapi_is_topup_hold_request') && subapi_is_topup_hold_request($processing)) {
        if (!subapi_settle_topup_failed($processing, $resultMessage, $financialClaim)) {
            if (function_exists('wallet_financial_operation_mark_failed')) {
                wallet_financial_operation_mark_failed($financialClaim, 'SERVER_ERROR', 'Failed to release held balance for API topup');
            }
            return [
                'ok' => false,
                'code' => 'SERVER_ERROR',
                'message' => 'Failed to release held balance for API topup',
            ];
        }
    } else {
        $refund = wallet_refund_hold($uid, $walletDebitAmount, $requestId, 'TOPUP_REFUND', [
            'financial_operation' => $financialClaim,
        ]);
        if (!($refund['ok'] ?? false)) {
            if (function_exists('wallet_financial_operation_mark_failed')) {
                wallet_financial_operation_mark_failed($financialClaim, (string)($refund['code'] ?? 'WALLET_REFUND_FAILED'), (string)($refund['message'] ?? 'Wallet refund failed'));
            }
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

    if (!fb_put('TOPUP_REQUESTS/DONE/' . $requestId, $done)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to move worker request to done bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to done bucket',
        ];
    }
    if (!fb_delete('TOPUP_REQUESTS/PROCESSING/' . $requestId)) {
        if (function_exists('wallet_financial_operation_mark_failed')) {
            wallet_financial_operation_mark_failed($financialClaim, 'REQUEST_FINALIZATION_FAILED', 'Failed to remove processing request bucket', [
                'wallet_applied' => true,
                'ledger_written' => true,
                'request_finalized' => false,
            ]);
        }
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move request to done bucket',
        ];
    }

    $historyWritten = false;
    if (function_exists('topup_write_history')) {
        $historyWritten = topup_write_history($done);
    } else {
        $historyWritten = fb_put('TOPUP_HISTORY/' . $uid . '/' . month_key() . '/' . $requestId, [
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

    if (function_exists('wallet_financial_operation_mark_applied')) {
        wallet_financial_operation_mark_applied($financialClaim, [
            'wallet_applied' => true,
            'ledger_written' => true,
            'request_finalized' => true,
            'final_status' => 'FAILED',
            'completed_bucket' => 'DONE',
            'source' => 'WORKER',
        ]);
    }

    update_request_status($requestId, 'FAILED', $resultMessage);
    worker_sync_api_request_status($uid, $requestId, 'FAILED', $resultMessage);
    $notification = notification_emit_request_status_notification(
        'TOPUP',
        $requestId,
        $uid,
        (string)($processing['status'] ?? 'PROCESSING'),
        'FAILED',
        $done,
        'WORKER_FAILED'
    );
    $notificationWritten = !empty($notification['ok']) || !empty($notification['notification_id']);

    worker_mark_status($deviceId, 'IDLE');

    system_log('WORKER_RESULT_FAILED', $requestId, 'Topup failed and refunded', [
        'device_id' => $deviceId,
        'message' => $resultMessage,
        'request_source' => (string)($processing['source'] ?? ''),
    ]);
    if (function_exists('wallet_financial_operation_mark_completed')) {
        wallet_financial_operation_mark_completed($financialClaim, [
            'final_status' => 'FAILED',
            'completed_bucket' => 'DONE',
            'source' => 'WORKER',
            'request_finalized' => true,
            'history_written' => $historyWritten,
            'notification_written' => $notificationWritten,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Worker result saved',
    ];
}
