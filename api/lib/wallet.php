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

function wallet_make_transfer_id(): string
{
    return 'WTR' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
}

function wallet_valid_month_key(?string $month = null): string
{
    $month = trim((string)$month);
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
        ? $month
        : wallet_month_key();
}

function wallet_normalize_role($role, string $fallback = 'USER'): string
{
    $role = strtoupper(trim((string)$role));
    return $role !== '' ? $role : $fallback;
}

function wallet_identity(string $uid, array $user = [], string $fallbackRole = 'USER'): array
{
    if (!$user && $uid !== '') {
        $loaded = fb_get('USERS/' . $uid);
        $user = is_array($loaded) ? $loaded : [];
    }

    return [
        'uid' => $uid,
        'name' => trim((string)($user['name'] ?? '')),
        'phone' => trim((string)($user['phone'] ?? '')),
        'role' => wallet_normalize_role($user['role'] ?? '', $fallbackRole),
    ];
}

function wallet_transfer_history_row(array $transfer, string $direction, string $ledgerId): array
{
    $direction = strtoupper(trim($direction));
    $isDebit = $direction === 'DEBIT';

    $beforeAvailable = $isDebit
        ? (float)($transfer['sender_before_available'] ?? 0)
        : (float)($transfer['receiver_before_available'] ?? $transfer['before_available'] ?? 0);
    $afterAvailable = $isDebit
        ? (float)($transfer['sender_after_available'] ?? 0)
        : (float)($transfer['receiver_after_available'] ?? $transfer['after_available'] ?? 0);
    $beforeHold = $isDebit
        ? (float)($transfer['sender_before_hold'] ?? 0)
        : (float)($transfer['receiver_before_hold'] ?? $transfer['before_hold'] ?? 0);
    $afterHold = $isDebit
        ? (float)($transfer['sender_after_hold'] ?? 0)
        : (float)($transfer['receiver_after_hold'] ?? $transfer['after_hold'] ?? 0);

    return array_merge($transfer, [
        'ledger_id' => $ledgerId,
        'type' => $isDebit ? 'WALLET_DEBIT' : 'WALLET_CREDIT',
        'transfer_type' => (string)($transfer['type'] ?? ''),
        'direction' => $direction,
        'before_balance' => wallet_round_money($beforeAvailable),
        'after_balance' => wallet_round_money($afterAvailable),
        'before_available' => wallet_round_money($beforeAvailable),
        'after_available' => wallet_round_money($afterAvailable),
        'before_hold' => wallet_round_money($beforeHold),
        'after_hold' => wallet_round_money($afterHold),
    ]);
}

function wallet_cleanup_paths(array $paths): void
{
    foreach (array_reverse($paths) as $path) {
        if (is_string($path) && trim($path) !== '') {
            fb_delete($path);
        }
    }
}

function wallet_store_transfer_records(array $transfer, array $ledgerRows = []): array
{
    $now = (int)($transfer['created_at'] ?? wallet_now());
    $month = wallet_valid_month_key((string)($transfer['month'] ?? wallet_month_key($now)));
    $transferId = trim((string)($transfer['transfer_id'] ?? ''));

    if ($transferId === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_TRANSFER_ID',
            'message' => 'Transfer ID is required',
        ];
    }

    $transfer['transfer_id'] = $transferId;
    $transfer['month'] = $month;
    $transfer['amount'] = wallet_round_money((float)($transfer['amount'] ?? 0));
    $transfer['currency'] = strtoupper(trim((string)($transfer['currency'] ?? 'BDT'))) ?: 'BDT';
    $transfer['status'] = 'SUCCESS';
    $transfer['created_at'] = $now;
    $transfer['updated_at'] = (int)($transfer['updated_at'] ?? $now);

    $writtenPaths = [];

    foreach ($ledgerRows as $ledgerSpec) {
        if (!is_array($ledgerSpec)) {
            continue;
        }

        $uid = trim((string)($ledgerSpec['uid'] ?? ''));
        $row = is_array($ledgerSpec['row'] ?? null) ? $ledgerSpec['row'] : [];
        $ledgerId = trim((string)($row['ledger_id'] ?? ''));

        if ($uid === '' || $ledgerId === '') {
            wallet_cleanup_paths($writtenPaths);
            return [
                'ok' => false,
                'code' => 'INVALID_LEDGER_DATA',
                'message' => 'Wallet ledger data is incomplete',
            ];
        }

        $row['uid'] = $uid;
        $row['ledger_id'] = $ledgerId;
        $row['currency'] = (string)($row['currency'] ?? $transfer['currency']);
        $row['status'] = (string)($row['status'] ?? 'SUCCESS');
        $row['created_at'] = (int)($row['created_at'] ?? $now);
        $row['updated_at'] = (int)($row['updated_at'] ?? $now);

        $path = 'WALLET_LEDGER/' . $uid . '/' . wallet_month_key($row['created_at']) . '/' . $ledgerId;
        if (!fb_put($path, $row)) {
            wallet_cleanup_paths($writtenPaths);
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'Failed to save wallet ledger',
            ];
        }
        $writtenPaths[] = $path;
    }

    $auditPath = 'WALLET_TRANSFERS/' . $month . '/' . $transferId;
    if (!fb_put($auditPath, $transfer)) {
        wallet_cleanup_paths($writtenPaths);
        return [
            'ok' => false,
            'code' => 'TRANSFER_AUDIT_FAILED',
            'message' => 'Failed to save transfer audit',
        ];
    }
    $writtenPaths[] = $auditPath;

    $receiverUid = trim((string)($transfer['receiver_uid'] ?? ''));
    $receiverLedgerId = trim((string)($transfer['receiver_ledger_id'] ?? $transfer['ledger_id'] ?? ''));
    if ($receiverUid !== '') {
        $receiverPath = 'USER_WALLET_HISTORY/' . $receiverUid . '/' . $month . '/' . $transferId;
        if (!fb_put($receiverPath, wallet_transfer_history_row($transfer, 'CREDIT', $receiverLedgerId))) {
            wallet_cleanup_paths($writtenPaths);
            return [
                'ok' => false,
                'code' => 'RECEIVER_HISTORY_FAILED',
                'message' => 'Failed to save receiver wallet history',
            ];
        }
        $writtenPaths[] = $receiverPath;
    }

    $senderUid = trim((string)($transfer['sender_uid'] ?? ''));
    $senderLedgerId = trim((string)($transfer['sender_ledger_id'] ?? ''));
    if (!empty($transfer['sender_wallet_debited']) && $senderUid !== '') {
        $senderPath = 'USER_WALLET_HISTORY/' . $senderUid . '/' . $month . '/' . $transferId;
        if (!fb_put($senderPath, wallet_transfer_history_row($transfer, 'DEBIT', $senderLedgerId))) {
            wallet_cleanup_paths($writtenPaths);
            return [
                'ok' => false,
                'code' => 'SENDER_HISTORY_FAILED',
                'message' => 'Failed to save sender wallet history',
            ];
        }
        $writtenPaths[] = $senderPath;
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet transfer history saved',
        'transfer_id' => $transferId,
        'month' => $month,
        'written_paths' => $writtenPaths,
    ];
}

