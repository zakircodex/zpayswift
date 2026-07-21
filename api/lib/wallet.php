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

function wallet_normalize_currency_code($currency, string $fallback = ''): string
{
    $currency = strtoupper(trim((string)$currency));

    if (function_exists('security_normalize_currency')) {
        $normalized = security_normalize_currency($currency);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    if (in_array($currency, ['MYR', 'RM', 'RINGGIT'], true)) {
        return 'MYR';
    }

    if (in_array($currency, ['BDT', 'TK', 'TAKA'], true)) {
        return 'BDT';
    }

    return $fallback;
}

function wallet_account_country_code(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        return auth_pricing_country_from_user($user, $wallet);
    }

    if (function_exists('security_user_country_code')) {
        $country = security_user_country_code($user, $wallet);
        if ($country !== '') {
            return $country;
        }
    }

    foreach (['pricing_country', 'market_country', 'service_country', 'country_code', 'country', 'user_country'] as $key) {
        $country = strtoupper(trim((string)($user[$key] ?? '')));

        if (in_array($country, ['MY', 'MYS', 'MALAYSIA'], true)) {
            return 'MY';
        }

        if (in_array($country, ['BD', 'BGD', 'BANGLADESH'], true)) {
            return 'BD';
        }
    }

    return '';
}

function wallet_account_currency(array $user, array $wallet = []): string
{
    $country = wallet_account_country_code($user, $wallet);

    if ($country === 'MY') {
        return 'MYR';
    }

    if ($country === 'BD') {
        return 'BDT';
    }

    foreach ([
        $user['wallet_currency'] ?? '',
        $user['currency'] ?? '',
        $wallet['wallet_currency'] ?? '',
        $wallet['currency'] ?? '',
    ] as $candidate) {
        $currency = wallet_normalize_currency_code($candidate);
        if ($currency !== '') {
            return $currency;
        }
    }

    return 'BDT';
}

function wallet_currency_for_uid(string $uid, array $wallet = []): string
{
    $uid = trim($uid);
    $user = $uid !== '' ? fb_get('USERS/' . $uid) : [];

    return wallet_account_currency(
        is_array($user) ? $user : [],
        $wallet
    );
}

function wallet_myr_to_bdt_rate(): float
{
    $paths = [
        'MFS_SETTINGS/rate_myr_bdt',
        'MFS_SETTINGS/rates/myr_to_bdt',
        'MFS_CONFIG/RATE/MYR_TO_BDT',
        'MFS_CONFIG/RATES/MYR_TO_BDT',
        'APP_CONFIG/MYR_TO_BDT_RATE',
        'APP_CONFIG/RINGGIT_RATE',
    ];

    foreach ($paths as $path) {
        $value = fb_get($path);

        if (is_numeric($value) && (float)$value > 0) {
            return wallet_round_money((float)$value);
        }

        if (is_array($value)) {
            foreach (['rate', 'value', 'amount', 'bdt'] as $key) {
                if (isset($value[$key]) && is_numeric($value[$key]) && (float)$value[$key] > 0) {
                    return wallet_round_money((float)$value[$key]);
                }
            }
        }
    }

    if (defined('MYR_TO_BDT_RATE') && (float)MYR_TO_BDT_RATE > 0) {
        return wallet_round_money((float)MYR_TO_BDT_RATE);
    }

    return 31.00;
}

