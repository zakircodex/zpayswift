<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function get_user_wallet(string $uid): ?array
{
    $wallet = fb_get('USER_WALLETS/' . $uid);
    return is_array($wallet) ? $wallet : null;
}

function wallet_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function wallet_month_key(?int $ts = null): string
{
    if (function_exists('month_key') && $ts === null) {
        return (string)month_key();
    }

    return date('Y-m', $ts ?? wallet_now());
}

function wallet_round_money(float $amount): float
{
    return round($amount, 2);
}

function wallet_make_ledger_id(): string
{
    if (function_exists('make_ledger_id')) {
        return (string)make_ledger_id();
    }

    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return 'WL' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function create_wallet_ledger(
    string $uid,
    string $type,
    float $amount,
    string $direction,
    string $refId,
    string $note,
    string $status = 'DONE'
): string {
    $ledgerId = wallet_make_ledger_id();

    $row = [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => $type,
        'amount' => wallet_round_money($amount),
        'currency' => 'BDT',
        'direction' => $direction,
        'ref_id' => $refId,
        'note' => $note,
        'status' => $status,
        'created_at' => wallet_now(),
    ];

    fb_put('WALLET_LEDGER/' . $uid . '/' . wallet_month_key() . '/' . $ledgerId, $row);

    return $ledgerId;
}

function create_wallet_ledger_full(string $uid, array $row): string
{
    $now = wallet_now();
    $ledgerId = trim((string)($row['ledger_id'] ?? ''));

    if ($ledgerId === '') {
        $ledgerId = wallet_make_ledger_id();
    }

    $row['ledger_id'] = $ledgerId;
    $row['uid'] = $uid;
    $row['currency'] = (string)($row['currency'] ?? 'BDT');
    $row['status'] = (string)($row['status'] ?? 'DONE');
    $row['created_at'] = (int)($row['created_at'] ?? $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . wallet_month_key((int)$row['created_at']) . '/' . $ledgerId, $row);

    return $ledgerId;
}

function wallet_credit_available(
    string $uid,
    float $amount,
    string $refId,
    string $type,
    string $note,
    array $extraLedger = []
): array {
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = $res['value'];
        $now = wallet_now();

        $beforeAvailable = (float)($wallet['available_balance'] ?? 0);
        $beforeHold = (float)($wallet['hold_balance'] ?? 0);

        $afterAvailable = wallet_round_money($beforeAvailable + $amount);

        $updated = $wallet;
        $updated['available_balance'] = $afterAvailable;
        $updated['updated_at'] = $now;

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to credit wallet balance',
            ];
        }

        $ledgerRow = array_merge([
            'type' => $type,
            'direction' => 'CREDIT',
            'amount' => wallet_round_money($amount),
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
            'before_hold' => wallet_round_money($beforeHold),
            'after_hold' => wallet_round_money($beforeHold),
            'ref_id' => $refId,
            'note' => $note,
            'created_at' => $now,
        ], $extraLedger);

        $ledgerId = create_wallet_ledger_full($uid, $ledgerRow);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet credited successfully',
            'ledger_id' => $ledgerId,
            'available_balance' => $afterAvailable,
            'hold_balance' => wallet_round_money($beforeHold),
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_debit_available(
    string $uid,
    float $amount,
    string $refId,
    string $type,
    string $note,
    array $extraLedger = []
): array {
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = $res['value'];
        $now = wallet_now();

        $beforeAvailable = (float)($wallet['available_balance'] ?? 0);
        $beforeHold = (float)($wallet['hold_balance'] ?? 0);

        if ($beforeAvailable < $amount) {
            return [
                'ok' => false,
                'code' => 'INSUFFICIENT_BALANCE',
                'message' => 'Not enough available balance',
                'available_balance' => wallet_round_money($beforeAvailable),
                'required_amount' => wallet_round_money($amount),
            ];
        }

        $afterAvailable = wallet_round_money($beforeAvailable - $amount);

        $updated = $wallet;
        $updated['available_balance'] = $afterAvailable;
        $updated['updated_at'] = $now;

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to debit wallet balance',
            ];
        }

        $ledgerRow = array_merge([
            'type' => $type,
            'direction' => 'DEBIT',
            'amount' => wallet_round_money($amount),
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
            'before_hold' => wallet_round_money($beforeHold),
            'after_hold' => wallet_round_money($beforeHold),
            'ref_id' => $refId,
            'note' => $note,
            'created_at' => $now,
        ], $extraLedger);

        $ledgerId = create_wallet_ledger_full($uid, $ledgerRow);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet debited successfully',
            'ledger_id' => $ledgerId,
            'available_balance' => $afterAvailable,
            'hold_balance' => wallet_round_money($beforeHold),
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_hold_amount(string $uid, float $amount, string $refId, string $type = 'TOPUP_HOLD'): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = $res['value'];
        $now = wallet_now();

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);

        if ($available < $amount) {
            return [
                'ok' => false,
                'code' => 'INSUFFICIENT_BALANCE',
                'message' => 'Not enough balance',
                'available_balance' => wallet_round_money($available),
                'required_amount' => wallet_round_money($amount),
            ];
        }

        $updated = $wallet;
        $updated['available_balance'] = wallet_round_money($available - $amount);
        $updated['hold_balance'] = wallet_round_money($hold + $amount);
        $updated['updated_at'] = $now;

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to reserve wallet balance',
            ];
        }

        create_wallet_ledger_full($uid, [
            'type' => $type,
            'direction' => 'HOLD',
            'amount' => wallet_round_money($amount),
            'before_available' => wallet_round_money($available),
            'after_available' => $updated['available_balance'],
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Amount reserved for request',
            'created_at' => $now,
        ]);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet amount held successfully',
            'available_balance' => $updated['available_balance'],
            'hold_balance' => $updated['hold_balance'],
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_refund_hold(string $uid, float $amount, string $refId, string $type = 'TOPUP_REFUND'): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = $res['value'];
        $now = wallet_now();

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalRefund = (float)($wallet['total_refund'] ?? 0);

        $updated = $wallet;
        $updated['available_balance'] = wallet_round_money($available + $amount);
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_refund'] = wallet_round_money($totalRefund + $amount);
        $updated['updated_at'] = $now;

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to refund wallet balance',
            ];
        }

        create_wallet_ledger_full($uid, [
            'type' => $type,
            'direction' => 'RELEASE_HOLD',
            'amount' => wallet_round_money($amount),
            'before_available' => wallet_round_money($available),
            'after_available' => $updated['available_balance'],
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Refunded reserved amount',
            'created_at' => $now,
        ]);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet refund successful',
            'available_balance' => $updated['available_balance'],
            'hold_balance' => $updated['hold_balance'],
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_settle_hold(string $uid, float $amount, string $refId, string $type = 'TOPUP_SETTLE'): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = $res['value'];
        $now = wallet_now();

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalTopup = (float)($wallet['total_topup_spent'] ?? 0);

        $updated = $wallet;
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_topup_spent'] = wallet_round_money($totalTopup + $amount);
        $updated['updated_at'] = $now;

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to settle wallet hold',
            ];
        }

        create_wallet_ledger_full($uid, [
            'type' => $type,
            'direction' => 'DEBIT_FINAL',
            'amount' => wallet_round_money($amount),
            'before_available' => wallet_round_money($available),
            'after_available' => wallet_round_money($available),
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Reserved amount settled after success',
            'created_at' => $now,
        ]);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet settled successfully',
            'available_balance' => wallet_round_money($available),
            'hold_balance' => $updated['hold_balance'],
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_settle_hold_bundle(string $uid, float $amount, string $refId, string $type = 'BUNDLE_SETTLE'): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        $wallet = $res['value'];
        $now = wallet_now();

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalBundle = (float)($wallet['total_bundle_spent'] ?? 0);

        $updated = $wallet;
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_bundle_spent'] = wallet_round_money($totalBundle + $amount);
        $updated['updated_at'] = $now;

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to settle bundle hold',
            ];
        }

        create_wallet_ledger_full($uid, [
            'type' => $type,
            'direction' => 'DEBIT_FINAL',
            'amount' => wallet_round_money($amount),
            'before_available' => wallet_round_money($available),
            'after_available' => wallet_round_money($available),
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Reserved amount settled after bundle success',
            'created_at' => $now,
        ]);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Bundle wallet settled successfully',
            'available_balance' => wallet_round_money($available),
            'hold_balance' => $updated['hold_balance'],
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_credit_bundle_user_commission(
    string $uid,
    float $commissionAmount,
    string $requestId,
    array $meta = []
): array {
    if ($commissionAmount <= 0) {
        return [
            'ok' => true,
            'code' => 'NO_COMMISSION',
            'message' => 'No user commission to credit',
            'available_balance' => (float)((get_user_wallet($uid) ?? [])['available_balance'] ?? 0),
        ];
    }

    return wallet_credit_available(
        $uid,
        $commissionAmount,
        $requestId,
        'BUNDLE_USER_COMMISSION',
        'Bundle commission credited after successful bundle request',
        [
            'direction' => 'CREDIT',
            'request_type' => 'BUNDLE',
            'commission_type' => 'USER_COMMISSION',
            'offer_id' => (string)($meta['offer_id'] ?? ''),
            'bundle_name' => (string)($meta['bundle_name'] ?? ''),
            'operator' => (string)($meta['operator'] ?? ''),
            'admin_commission' => wallet_round_money((float)($meta['admin_commission'] ?? 0)),
            'user_commission' => wallet_round_money($commissionAmount),
            'subadmin_profit' => wallet_round_money((float)($meta['subadmin_profit'] ?? 0)),
        ]
    );
}