function wallet_restore_available_balance(string $uid, float $expectedCurrent, float $restoreValue): bool
{
    for ($i = 0; $i < 3; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);
        if (!($res['ok'] ?? false) || !is_array($res['value'] ?? null) || empty($res['etag'])) {
            return false;
        }

        $wallet = $res['value'];
        $current = wallet_round_money((float)($wallet['available_balance'] ?? 0));
        if (abs($current - wallet_round_money($expectedCurrent)) > 0.001) {
            return false;
        }

        $wallet['available_balance'] = wallet_round_money($restoreValue);
        $wallet['updated_at'] = wallet_now();
        $save = fb_put_if_match('USER_WALLETS/' . $uid, $wallet, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }

        return (bool)($save['ok'] ?? false);
    }

    return false;
}

function wallet_delete_ledger_record(string $uid, int $createdAt, string $ledgerId): bool
{
    if ($uid === '' || $ledgerId === '') {
        return false;
    }

    return fb_delete('WALLET_LEDGER/' . $uid . '/' . wallet_month_key($createdAt) . '/' . $ledgerId);
}

function wallet_list_transfer_history(string $month, array $filters = [], int $limit = 200): array
{
    $month = wallet_valid_month_key($month);
    $rows = fb_get('WALLET_TRANSFERS/' . $month);
    $rows = is_array($rows) ? $rows : [];
    $items = [];

    $receiverQuery = strtolower(trim((string)($filters['receiver'] ?? '')));
    $senderRole = wallet_normalize_role($filters['sender_role'] ?? '', '');
    $receiverRole = wallet_normalize_role($filters['receiver_role'] ?? '', '');
    $type = strtoupper(trim((string)($filters['type'] ?? '')));
    $senderUid = trim((string)($filters['sender_uid'] ?? ''));
    $receiverUid = trim((string)($filters['receiver_uid'] ?? ''));

    foreach ($rows as $transferId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['transfer_id'] = (string)($row['transfer_id'] ?? $transferId);

        if ($senderUid !== '' && (string)($row['sender_uid'] ?? '') !== $senderUid) {
            continue;
        }
        if ($receiverUid !== '' && (string)($row['receiver_uid'] ?? '') !== $receiverUid) {
            continue;
        }
        if ($senderRole !== '' && wallet_normalize_role($row['sender_role'] ?? '', '') !== $senderRole) {
            continue;
        }
        if ($receiverRole !== '' && wallet_normalize_role($row['receiver_role'] ?? '', '') !== $receiverRole) {
            continue;
        }
        if ($type !== '' && strtoupper((string)($row['type'] ?? '')) !== $type) {
            continue;
        }
        if ($receiverQuery !== '') {
            $haystack = strtolower(implode(' ', [
                (string)($row['receiver_uid'] ?? ''),
                (string)($row['receiver_name'] ?? ''),
                (string)($row['receiver_phone'] ?? ''),
            ]));
            if (!str_contains($haystack, $receiverQuery)) {
                continue;
            }
        }

        $items[] = $row;
    }

    usort($items, static fn(array $a, array $b): int =>
        (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0)
    );

    return array_slice($items, 0, max(1, min(500, $limit)));
}

function wallet_list_user_history(string $uid, string $month, int $limit = 100): array
{
    $month = wallet_valid_month_key($month);
    $rows = fb_get('USER_WALLET_HISTORY/' . $uid . '/' . $month);
    $rows = is_array($rows) ? $rows : [];
    $items = [];

    foreach ($rows as $transferId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['transfer_id'] = (string)($row['transfer_id'] ?? $transferId);
        $items[] = $row;
    }

    usort($items, static fn(array $a, array $b): int =>
        (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0)
    );

    return array_slice($items, 0, max(1, min(500, $limit)));
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

function wallet_admin_add_balance(
    string $uid,
    float $amount,
    string $refId,
    string $note = 'Admin balance added',
    array $extraLedger = []
): array
{
    return wallet_credit_available(
        $uid,
        $amount,
        $refId,
        'ADMIN_CREDIT',
        $note,
        array_merge([
            'direction' => 'CREDIT',
        ], $extraLedger)
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
