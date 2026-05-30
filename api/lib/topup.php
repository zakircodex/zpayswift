<?php
declare(strict_types=1);

require_once __DIR__ . '/subadmin_api.php';

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

function create_topup_pending_request(
    string $requestId,
    string $uid,
    string $userPhone,
    string $topupNumber,
    string $operator,
    float $amount
): bool {
    $row = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => $userPhone,
        'topup_number' => $topupNumber,
        'operator' => $operator,
        'amount' => $amount,
        'request_pin_verified' => true,
        'wallet_hold_amount' => $amount,
        'status' => 'PENDING',
        'assigned_device_id' => '',
        'assigned_slot' => '',
        'created_at' => now_ts(),
        'updated_at' => now_ts(),
    ];

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
        'status' => (string)($done['status'] ?? ''),
        'message' => (string)($done['final_message'] ?? ''),
        'created_at' => (int)($done['created_at'] ?? now_ts()),
        'completed_at' => (int)($done['completed_at'] ?? now_ts()),
        'created_by_admin' => (bool)($done['created_by_admin'] ?? false),
        'request_source' => $requestSource,
    ]);
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