function wallet_credit_bundle_subadmin_profit(
    string $subadminUid,
    float $profitAmount,
    string $requestId,
    array $meta = []
): array {
    if ($profitAmount <= 0 || trim($subadminUid) === '') {
        return [
            'ok' => true,
            'code' => 'NO_SUBADMIN_PROFIT',
            'message' => 'No subadmin profit to credit',
            'available_balance' => (float)((get_user_wallet($subadminUid) ?? [])['available_balance'] ?? 0),
        ];
    }

    return wallet_credit_available(
        $subadminUid,
        $profitAmount,
        $requestId,
        'BUNDLE_SUBADMIN_PROFIT',
        'Subadmin bundle profit credited after successful bundle request',
        [
            'direction' => 'CREDIT',
            'request_type' => 'BUNDLE',
            'commission_type' => 'SUBADMIN_PROFIT',
            'offer_id' => (string)($meta['offer_id'] ?? ''),
            'bundle_name' => (string)($meta['bundle_name'] ?? ''),
            'operator' => (string)($meta['operator'] ?? ''),
            'target_uid' => (string)($meta['target_uid'] ?? ''),
            'admin_commission' => wallet_round_money((float)($meta['admin_commission'] ?? 0)),
            'user_commission' => wallet_round_money((float)($meta['user_commission'] ?? 0)),
            'subadmin_profit' => wallet_round_money($profitAmount),
        ]
    );
}

function wallet_admin_add_balance(string $uid, float $amount, string $refId, string $note = 'Admin balance added'): array
{
    return wallet_credit_available(
        $uid,
        $amount,
        $refId,
        'ADMIN_CREDIT',
        $note,
        [
            'direction' => 'CREDIT',
        ]
    );
}

function wallet_admin_deduct_balance(string $uid, float $amount, string $refId, string $note = 'Admin balance deducted'): array
{
    return wallet_debit_available(
        $uid,
        $amount,
        $refId,
        'ADMIN_DEBIT',
        $note,
        [
            'direction' => 'DEBIT',
        ]
    );
}