function wallet_service_bdt_to_native(
    string $uid,
    float $amountBdt,
    array $user = [],
    array $wallet = []
): array {
    $uid = trim($uid);
    $amountBdt = wallet_round_money(max(0, $amountBdt));

    if (!$user && $uid !== '') {
        $loadedUser = fb_get('USERS/' . $uid);
        $user = is_array($loadedUser) ? $loadedUser : [];
    }

    if (!$wallet && $uid !== '') {
        $loadedWallet = fb_get('USER_WALLETS/' . $uid);
        $wallet = is_array($loadedWallet) ? $loadedWallet : [];
    }

    $currency = wallet_account_currency($user, $wallet);
    $rate = wallet_myr_to_bdt_rate();
    $walletAmount = $currency === 'MYR' && $rate > 0
        ? wallet_round_money($amountBdt / $rate)
        : $amountBdt;

    return [
        'amount_bdt' => $amountBdt,
        'wallet_currency' => $currency,
        'wallet_amount' => $walletAmount,
        'rate_used' => $currency === 'MYR' ? $rate : 0.0,
    ];
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

function wallet_deterministic_record_matches(array $existing, array $expected, array $identityFields): bool
{
    foreach ($identityFields as $field) {
        if ($field === 'amount') {
            if (abs(wallet_round_money((float)($existing[$field] ?? 0)) - wallet_round_money((float)($expected[$field] ?? 0))) > 0.001) {
                return false;
            }
            continue;
        }

        if ((string)($existing[$field] ?? '') !== (string)($expected[$field] ?? '')) {
            return false;
        }
    }

    return true;
}

function wallet_put_deterministic_record(string $path, array $row, array $identityFields): array
{
    for ($i = 0; $i < 5; $i++) {
        $current = fb_get_with_etag($path);
        if (empty($current['ok']) || empty($current['etag'])) {
            return ['ok' => false, 'code' => 'RECORD_READ_FAILED'];
        }

        $existing = $current['value'] ?? null;
        if ($existing !== null) {
            if (is_array($existing) && wallet_deterministic_record_matches($existing, $row, $identityFields)) {
                return ['ok' => true, 'idempotent_replay' => true];
            }
            return ['ok' => false, 'code' => 'RECORD_CONFLICT'];
        }

        $save = fb_put_if_match($path, $row, (string)$current['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        return ['ok' => !empty($save['ok']), 'code' => !empty($save['ok']) ? 'SUCCESS' : 'RECORD_WRITE_FAILED'];
    }

    return ['ok' => false, 'code' => 'RECORD_CONFLICT'];
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

        $ledger = wallet_create_ledger_full_checked($uid, $row);
        $path = (string)($ledger['ledger_path'] ?? ('WALLET_LEDGER/' . $uid . '/' . wallet_month_key($row['created_at']) . '/' . $ledgerId));
        if (empty($ledger['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($ledger['code'] ?? 'LEDGER_WRITE_FAILED'),
                'message' => 'Failed to save wallet ledger',
            ];
        }
        $writtenPaths[] = $path;
    }

    $auditPath = 'WALLET_TRANSFERS/' . $month . '/' . $transferId;
    $auditWrite = wallet_put_deterministic_record(
        $auditPath,
        $transfer,
        ['transfer_id', 'type', 'amount', 'currency', 'sender_uid', 'receiver_uid']
    );
    if (empty($auditWrite['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($auditWrite['code'] ?? 'TRANSFER_AUDIT_FAILED'),
            'message' => 'Failed to save transfer audit',
        ];
    }
    $writtenPaths[] = $auditPath;

    $receiverUid = trim((string)($transfer['receiver_uid'] ?? ''));
    $receiverLedgerId = trim((string)($transfer['receiver_ledger_id'] ?? $transfer['ledger_id'] ?? ''));
    if ($receiverUid !== '') {
        $receiverPath = 'USER_WALLET_HISTORY/' . $receiverUid . '/' . $month . '/' . $transferId;
        $receiverHistory = wallet_transfer_history_row($transfer, 'CREDIT', $receiverLedgerId);
        $receiverWrite = wallet_put_deterministic_record(
            $receiverPath,
            $receiverHistory,
            ['transfer_id', 'direction', 'amount', 'currency', 'sender_uid', 'receiver_uid']
        );
        if (empty($receiverWrite['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($receiverWrite['code'] ?? 'RECEIVER_HISTORY_FAILED'),
                'message' => 'Failed to save receiver wallet history',
            ];
        }
        $writtenPaths[] = $receiverPath;
    }

    $senderUid = trim((string)($transfer['sender_uid'] ?? ''));
    $senderLedgerId = trim((string)($transfer['sender_ledger_id'] ?? ''));
    if (!empty($transfer['sender_wallet_debited']) && $senderUid !== '') {
        $senderPath = 'USER_WALLET_HISTORY/' . $senderUid . '/' . $month . '/' . $transferId;
        $senderHistory = wallet_transfer_history_row($transfer, 'DEBIT', $senderLedgerId);
        $senderWrite = wallet_put_deterministic_record(
            $senderPath,
            $senderHistory,
            ['transfer_id', 'direction', 'amount', 'currency', 'sender_uid', 'receiver_uid']
        );
        if (empty($senderWrite['ok'])) {
            return [
                'ok' => false,
                'code' => (string)($senderWrite['code'] ?? 'SENDER_HISTORY_FAILED'),
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

function wallet_financial_operation_scope_path(string $refId, string $scope): string
{
    $refId = trim($refId);
    $scope = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', trim($scope)) ?? '');

    return 'WALLET_FINANCIAL_OPERATIONS/' . hash('sha256', $refId) . '/' . ($scope !== '' ? $scope : 'REQUEST_FINAL');
}

function wallet_financial_operation_ledger_id(string $refId, string $operationType): string
{
    return 'LED' . strtoupper(substr(hash('sha256', trim($refId) . '|' . strtoupper(trim($operationType))), 0, 28));
}

function wallet_financial_operation_key(string $refId, string $operationType): string
{
    return hash('sha256', trim($refId) . '|' . strtoupper(trim($operationType)));
}

function wallet_financial_operation_lease_seconds(): int
{
    $raw = defined('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS')
        ? (string)constant('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS')
        : (string)(getenv('WALLET_FINANCIAL_OPERATION_LEASE_SECONDS') ?: '');
    $seconds = is_numeric($raw) ? (int)$raw : 120;
    return max(60, min(600, $seconds));
}

function wallet_financial_operation_legacy_stale_seconds(): int
{
    return max(300, wallet_financial_operation_lease_seconds() * 2);
}

function wallet_financial_operation_is_stale(array $operation, int $now): bool
{
    $leaseExpires = (int)($operation['lease_expires_at'] ?? 0);
    if ($leaseExpires > 0) {
        return $leaseExpires <= $now;
    }

    $anchor = (int)(
        ($operation['updated_at'] ?? 0)
        ?: ($operation['claimed_at'] ?? 0)
        ?: ($operation['created_at'] ?? 0)
    );

    return $anchor > 0 && ($anchor + wallet_financial_operation_legacy_stale_seconds()) <= $now;
}

function wallet_financial_operation_owner_hash_from_claim(array $claim): string
{
    $token = (string)($claim['_owner_token'] ?? '');
    if ($token !== '') {
        return hash('sha256', $token);
    }

    return (string)($claim['owner_token_hash'] ?? '');
}

function wallet_financial_operation_marker_from_wallet(string $uid, string $operationKey): array
{
    $wallet = fb_get('USER_WALLETS/' . trim($uid));
    if (!is_array($wallet)) {
        return [];
    }

    $markers = $wallet['financial_operations'] ?? [];
    if (!is_array($markers)) {
        return [];
    }

    $marker = $markers[$operationKey] ?? [];
    return is_array($marker) ? $marker : [];
}

function wallet_financial_operation_with_runtime_fields(array $claim, string $path, string $owner, array $source = []): array
{
    $claim['_path'] = $path;
    $claim['_owner_token'] = $owner;
    if (!empty($source['wallet_applied'])) {
        $claim['_wallet_already_applied'] = true;
    }
    if (!empty($source['resume_from'])) {
        $claim['_resume_from'] = (string)$source['resume_from'];
    }
    return $claim;
}

function wallet_financial_operation_binding_issue(
    array $operation,
    string $refId,
    string $operationType,
    string $scope,
    string $uid,
    float $amount,
    string $currency
): array {
    $expected = [
        'request_id' => trim($refId),
        'operation_type' => strtoupper(trim($operationType)),
        'scope' => strtoupper(trim($scope)),
        'uid' => trim($uid),
        'operation_key' => wallet_financial_operation_key($refId, $operationType),
    ];

    foreach ($expected as $field => $value) {
        $stored = trim((string)($operation[$field] ?? ''));
        if ($stored === '') {
            return ['type' => 'missing', 'field' => $field];
        }
        if ($stored !== $value) {
            return ['type' => 'mismatch', 'field' => $field];
        }
    }

    if (!array_key_exists('amount', $operation)) {
        return ['type' => 'missing', 'field' => 'amount'];
    }
    if (abs(wallet_round_money((float)$operation['amount']) - wallet_round_money($amount)) > 0.001) {
        return ['type' => 'mismatch', 'field' => 'amount'];
    }

    $storedCurrency = wallet_normalize_currency_code((string)($operation['currency'] ?? ''));
    if ($storedCurrency === '') {
        return ['type' => 'missing', 'field' => 'currency'];
    }
    if ($storedCurrency !== wallet_normalize_currency_code($currency)) {
        return ['type' => 'mismatch', 'field' => 'currency'];
    }

    return [];
}

function wallet_financial_operation_legacy_applied_recoverable(
    array $operation,
    string $refId,
    string $operationType,
    string $scope,
    string $uid,
    float $amount,
    string $currency
): bool {
    if (strtoupper(trim((string)($operation['status'] ?? ''))) !== 'APPLIED' || empty($operation['wallet_applied'])) {
        return false;
    }
    $ledger = is_array($operation['ledger_row'] ?? null) ? (array)$operation['ledger_row'] : [];
    if ($ledger === []) {
        return false;
    }

    $storedBindings = [
        trim((string)($operation['request_id'] ?? '')) === trim($refId),
        strtoupper(trim((string)($operation['operation_type'] ?? ''))) === strtoupper(trim($operationType)),
        strtoupper(trim((string)($operation['scope'] ?? ''))) === strtoupper(trim($scope)),
        trim((string)($operation['uid'] ?? '')) === trim($uid),
        abs(wallet_round_money((float)($operation['amount'] ?? -1)) - wallet_round_money($amount)) <= 0.001,
        wallet_normalize_currency_code((string)($operation['currency'] ?? '')) === wallet_normalize_currency_code($currency),
    ];
    if (in_array(false, $storedBindings, true)) {
        return false;
    }

    $ledgerRef = trim((string)($ledger['ref_id'] ?? $ledger['request_id'] ?? $ledger['reference'] ?? ''));
    $ledgerCurrency = wallet_normalize_currency_code((string)($ledger['wallet_currency'] ?? $ledger['currency'] ?? ''));
    return $ledgerRef === trim($refId)
        && abs(wallet_round_money((float)($ledger['amount'] ?? -1)) - wallet_round_money($amount)) <= 0.001
        && $ledgerCurrency === wallet_normalize_currency_code($currency)
        && trim((string)($operation['ledger_id'] ?? '')) !== ''
        && trim((string)($operation['ledger_id'] ?? '')) === trim((string)($ledger['ledger_id'] ?? ''));
}

function wallet_financial_operation_begin(
    string $refId,
    string $operationType,
    string $scope,
    string $uid,
    float $amount,
    string $currency,
    array $meta = []
): array {
    $refId = trim($refId);
    $operationType = strtoupper(trim($operationType));
    $scope = strtoupper(trim($scope));
    $uid = trim($uid);
    $currency = wallet_normalize_currency_code($currency);

    if ($refId === '' || $operationType === '' || $scope === '' || $uid === '') {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_INVALID',
            'message' => 'Financial operation data is invalid',
        ];
    }

    $path = wallet_financial_operation_scope_path($refId, $scope);
    $owner = bin2hex(random_bytes(12));
    $now = wallet_now();
    $ledgerId = wallet_financial_operation_ledger_id($refId, $operationType);
    $operationKey = wallet_financial_operation_key($refId, $operationType);
    $leaseSeconds = wallet_financial_operation_lease_seconds();

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_UNAVAILABLE',
                'message' => 'Financial operation safety check is unavailable',
            ];
        }

        $current = is_array($res['value'] ?? null) ? $res['value'] : [];
        $resumeSource = [];
        if ($current !== []) {
            $currentOperation = strtoupper(trim((string)($current['operation_type'] ?? '')));
            $currentStatus = strtoupper(trim((string)($current['status'] ?? 'CLAIMED')));
            $bindingOperationType = $currentOperation !== '' ? $currentOperation : $operationType;
            $bindingIssue = wallet_financial_operation_binding_issue(
                $current,
                $refId,
                $bindingOperationType,
                $scope,
                $uid,
                $amount,
                $currency
            );

            if (($bindingIssue['type'] ?? '') === 'mismatch') {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_BINDING_CONFLICT',
                    'message' => 'Financial operation identity does not match the original request',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            if (($bindingIssue['type'] ?? '') === 'missing' && $currentStatus !== 'COMPLETED') {
                $legacyAppliedRecoverable = wallet_financial_operation_legacy_applied_recoverable(
                    $current,
                    $refId,
                    $bindingOperationType,
                    $scope,
                    $uid,
                    $amount,
                    $currency
                );
                if (!$legacyAppliedRecoverable) {
                    wallet_financial_operation_mark_reconciliation_record($path, (string)$res['etag'], $current, [
                        'last_error_code' => 'FINANCIAL_OPERATION_BINDING_MISSING',
                        'last_error_at' => $now,
                        'reconciliation_required' => true,
                        'binding_missing_field' => (string)($bindingIssue['field'] ?? ''),
                    ]);
                    return [
                        'ok' => false,
                        'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
                        'message' => 'Financial operation identity requires reconciliation',
                        'operation' => $current,
                        'path' => $path,
                    ];
                }
            }

            $markerUid = trim((string)($current['uid'] ?? $uid));
            $marker = wallet_financial_operation_marker_from_wallet($markerUid, (string)($current['operation_key'] ?? $operationKey));
            if (!empty($marker)) {
                $current['wallet_applied'] = true;
                $current['wallet_marker'] = $marker;
                if (empty($current['ledger_row']) && !empty($marker['ledger_row']) && is_array($marker['ledger_row'])) {
                    $current['ledger_row'] = $marker['ledger_row'];
                }
            }

            if ($currentOperation !== '' && $currentOperation !== $operationType && $currentStatus === 'COMPLETED') {
                return [
                    'ok' => true,
                    'duplicate' => true,
                    'completed' => true,
                    'conflicting_operation' => true,
                    'code' => 'FINANCIAL_OPERATION_ALREADY_COMPLETED',
                    'message' => 'Financial operation already completed',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            if ($currentStatus === 'RECONCILIATION_REQUIRED') {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
                    'message' => 'Financial operation requires manual reconciliation',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            if ($currentOperation !== '' && $currentOperation !== $operationType) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_CONFLICT',
                    'message' => 'This request already has a financial operation in progress or completed',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            if ($currentStatus === 'COMPLETED') {
                return [
                    'ok' => true,
                    'duplicate' => true,
                    'completed' => true,
                    'code' => 'FINANCIAL_OPERATION_ALREADY_COMPLETED',
                    'message' => 'Financial operation already completed',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            $isRetryable = $currentStatus === 'FAILED_RETRYABLE';
            $isActive = in_array($currentStatus, ['CLAIMED', 'APPLIED'], true);
            $isStale = wallet_financial_operation_is_stale($current, $now);
            $legacyWithoutLease = empty($current['lease_expires_at']) && empty($current['operation_key']);

            if ($isActive && !$isStale) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_IN_PROGRESS',
                    'message' => 'This financial operation is already being processed',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            if ($legacyWithoutLease && $currentStatus === 'CLAIMED' && empty($current['wallet_applied'])) {
                wallet_financial_operation_mark_reconciliation_record($path, (string)$res['etag'], $current, [
                    'last_error_code' => 'LEGACY_CLAIM_AMBIGUOUS',
                    'last_error_at' => $now,
                    'reconciliation_required' => true,
                ]);
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
                    'message' => 'Legacy financial operation state is ambiguous',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            if (!$isActive && !$isRetryable) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_CONFLICT',
                    'message' => 'Financial operation state is not retryable',
                    'operation' => $current,
                    'path' => $path,
                ];
            }

            $resumeSource = [
                'resume_from' => $currentStatus,
                'wallet_applied' => !empty($current['wallet_applied']),
            ];
        }

        $claim = array_merge($current, [
            'request_id' => $refId,
            'scope' => $scope,
            'operation_type' => $operationType,
            'operation_key' => $operationKey,
            'status' => 'CLAIMED',
            'uid' => $uid,
            'amount' => wallet_round_money($amount),
            'currency' => $currency,
            'ledger_id' => $ledgerId,
            'owner_token_hash' => hash('sha256', $owner),
            'attempts' => (int)($current['attempts'] ?? $current['attempt_count'] ?? 0) + 1,
            'attempt_count' => (int)($current['attempt_count'] ?? $current['attempts'] ?? 0) + 1,
            'created_at' => (int)($current['created_at'] ?? $now),
            'claimed_at' => $now,
            'lease_expires_at' => $now + $leaseSeconds,
            'updated_at' => $now,
            'meta' => $meta,
        ]);

        $save = fb_put_if_match($path, $claim, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }
        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_CLAIM_FAILED',
                'message' => 'Financial operation could not be locked',
            ];
        }

        $claim = wallet_financial_operation_with_runtime_fields($claim, $path, $owner, $resumeSource);

        return [
            'ok' => true,
            'claim' => $claim,
            'path' => $path,
        ];
    }

    return [
        'ok' => false,
        'code' => 'FINANCIAL_OPERATION_CONFLICT',
        'message' => 'Financial operation conflict. Please retry.',
    ];
}

function wallet_financial_operation_mark_reconciliation_record(string $path, string $etag, array $current, array $patch): bool
{
    $data = array_merge($current, $patch, [
        'status' => 'RECONCILIATION_REQUIRED',
        'updated_at' => wallet_now(),
    ]);
    $save = fb_put_if_match($path, $data, $etag);
    return !empty($save['ok']);
}

function wallet_financial_operation_claim_from_options(array $options): array
{
    $claim = $options['financial_operation'] ?? [];
    return is_array($claim) ? $claim : [];
}

function wallet_financial_operation_mutation_binding_error(
    array $claim,
    string $uid,
    float $amount,
    string $refId,
    bool $allowRelatedUid = false
): array {
    if ($claim === []) {
        return [];
    }

    $uid = trim($uid);
    $refId = trim($refId);
    $claimRef = trim((string)($claim['request_id'] ?? ''));
    if ($refId === '' || $claimRef === '' || !hash_equals($claimRef, $refId)) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_BINDING_CONFLICT',
            'message' => 'Wallet request reference does not match the financial operation',
        ];
    }

    if (abs(wallet_round_money((float)($claim['amount'] ?? -1)) - wallet_round_money($amount)) > 0.001) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_BINDING_CONFLICT',
            'message' => 'Wallet amount does not match the financial operation',
        ];
    }

    $allowedUids = [trim((string)($claim['uid'] ?? ''))];
    if ($allowRelatedUid) {
        $meta = is_array($claim['meta'] ?? null) ? (array)$claim['meta'] : [];
        foreach ([
            'actor_uid',
            'target_uid',
            'source_uid',
            'sender_uid',
            'receiver_uid',
            'subadmin_uid',
            'partner_uid',
        ] as $field) {
            $allowedUids[] = trim((string)($meta[$field] ?? ''));
        }
    }
    $allowedUids = array_values(array_unique(array_filter(
        $allowedUids,
        static fn(string $value): bool => $value !== ''
    )));

    if ($uid === '' || !in_array($uid, $allowedUids, true)) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_BINDING_CONFLICT',
            'message' => 'Wallet owner does not match the financial operation',
        ];
    }

    return [];
}

function wallet_financial_operation_currency_binding_error(array $claim, string $currency): array
{
    if ($claim === []) {
        return [];
    }

    $claimCurrency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
    $currency = wallet_normalize_currency_code($currency);
    if ($claimCurrency === '' || $currency === '' || $claimCurrency !== $currency) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_BINDING_CONFLICT',
            'message' => 'Wallet currency does not match the financial operation',
        ];
    }

    return [];
}

function wallet_financial_operation_mark(string $status, array $claim, array $patch = [], array $expectedStatuses = []): bool
{
    $path = trim((string)($claim['_path'] ?? ''));
    if ($path === '') {
        return true;
    }

    $ownerHash = wallet_financial_operation_owner_hash_from_claim($claim);
    $status = strtoupper(trim($status));
    $expectedStatuses = array_values(array_filter(array_map(
        static fn($item): string => strtoupper(trim((string)$item)),
        $expectedStatuses
    )));

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || empty($res['etag'])) {
            return false;
        }

        $current = is_array($res['value'] ?? null) ? $res['value'] : [];
        if ($current === []) {
            return false;
        }

        $currentOwnerHash = (string)($current['owner_token_hash'] ?? '');
        if ($ownerHash === '' || $currentOwnerHash === '' || !hash_equals($currentOwnerHash, $ownerHash)) {
            return false;
        }

        $currentStatus = strtoupper(trim((string)($current['status'] ?? '')));
        if ($expectedStatuses !== [] && !in_array($currentStatus, $expectedStatuses, true)) {
            return false;
        }

        $now = wallet_now();
        $data = array_merge($current, $patch, [
            'status' => $status,
            'updated_at' => $now,
        ]);

        if (!in_array($status, ['COMPLETED', 'RECONCILIATION_REQUIRED'], true)) {
            $data['lease_expires_at'] = $now + wallet_financial_operation_lease_seconds();
        }

        if ($status === 'COMPLETED') {
            $data['completed_at'] = (int)($data['completed_at'] ?? $now);
        }

        $save = fb_put_if_match($path, $data, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        return !empty($save['ok']);
    }

    return false;
}

function wallet_financial_operation_mark_failed(array $claim, string $code, string $message, array $patch = []): bool
{
    return wallet_financial_operation_mark('FAILED_RETRYABLE', $claim, array_merge($patch, [
        'last_error_code' => $code,
        'last_error_message' => substr($message, 0, 180),
        'last_error_at' => wallet_now(),
    ]), ['CLAIMED', 'APPLIED', 'FAILED_RETRYABLE']);
}

function wallet_financial_operation_mark_applied(array $claim, array $patch = []): bool
{
    return wallet_financial_operation_mark('APPLIED', $claim, $patch, ['CLAIMED', 'APPLIED', 'FAILED_RETRYABLE']);
}

function wallet_financial_operation_mark_completed(array $claim, array $patch = []): bool
{
    return wallet_financial_operation_mark('COMPLETED', $claim, array_merge($patch, [
        'completed_at' => wallet_now(),
    ]), ['CLAIMED', 'APPLIED', 'FAILED_RETRYABLE']);
}

function wallet_financial_operation_mark_reconciliation_required(array $claim, string $code, string $message, array $patch = []): bool
{
    return wallet_financial_operation_mark('RECONCILIATION_REQUIRED', $claim, array_merge($patch, [
        'reconciliation_required' => true,
        'last_error_code' => $code,
        'last_error_message' => substr($message, 0, 180),
        'last_error_at' => wallet_now(),
    ]), ['CLAIMED', 'APPLIED', 'FAILED_RETRYABLE', 'RECONCILIATION_REQUIRED']);
}

function wallet_financial_operation_owner_is_current(array $claim): bool
{
    $path = trim((string)($claim['_path'] ?? ''));
    if ($path === '') {
        return true;
    }

    $ownerHash = wallet_financial_operation_owner_hash_from_claim($claim);
    if ($ownerHash === '') {
        return false;
    }

    $row = fb_get($path);
    if (!is_array($row)) {
        return false;
    }

    $currentOwnerHash = (string)($row['owner_token_hash'] ?? '');
    return $currentOwnerHash !== '' && hash_equals($currentOwnerHash, $ownerHash);
}

function wallet_operation_duplicate_result(array $operation, string $message = 'Financial operation already completed'): array
{
    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => $message,
        'idempotent_replay' => true,
        'ledger_id' => (string)($operation['ledger_id'] ?? ''),
        'available_balance' => wallet_round_money((float)($operation['after_available'] ?? 0)),
        'hold_balance' => wallet_round_money((float)($operation['after_hold'] ?? 0)),
    ];
}

function wallet_create_ledger_full_checked(string $uid, array $row): array
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

    $path = 'WALLET_LEDGER/' . $uid . '/' . wallet_month_key((int)$row['created_at']) . '/' . $ledgerId;

    for ($i = 0; $i < 5; $i++) {
        $current = fb_get_with_etag($path);
        if (empty($current['ok']) || empty($current['etag'])) {
            break;
        }

        $existing = $current['value'] ?? null;
        if ($existing !== null) {
            if (is_array($existing) && wallet_financial_operation_ledger_matches($existing, $row)) {
                return [
                    'ok' => true,
                    'idempotent_replay' => true,
                    'ledger_id' => $ledgerId,
                    'ledger_path' => $path,
                    'ledger_row' => $existing,
                ];
            }

            return [
                'ok' => false,
                'code' => 'LEDGER_CONFLICT',
                'message' => 'Existing wallet ledger does not match this operation',
                'ledger_id' => $ledgerId,
                'ledger_path' => $path,
                'ledger_row' => $row,
            ];
        }

        $save = fb_put_if_match($path, $row, (string)$current['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        if (!empty($save['ok'])) {
            return [
                'ok' => true,
                'ledger_id' => $ledgerId,
                'ledger_path' => $path,
                'ledger_row' => $row,
            ];
        }
        break;
    }

    return [
        'ok' => false,
        'code' => 'LEDGER_WRITE_FAILED',
        'message' => 'Wallet ledger could not be saved',
        'ledger_id' => $ledgerId,
        'ledger_path' => $path,
        'ledger_row' => $row,
    ];
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
    $res = wallet_create_ledger_full_checked($uid, $row);
    return (string)($res['ledger_id'] ?? '');
}

function wallet_financial_operation_side_ledger_id(string $refId, string $operationType, string $side): string
{
    $side = strtoupper(preg_replace('/[^A-Z0-9_]+/', '_', trim($side)) ?? '');
    return wallet_financial_operation_ledger_id($refId, $operationType . '_' . ($side !== '' ? $side : 'SIDE'));
}

function wallet_financial_operation_side_done(array $claim, string $step): bool
{
    $step = trim($step);
    return $step !== '' && !empty($claim[$step]) && !empty($claim[$step . '_ledger_written']);
}

function wallet_apply_available_delta_with_operation(
    array $claim,
    string $uid,
    float $amount,
    string $direction,
    string $refId,
    string $ledgerType,
    string $note,
    array $extraLedger,
    string $step
): array {
    $uid = trim($uid);
    $refId = trim($refId);
    $ledgerType = strtoupper(trim($ledgerType));
    $step = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($step))) ?? '';
    $direction = strtoupper(trim($direction));
    $amount = wallet_round_money($amount);

    if ($uid === '' || $refId === '' || $ledgerType === '' || $step === '' || $amount <= 0 || !in_array($direction, ['CREDIT', 'DEBIT'], true)) {
        return [
            'ok' => false,
            'code' => 'INVALID_WALLET_OPERATION',
            'message' => 'Wallet operation data is invalid',
        ];
    }

    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId, true);
    if ($bindingError !== []) {
        return $bindingError;
    }

    if (wallet_financial_operation_side_done($claim, $step)) {
        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet operation side already completed',
            'ledger_id' => (string)($claim[$step . '_ledger_id'] ?? ''),
            'available_balance' => wallet_round_money((float)($claim[$step . '_after_available'] ?? $claim['after_available'] ?? 0)),
            'before_available' => wallet_round_money((float)($claim[$step . '_before_available'] ?? $claim['before_available'] ?? 0)),
            'after_available' => wallet_round_money((float)($claim[$step . '_after_available'] ?? $claim['after_available'] ?? 0)),
            'before_hold' => wallet_round_money((float)($claim[$step . '_before_hold'] ?? $claim['before_hold'] ?? 0)),
            'after_hold' => wallet_round_money((float)($claim[$step . '_after_hold'] ?? $claim['after_hold'] ?? 0)),
            'idempotent_replay' => true,
        ];
    }

    if (!wallet_financial_operation_owner_is_current($claim)) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
            'message' => 'Financial operation ownership changed',
        ];
    }

    $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $ledgerType));
    $ledgerId = trim((string)($extraLedger['ledger_id'] ?? ''));
    if ($ledgerId === '') {
        $ledgerId = wallet_financial_operation_side_ledger_id($refId, (string)($claim['operation_type'] ?? $ledgerType), $step);
    }

    $marker = wallet_financial_operation_marker_from_wallet($uid, $operationKey);
    if ($marker !== []) {
        return wallet_financial_operation_recover_wallet_marker($uid, $claim, $marker, $step);
    }

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);
        if (!($res['ok'] ?? false) || !is_array($res['value'] ?? null) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        if (!wallet_financial_operation_owner_is_current($claim)) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed',
            ];
        }

        $wallet = $res['value'];
        $resolvedWalletCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $resolvedWalletCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }
        $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
        if ($snapshotMarker !== []) {
            return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker, $step);
        }
        $now = wallet_now();
        $walletCurrency = wallet_normalize_currency_code(
            $extraLedger['currency'] ?? $extraLedger['wallet_currency'] ?? '',
            $resolvedWalletCurrency
        );
        $beforeAvailable = wallet_round_money((float)($wallet['available_balance'] ?? 0));
        $beforeHold = wallet_round_money((float)($wallet['hold_balance'] ?? 0));

        if ($direction === 'DEBIT' && $beforeAvailable < $amount) {
            return [
                'ok' => false,
                'code' => 'INSUFFICIENT_BALANCE',
                'message' => 'Not enough available balance',
                'available_balance' => $beforeAvailable,
                'required_amount' => $amount,
            ];
        }

        $afterAvailable = $direction === 'CREDIT'
            ? wallet_round_money($beforeAvailable + $amount)
            : wallet_round_money($beforeAvailable - $amount);

        $ledgerRow = array_merge([
            'ledger_id' => $ledgerId,
            'type' => $ledgerType,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $walletCurrency,
            'wallet_currency' => $walletCurrency,
            'before_available' => $beforeAvailable,
            'after_available' => $afterAvailable,
            'before_hold' => $beforeHold,
            'after_hold' => $beforeHold,
            'ref_id' => $refId,
            'request_id' => $refId,
            'note' => $note,
            'created_at' => $now,
        ], $extraLedger, [
            'ledger_id' => $ledgerId,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $walletCurrency,
            'wallet_currency' => $walletCurrency,
            'before_available' => $beforeAvailable,
            'after_available' => $afterAvailable,
            'before_hold' => $beforeHold,
            'after_hold' => $beforeHold,
            'ref_id' => $refId,
            'request_id' => (string)($extraLedger['request_id'] ?? $refId),
            'created_at' => $now,
        ]);

        $updated = $wallet;
        $updated['available_balance'] = $afterAvailable;
        $updated['updated_at'] = $now;
        if ($walletCurrency !== '') {
            $updated['currency'] = $walletCurrency;
            $updated['wallet_currency'] = $walletCurrency;
        }
        $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
        $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
            'step' => $step,
            'before_available' => $beforeAvailable,
            'after_available' => $afterAvailable,
            'before_hold' => $beforeHold,
            'after_hold' => $beforeHold,
        ]);

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }
        if (empty($save['ok'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to update wallet balance',
            ];
        }

        if (!wallet_financial_operation_mark_applied($claim, [
            $step => true,
            $step . '_ledger_id' => $ledgerId,
            $step . '_ledger_row' => $ledgerRow,
            $step . '_before_available' => $beforeAvailable,
            $step . '_after_available' => $afterAvailable,
            $step . '_before_hold' => $beforeHold,
            $step . '_after_hold' => $beforeHold,
            'wallet_applied' => true,
        ])) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed after wallet mutation',
            ];
        }

        $ledger = wallet_create_ledger_full_checked($uid, $ledgerRow);
        if (empty($ledger['ok'])) {
            wallet_financial_operation_mark_failed($claim, 'LEDGER_WRITE_FAILED', 'Wallet side applied but ledger could not be saved', [
                $step => true,
                $step . '_ledger_id' => $ledgerId,
                $step . '_ledger_row' => $ledgerRow,
                'wallet_applied' => true,
            ]);
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'Wallet side ledger could not be saved',
                'available_balance' => $afterAvailable,
            ];
        }

        if (!wallet_financial_operation_mark_applied($claim, [
            $step => true,
            $step . '_ledger_written' => true,
            $step . '_ledger_id' => (string)($ledger['ledger_id'] ?? $ledgerId),
            $step . '_ledger_row' => $ledgerRow,
            'wallet_applied' => true,
        ])) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed after ledger write',
            ];
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet operation side completed',
            'ledger_id' => (string)($ledger['ledger_id'] ?? $ledgerId),
            'available_balance' => $afterAvailable,
            'before_available' => $beforeAvailable,
            'after_available' => $afterAvailable,
            'before_hold' => $beforeHold,
            'after_hold' => $beforeHold,
            'currency' => $walletCurrency,
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_financial_operation_claim_wallet_applied(array $claim): bool
{
    return !empty($claim['_wallet_already_applied'])
        || !empty($claim['wallet_applied'])
        || !empty($claim['ledger_written'])
        || !empty($claim['request_finalized']);
}

function wallet_financial_operation_prepare_marker(array $claim, array $ledgerRow, array $extra = []): array
{
    return array_merge([
        'operation_key' => (string)($claim['operation_key'] ?? ''),
        'request_id' => (string)($claim['request_id'] ?? $ledgerRow['ref_id'] ?? ''),
        'operation_type' => (string)($claim['operation_type'] ?? $ledgerRow['type'] ?? ''),
        'ledger_id' => (string)($ledgerRow['ledger_id'] ?? $claim['ledger_id'] ?? ''),
        'amount' => wallet_round_money((float)($ledgerRow['amount'] ?? $claim['amount'] ?? 0)),
        'currency' => (string)($ledgerRow['currency'] ?? $claim['currency'] ?? ''),
        'applied_at' => wallet_now(),
        'ledger_row' => $ledgerRow,
    ], $extra);
}

function wallet_financial_operation_ledger_matches(array $existing, array $expected): bool
{
    foreach (['ledger_id', 'type', 'direction', 'ref_id'] as $key) {
        if ((string)($existing[$key] ?? '') !== (string)($expected[$key] ?? '')) {
            return false;
        }
    }

    $existingAmount = wallet_round_money((float)($existing['amount'] ?? 0));
    $expectedAmount = wallet_round_money((float)($expected['amount'] ?? 0));
    if ($existingAmount !== $expectedAmount) {
        return false;
    }

    $existingCurrency = wallet_normalize_currency_code((string)($existing['currency'] ?? $existing['wallet_currency'] ?? 'BDT'));
    $expectedCurrency = wallet_normalize_currency_code((string)($expected['currency'] ?? $expected['wallet_currency'] ?? 'BDT'));

    return $existingCurrency === $expectedCurrency;
}

function wallet_financial_operation_marker_from_snapshot(array $wallet, string $operationKey): array
{
    $markers = $wallet['financial_operations'] ?? [];
    if (!is_array($markers)) {
        return [];
    }

    $marker = $markers[$operationKey] ?? [];
    return is_array($marker) ? $marker : [];
}

function wallet_financial_operation_marker_matches_claim(string $uid, array $claim, array $marker): bool
{
    $expectedStrings = [
        'operation_key' => (string)($claim['operation_key'] ?? ''),
        'request_id' => (string)($claim['request_id'] ?? ''),
        'operation_type' => (string)($claim['operation_type'] ?? ''),
    ];

    foreach ($expectedStrings as $field => $expected) {
        if ($expected === '' || (string)($marker[$field] ?? '') !== $expected) {
            return false;
        }
    }

    $markerUid = trim((string)($marker['uid'] ?? ''));
    if ($markerUid !== '' && $markerUid !== trim($uid)) {
        return false;
    }

    if (abs(wallet_round_money((float)($marker['amount'] ?? 0)) - wallet_round_money((float)($claim['amount'] ?? 0))) > 0.001) {
        return false;
    }

    $markerCurrency = wallet_normalize_currency_code((string)($marker['currency'] ?? ''));
    $claimCurrency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
    return $markerCurrency !== '' && $claimCurrency !== '' && $markerCurrency === $claimCurrency;
}

function wallet_financial_operation_recover_wallet_marker(
    string $uid,
    array $claim,
    array $marker,
    string $step = ''
): array {
    $step = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($step))) ?? '';

    if (!wallet_financial_operation_marker_matches_claim($uid, $claim, $marker)) {
        wallet_financial_operation_mark_reconciliation_required(
            $claim,
            'WALLET_MARKER_CONFLICT',
            'Wallet mutation marker does not match the claimed operation',
            ['wallet_applied' => true]
        );
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
            'message' => 'Wallet operation marker conflict requires reconciliation',
        ];
    }

    $ledgerRow = is_array($marker['ledger_row'] ?? null) ? $marker['ledger_row'] : [];
    if ($ledgerRow === []) {
        wallet_financial_operation_mark_reconciliation_required(
            $claim,
            'WALLET_MARKER_LEDGER_MISSING',
            'Wallet mutation marker is missing deterministic ledger data',
            ['wallet_applied' => true]
        );
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
            'message' => 'Wallet operation requires reconciliation',
        ];
    }

    $ledger = wallet_create_ledger_full_checked($uid, $ledgerRow);
    if (empty($ledger['ok'])) {
        $code = (string)($ledger['code'] ?? 'LEDGER_WRITE_FAILED');
        if ($code === 'LEDGER_CONFLICT') {
            wallet_financial_operation_mark_reconciliation_required(
                $claim,
                'LEDGER_CONFLICT',
                'Existing deterministic ledger conflicts with wallet marker',
                ['wallet_applied' => true, 'ledger_row' => $ledgerRow]
            );
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
                'message' => 'Wallet operation ledger conflict requires reconciliation',
            ];
        }

        wallet_financial_operation_mark_failed($claim, 'LEDGER_WRITE_FAILED', 'Wallet marker ledger could not be repaired', [
            'wallet_applied' => true,
            'ledger_row' => $ledgerRow,
        ]);
        return [
            'ok' => false,
            'code' => 'LEDGER_WRITE_FAILED',
            'message' => 'Wallet operation ledger could not be repaired',
        ];
    }

    $patch = [
        'wallet_applied' => true,
        'ledger_written' => true,
        'ledger_id' => (string)($ledger['ledger_id'] ?? $marker['ledger_id'] ?? ''),
        'ledger_path' => (string)($ledger['ledger_path'] ?? ''),
        'ledger_row' => $ledgerRow,
        'before_available' => wallet_round_money((float)($marker['before_available'] ?? 0)),
        'after_available' => wallet_round_money((float)($marker['after_available'] ?? 0)),
        'before_hold' => wallet_round_money((float)($marker['before_hold'] ?? 0)),
        'after_hold' => wallet_round_money((float)($marker['after_hold'] ?? 0)),
    ];
    if ($step !== '') {
        $patch[$step] = true;
        $patch[$step . '_ledger_written'] = true;
        $patch[$step . '_ledger_id'] = $patch['ledger_id'];
        $patch[$step . '_ledger_row'] = $ledgerRow;
        $patch[$step . '_before_available'] = $patch['before_available'];
        $patch[$step . '_after_available'] = $patch['after_available'];
        $patch[$step . '_before_hold'] = $patch['before_hold'];
        $patch[$step . '_after_hold'] = $patch['after_hold'];
    }

    if (!wallet_financial_operation_mark_applied($claim, $patch)) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
            'message' => 'Financial operation ownership changed',
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet operation recovered from deterministic marker',
        'idempotent_replay' => true,
        'ledger_id' => $patch['ledger_id'],
        'available_balance' => $patch['after_available'],
        'hold_balance' => $patch['after_hold'],
        'before_available' => $patch['before_available'],
        'after_available' => $patch['after_available'],
        'before_hold' => $patch['before_hold'],
        'after_hold' => $patch['after_hold'],
        'currency' => wallet_normalize_currency_code((string)($marker['currency'] ?? $claim['currency'] ?? '')),
    ];
}

function wallet_financial_operation_repair_applied_ledger(string $uid, array $claim): array
{
    if (!wallet_financial_operation_owner_is_current($claim)) {
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
            'message' => 'Financial operation ownership changed',
        ];
    }

    $ledgerRow = $claim['ledger_row'] ?? [];
    if (!is_array($ledgerRow) || $ledgerRow === []) {
        wallet_financial_operation_mark_reconciliation_required($claim, 'LEDGER_REPAIR_DATA_MISSING', 'Wallet was applied but ledger repair data is missing', [
            'wallet_applied' => true,
        ]);
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
            'message' => 'Wallet was applied but ledger repair data is missing',
        ];
    }

    $ledgerRow['ledger_id'] = (string)($ledgerRow['ledger_id'] ?? $claim['ledger_id'] ?? '');
    if ($ledgerRow['ledger_id'] === '') {
        wallet_financial_operation_mark_reconciliation_required($claim, 'LEDGER_ID_MISSING', 'Wallet was applied but deterministic ledger id is missing', [
            'wallet_applied' => true,
            'ledger_row' => $ledgerRow,
        ]);
        return [
            'ok' => false,
            'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
            'message' => 'Wallet was applied but deterministic ledger id is missing',
        ];
    }

    $createdAt = (int)($ledgerRow['created_at'] ?? wallet_now());
    $path = 'WALLET_LEDGER/' . $uid . '/' . wallet_month_key($createdAt) . '/' . $ledgerRow['ledger_id'];
    $existing = fb_get($path);

    if (is_array($existing)) {
        if (!wallet_financial_operation_ledger_matches($existing, $ledgerRow)) {
            wallet_financial_operation_mark_reconciliation_required($claim, 'LEDGER_CONFLICT', 'Existing ledger row does not match financial operation', [
                'wallet_applied' => true,
                'ledger_conflict_path' => $path,
                'ledger_row' => $ledgerRow,
            ]);
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_RECONCILIATION_REQUIRED',
                'message' => 'Existing ledger row conflicts with wallet operation',
            ];
        }

        wallet_financial_operation_mark_applied($claim, [
            'wallet_applied' => true,
            'ledger_written' => true,
            'ledger_id' => $ledgerRow['ledger_id'],
            'ledger_path' => $path,
            'ledger_row' => $ledgerRow,
        ]);

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet operation ledger already repaired',
            'ledger_id' => $ledgerRow['ledger_id'],
            'available_balance' => wallet_round_money((float)($claim['after_available'] ?? 0)),
            'hold_balance' => wallet_round_money((float)($claim['after_hold'] ?? 0)),
        ];
    }

    $ledger = wallet_create_ledger_full_checked($uid, $ledgerRow);
    if (empty($ledger['ok'])) {
        wallet_financial_operation_mark_failed($claim, 'LEDGER_WRITE_FAILED', 'Wallet applied but ledger could not be repaired', [
            'wallet_applied' => true,
            'ledger_row' => $ledgerRow,
        ]);
        return [
            'ok' => false,
            'code' => 'LEDGER_WRITE_FAILED',
            'message' => 'Wallet applied but ledger could not be repaired',
        ];
    }

    wallet_financial_operation_mark_applied($claim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'ledger_id' => (string)($ledger['ledger_id'] ?? $ledgerRow['ledger_id']),
        'ledger_path' => (string)($ledger['ledger_path'] ?? $path),
        'ledger_row' => $ledgerRow,
    ]);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Wallet operation ledger repaired',
        'ledger_id' => (string)($ledger['ledger_id'] ?? $ledgerRow['ledger_id']),
        'available_balance' => wallet_round_money((float)($claim['after_available'] ?? 0)),
        'hold_balance' => wallet_round_money((float)($claim['after_hold'] ?? 0)),
    ];
}

function wallet_credit_available(
    string $uid,
    float $amount,
    string $refId,
    string $type,
    string $note,
    array $extraLedger = [],
    array $options = []
): array {
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
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

        if ($claim !== [] && !wallet_financial_operation_owner_is_current($claim)) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed',
            ];
        }

        $wallet = $res['value'];
        if ($claim !== []) {
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $walletCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $walletCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }

        $beforeAvailable = (float)($wallet['available_balance'] ?? 0);
        $beforeHold = (float)($wallet['hold_balance'] ?? 0);

        $afterAvailable = wallet_round_money($beforeAvailable + $amount);

        $updated = $wallet;
        $updated['available_balance'] = $afterAvailable;
        $updated['updated_at'] = $now;

        $creditCurrency = wallet_normalize_currency_code(
            $extraLedger['currency']
            ?? $extraLedger['wallet_currency']
            ?? ''
        );
        if ($creditCurrency !== '') {
            $updated['currency'] = $creditCurrency;
            $updated['wallet_currency'] = $creditCurrency;
        }

        $ledgerRow = array_merge([
            'type' => $type,
            'direction' => 'CREDIT',
            'amount' => wallet_round_money($amount),
            'currency' => $walletCurrency,
            'wallet_currency' => $walletCurrency,
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
            'before_hold' => wallet_round_money($beforeHold),
            'after_hold' => wallet_round_money($beforeHold),
            'ref_id' => $refId,
            'note' => $note,
            'created_at' => $now,
        ], $extraLedger);

        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            if (empty($ledgerRow['ledger_id'])) {
                $ledgerRow['ledger_id'] = (string)($claim['ledger_id'] ?? wallet_financial_operation_ledger_id($refId, $type));
            }
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($beforeAvailable),
                'after_available' => $afterAvailable,
                'before_hold' => wallet_round_money($beforeHold),
                'after_hold' => wallet_round_money($beforeHold),
            ]);
        }

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

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'before_available' => wallet_round_money($beforeAvailable),
                'after_available' => $afterAvailable,
                'before_hold' => wallet_round_money($beforeHold),
                'after_hold' => wallet_round_money($beforeHold),
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }
        }

        $ledger = $claim !== []
            ? wallet_create_ledger_full_checked($uid, $ledgerRow)
            : ['ok' => true, 'ledger_id' => create_wallet_ledger_full($uid, $ledgerRow)];

        if (empty($ledger['ok'])) {
            if ($claim !== []) {
                wallet_financial_operation_mark_failed(
                    $claim,
                    'LEDGER_WRITE_FAILED',
                    'Wallet credited but ledger could not be saved',
                    ['wallet_applied' => true, 'ledger_row' => $ledgerRow]
                );
            }
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'Wallet credited but ledger could not be saved',
                'available_balance' => $afterAvailable,
                'hold_balance' => wallet_round_money($beforeHold),
            ];
        }

        $ledgerId = (string)($ledger['ledger_id'] ?? '');
        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => $ledgerId,
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet credited successfully',
            'ledger_id' => $ledgerId,
            'available_balance' => $afterAvailable,
            'hold_balance' => wallet_round_money($beforeHold),
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
            'currency' => $creditCurrency !== '' ? $creditCurrency : $walletCurrency,
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
    array $extraLedger = [],
    array $options = []
): array {
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
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

        if ($claim !== [] && !wallet_financial_operation_owner_is_current($claim)) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed',
            ];
        }

        $wallet = $res['value'];
        if ($claim !== []) {
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $walletCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $walletCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }
        $claimCurrency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
        if ($claimCurrency !== '') {
            $walletCurrency = $claimCurrency;
        }

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

        $ledgerId = '';
        if ($claim !== []) {
            $ledgerId = trim((string)($extraLedger['ledger_id'] ?? $claim['ledger_id'] ?? ''));
            if ($ledgerId === '') {
                $ledgerId = wallet_financial_operation_ledger_id($refId, (string)($claim['operation_type'] ?? $type));
            }
        }

        $ledgerRow = array_merge([
            'type' => $type,
            'direction' => 'DEBIT',
            'amount' => wallet_round_money($amount),
            'currency' => $walletCurrency,
            'wallet_currency' => $walletCurrency,
            'before_available' => wallet_round_money($beforeAvailable),
            'after_available' => $afterAvailable,
            'before_hold' => wallet_round_money($beforeHold),
            'after_hold' => wallet_round_money($beforeHold),
            'ref_id' => $refId,
            'request_id' => $refId,
            'note' => $note,
            'created_at' => $now,
        ], $extraLedger);

        if ($ledgerId !== '') {
            $ledgerRow['ledger_id'] = $ledgerId;
        }

        $updated = $wallet;
        $updated['available_balance'] = $afterAvailable;
        $updated['updated_at'] = $now;
        if ($claim !== []) {
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($beforeAvailable),
                'after_available' => $afterAvailable,
                'before_hold' => wallet_round_money($beforeHold),
                'after_hold' => wallet_round_money($beforeHold),
            ]);
        }

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

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_id' => (string)($ledgerRow['ledger_id'] ?? $ledgerId),
                'ledger_row' => $ledgerRow,
                'before_available' => wallet_round_money($beforeAvailable),
                'after_available' => $afterAvailable,
                'before_hold' => wallet_round_money($beforeHold),
                'after_hold' => wallet_round_money($beforeHold),
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }

            $ledger = wallet_create_ledger_full_checked($uid, $ledgerRow);
            if (empty($ledger['ok'])) {
                wallet_financial_operation_mark_failed($claim, 'LEDGER_WRITE_FAILED', 'Wallet debited but ledger could not be saved', [
                    'wallet_applied' => true,
                    'ledger_row' => $ledgerRow,
                ]);
                return [
                    'ok' => false,
                    'code' => 'LEDGER_WRITE_FAILED',
                    'message' => 'Wallet ledger could not be saved',
                    'available_balance' => $afterAvailable,
                    'hold_balance' => wallet_round_money($beforeHold),
                ];
            }

            $ledgerId = (string)($ledger['ledger_id'] ?? $ledgerRow['ledger_id'] ?? $ledgerId);
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => $ledgerId,
                'ledger_path' => (string)($ledger['ledger_path'] ?? ''),
                'ledger_row' => $ledgerRow,
                'before_available' => wallet_round_money($beforeAvailable),
                'after_available' => $afterAvailable,
                'before_hold' => wallet_round_money($beforeHold),
                'after_hold' => wallet_round_money($beforeHold),
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        } else {
            $ledgerId = create_wallet_ledger_full($uid, $ledgerRow);
        }

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

function wallet_hold_amount(string $uid, float $amount, string $refId, string $type = 'TOPUP_HOLD', array $options = []): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
    }
    $extraLedger = is_array($options['ledger_extra'] ?? null) ? $options['ledger_extra'] : [];

    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag('USER_WALLETS/' . $uid);

        if (!$res['ok'] || !is_array($res['value']) || empty($res['etag'])) {
            return [
                'ok' => false,
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found or unavailable',
            ];
        }

        if ($claim !== [] && !wallet_financial_operation_owner_is_current($claim)) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed',
            ];
        }

        $wallet = $res['value'];
        if ($claim !== []) {
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $resolvedCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $resolvedCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }
        $currency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
        if ($currency === '') {
            $currency = $resolvedCurrency;
        }

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

        $ledgerId = '';
        if ($claim !== []) {
            $ledgerId = trim((string)($extraLedger['ledger_id'] ?? $claim['ledger_id'] ?? ''));
            if ($ledgerId === '') {
                $ledgerId = wallet_financial_operation_ledger_id($refId, (string)($claim['operation_type'] ?? $type));
            }
        }

        $ledgerRow = array_merge([
            'type' => $type,
            'direction' => 'HOLD',
            'amount' => wallet_round_money($amount),
            'currency' => $currency,
            'wallet_currency' => $currency,
            'before_available' => wallet_round_money($available),
            'after_available' => wallet_round_money($available - $amount),
            'before_hold' => wallet_round_money($hold),
            'after_hold' => wallet_round_money($hold + $amount),
            'ref_id' => $refId,
            'request_id' => $refId,
            'note' => 'Amount reserved for request',
            'created_at' => $now,
        ], $extraLedger);

        if ($ledgerId !== '') {
            $ledgerRow['ledger_id'] = $ledgerId;
        }

        $updated = $wallet;
        $updated['available_balance'] = (float)$ledgerRow['after_available'];
        $updated['hold_balance'] = (float)$ledgerRow['after_hold'];
        $updated['updated_at'] = $now;
        if ($claim !== []) {
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($available),
                'after_available' => $updated['available_balance'],
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ]);
        }

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

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_id' => (string)($ledgerRow['ledger_id'] ?? $ledgerId),
                'ledger_row' => $ledgerRow,
                'before_available' => wallet_round_money($available),
                'after_available' => $updated['available_balance'],
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }

            $ledger = wallet_create_ledger_full_checked($uid, $ledgerRow);
            if (empty($ledger['ok'])) {
                wallet_financial_operation_mark_failed($claim, 'LEDGER_WRITE_FAILED', 'Wallet hold applied but ledger could not be saved', [
                    'wallet_applied' => true,
                    'ledger_row' => $ledgerRow,
                ]);
                return [
                    'ok' => false,
                    'code' => 'LEDGER_WRITE_FAILED',
                    'message' => 'Wallet ledger could not be saved',
                    'available_balance' => $updated['available_balance'],
                    'hold_balance' => $updated['hold_balance'],
                ];
            }

            $ledgerId = (string)($ledger['ledger_id'] ?? $ledgerRow['ledger_id'] ?? $ledgerId);
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => $ledgerId,
                'ledger_path' => (string)($ledger['ledger_path'] ?? ''),
                'ledger_row' => $ledgerRow,
                'before_available' => wallet_round_money($available),
                'after_available' => $updated['available_balance'],
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        } else {
            $ledgerId = create_wallet_ledger_full($uid, $ledgerRow);
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet amount held successfully',
            'ledger_id' => (string)$ledgerId,
            'available_balance' => $updated['available_balance'],
            'hold_balance' => $updated['hold_balance'],
            'before_available' => wallet_round_money($available),
            'after_available' => $updated['available_balance'],
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'currency' => $currency,
        ];
    }

    return [
        'ok' => false,
        'code' => 'WALLET_CONFLICT',
        'message' => 'Wallet update conflict, please retry',
    ];
}

function wallet_refund_hold(string $uid, float $amount, string $refId, string $type = 'TOPUP_REFUND', array $options = []): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
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
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $resolvedCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $resolvedCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }
        $currency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
        if ($currency === '') {
            $currency = $resolvedCurrency;
        }

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalRefund = (float)($wallet['total_refund'] ?? 0);

        $updated = $wallet;
        $updated['available_balance'] = wallet_round_money($available + $amount);
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_refund'] = wallet_round_money($totalRefund + $amount);
        $updated['updated_at'] = $now;

        $ledgerRow = [
            'type' => $type,
            'direction' => 'RELEASE_HOLD',
            'amount' => wallet_round_money($amount),
            'currency' => $currency,
            'wallet_currency' => $currency,
            'before_available' => wallet_round_money($available),
            'after_available' => $updated['available_balance'],
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Refunded reserved amount',
            'created_at' => $now,
        ];
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $ledgerRow['ledger_id'] = (string)($claim['ledger_id'] ?? wallet_financial_operation_ledger_id($refId, $type));
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($available),
                'after_available' => $updated['available_balance'],
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ]);
        }

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

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'before_available' => wallet_round_money($available),
                'after_available' => $updated['available_balance'],
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }
        }

        $ledger = $claim !== []
            ? wallet_create_ledger_full_checked($uid, $ledgerRow)
            : ['ok' => true, 'ledger_id' => create_wallet_ledger_full($uid, $ledgerRow)];
        if (empty($ledger['ok'])) {
            if ($claim !== []) {
                wallet_financial_operation_mark_failed(
                    $claim,
                    'LEDGER_WRITE_FAILED',
                    'Wallet refunded but ledger could not be saved',
                    ['wallet_applied' => true, 'ledger_row' => $ledgerRow]
                );
            }
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'Wallet refunded but ledger could not be saved',
                'available_balance' => $updated['available_balance'],
                'hold_balance' => $updated['hold_balance'],
            ];
        }
        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet refund successful',
            'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
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

function wallet_settle_hold(string $uid, float $amount, string $refId, string $type = 'TOPUP_SETTLE', array $options = []): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
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
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $resolvedCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $resolvedCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }
        $currency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
        if ($currency === '') {
            $currency = $resolvedCurrency;
        }

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalTopup = (float)($wallet['total_topup_spent'] ?? 0);

        $updated = $wallet;
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_topup_spent'] = wallet_round_money($totalTopup + $amount);
        $updated['updated_at'] = $now;

        $ledgerRow = [
            'type' => $type,
            'direction' => 'DEBIT_FINAL',
            'amount' => wallet_round_money($amount),
            'currency' => $currency,
            'wallet_currency' => $currency,
            'before_available' => wallet_round_money($available),
            'after_available' => wallet_round_money($available),
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Reserved amount settled after success',
            'created_at' => $now,
        ];
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $ledgerRow['ledger_id'] = (string)($claim['ledger_id'] ?? wallet_financial_operation_ledger_id($refId, $type));
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($available),
                'after_available' => wallet_round_money($available),
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ]);
        }

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

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'before_available' => wallet_round_money($available),
                'after_available' => wallet_round_money($available),
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }
        }

        $ledger = $claim !== []
            ? wallet_create_ledger_full_checked($uid, $ledgerRow)
            : ['ok' => true, 'ledger_id' => create_wallet_ledger_full($uid, $ledgerRow)];
        if (empty($ledger['ok'])) {
            if ($claim !== []) {
                wallet_financial_operation_mark_failed(
                    $claim,
                    'LEDGER_WRITE_FAILED',
                    'Wallet settled but ledger could not be saved',
                    ['wallet_applied' => true, 'ledger_row' => $ledgerRow]
                );
            }
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'Wallet settled but ledger could not be saved',
                'available_balance' => wallet_round_money($available),
                'hold_balance' => $updated['hold_balance'],
            ];
        }
        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Wallet settled successfully',
            'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
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

function wallet_settle_hold_mfs(string $uid, float $amount, string $refId, string $type = 'MFS_SUCCESS', array $options = []): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
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

        if ($claim !== [] && !wallet_financial_operation_owner_is_current($claim)) {
            return [
                'ok' => false,
                'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                'message' => 'Financial operation ownership changed',
            ];
        }

        $wallet = $res['value'];
        if ($claim !== []) {
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $resolvedCurrency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $resolvedCurrency);
        if ($currencyError !== []) {
            return $currencyError;
        }
        $currency = wallet_normalize_currency_code((string)($claim['currency'] ?? ''));
        if ($currency === '') {
            $currency = $resolvedCurrency;
        }

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalMfs = (float)($wallet['total_mfs_spent'] ?? 0);

        $updated = $wallet;
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_mfs_spent'] = wallet_round_money($totalMfs + $amount);
        $updated['updated_at'] = $now;

        $ledgerRow = [
            'type' => $type,
            'direction' => 'DEBIT_HOLD',
            'amount' => wallet_round_money($amount),
            'currency' => $currency,
            'wallet_currency' => $currency,
            'before_available' => wallet_round_money($available),
            'after_available' => wallet_round_money($available),
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'MFS request successful',
            'created_at' => $now,
        ];
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $ledgerRow['ledger_id'] = (string)($claim['ledger_id'] ?? wallet_financial_operation_ledger_id($refId, $type));
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($available),
                'after_available' => wallet_round_money($available),
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ]);
        }

        $save = fb_put_if_match('USER_WALLETS/' . $uid, $updated, $res['etag']);

        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }

        if (!($save['ok'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'WALLET_UPDATE_FAILED',
                'message' => 'Failed to settle MFS hold',
            ];
        }

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'before_available' => wallet_round_money($available),
                'after_available' => wallet_round_money($available),
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }
        }

        $ledger = $claim !== []
            ? wallet_create_ledger_full_checked($uid, $ledgerRow)
            : ['ok' => true, 'ledger_id' => create_wallet_ledger_full($uid, $ledgerRow)];
        if (empty($ledger['ok'])) {
            if ($claim !== []) {
                wallet_financial_operation_mark_failed(
                    $claim,
                    'LEDGER_WRITE_FAILED',
                    'MFS wallet settled but ledger could not be saved',
                    ['wallet_applied' => true, 'ledger_row' => $ledgerRow]
                );
            }
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'MFS wallet settled but ledger could not be saved',
                'available_balance' => wallet_round_money($available),
                'hold_balance' => $updated['hold_balance'],
            ];
        }
        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'MFS wallet settled successfully',
            'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
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

function wallet_settle_hold_bundle(string $uid, float $amount, string $refId, string $type = 'BUNDLE_SETTLE', array $options = []): array
{
    if ($amount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_AMOUNT',
            'message' => 'Amount must be greater than zero',
        ];
    }

    $claim = wallet_financial_operation_claim_from_options($options);
    $bindingError = wallet_financial_operation_mutation_binding_error($claim, $uid, $amount, $refId);
    if ($bindingError !== []) {
        return $bindingError;
    }
    if ($claim !== [] && wallet_financial_operation_claim_wallet_applied($claim)) {
        return wallet_financial_operation_repair_applied_ledger($uid, $claim);
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
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $snapshotMarker = wallet_financial_operation_marker_from_snapshot($wallet, $operationKey);
            if ($snapshotMarker !== []) {
                return wallet_financial_operation_recover_wallet_marker($uid, $claim, $snapshotMarker);
            }
        }
        $now = wallet_now();
        $currency = wallet_currency_for_uid($uid, $wallet);
        $currencyError = wallet_financial_operation_currency_binding_error($claim, $currency);
        if ($currencyError !== []) {
            return $currencyError;
        }

        $available = (float)($wallet['available_balance'] ?? 0);
        $hold = (float)($wallet['hold_balance'] ?? 0);
        $totalBundle = (float)($wallet['total_bundle_spent'] ?? 0);

        $updated = $wallet;
        $updated['hold_balance'] = wallet_round_money(max(0, $hold - $amount));
        $updated['total_bundle_spent'] = wallet_round_money($totalBundle + $amount);
        $updated['updated_at'] = $now;

        $ledgerRow = [
            'type' => $type,
            'direction' => 'DEBIT_FINAL',
            'amount' => wallet_round_money($amount),
            'currency' => $currency,
            'wallet_currency' => $currency,
            'before_available' => wallet_round_money($available),
            'after_available' => wallet_round_money($available),
            'before_hold' => wallet_round_money($hold),
            'after_hold' => $updated['hold_balance'],
            'ref_id' => $refId,
            'note' => 'Reserved amount settled after bundle success',
            'created_at' => $now,
        ];
        if ($claim !== []) {
            if (!wallet_financial_operation_owner_is_current($claim)) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed',
                ];
            }
            $ledgerRow['ledger_id'] = (string)($claim['ledger_id'] ?? wallet_financial_operation_ledger_id($refId, $type));
            $operationKey = (string)($claim['operation_key'] ?? wallet_financial_operation_key($refId, $type));
            $updated['financial_operations'] = is_array($updated['financial_operations'] ?? null) ? $updated['financial_operations'] : [];
            $updated['financial_operations'][$operationKey] = wallet_financial_operation_prepare_marker($claim, $ledgerRow, [
                'before_available' => wallet_round_money($available),
                'after_available' => wallet_round_money($available),
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
            ]);
        }

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

        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'before_available' => wallet_round_money($available),
                'after_available' => wallet_round_money($available),
                'before_hold' => wallet_round_money($hold),
                'after_hold' => $updated['hold_balance'],
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after wallet mutation',
                ];
            }
        }

        $ledger = $claim !== []
            ? wallet_create_ledger_full_checked($uid, $ledgerRow)
            : ['ok' => true, 'ledger_id' => create_wallet_ledger_full($uid, $ledgerRow)];
        if (empty($ledger['ok'])) {
            if ($claim !== []) {
                wallet_financial_operation_mark_failed(
                    $claim,
                    'LEDGER_WRITE_FAILED',
                    'Bundle wallet settled but ledger could not be saved',
                    ['wallet_applied' => true, 'ledger_row' => $ledgerRow]
                );
            }
            return [
                'ok' => false,
                'code' => 'LEDGER_WRITE_FAILED',
                'message' => 'Bundle wallet settled but ledger could not be saved',
                'available_balance' => wallet_round_money($available),
                'hold_balance' => $updated['hold_balance'],
            ];
        }
        if ($claim !== []) {
            if (!wallet_financial_operation_mark_applied($claim, [
                'wallet_applied' => true,
                'ledger_written' => true,
                'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
                'ledger_row' => $ledgerRow,
            ])) {
                return [
                    'ok' => false,
                    'code' => 'FINANCIAL_OPERATION_OWNER_MISMATCH',
                    'message' => 'Financial operation ownership changed after ledger write',
                ];
            }
        }

        return [
            'ok' => true,
            'code' => 'SUCCESS',
            'message' => 'Bundle wallet settled successfully',
            'ledger_id' => (string)($ledger['ledger_id'] ?? ''),
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
    array $meta = [],
    array $options = []
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
            'subadmin_profit_bdt' => wallet_round_money((float)($meta['subadmin_profit_bdt'] ?? $profitAmount)),
            'subadmin_profit_wallet_amount' => wallet_round_money($profitAmount),
            'subadmin_profit_wallet_currency' => (string)($meta['subadmin_profit_wallet_currency'] ?? ''),
            'subadmin_profit_rate_used' => wallet_round_money((float)($meta['subadmin_profit_rate_used'] ?? 0)),
        ],
        $options
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
