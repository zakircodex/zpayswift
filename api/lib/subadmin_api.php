<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

/*
 * Keep bundle helper available for subadmin panel bundle offer/custom commission.
 * If another endpoint already required bundle.php, require_once will not duplicate it.
 */
$__subapi_bundle_file = __DIR__ . '/bundle.php';
if (file_exists($__subapi_bundle_file) && !function_exists('bundle_load_offer')) {
    require_once $__subapi_bundle_file;
}

function subapi_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function subapi_make_uid(string $prefix = 'ID'): string
{
    if (function_exists('make_uid')) {
        return (string)make_uid();
    }

    return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function subapi_round_money(float $amount): float
{
    return round($amount, 2);
}

function subapi_make_key_id(): string
{
    return 'AK' . strtoupper(bin2hex(random_bytes(6)));
}

function subapi_generate_plain_key(string $uid): string
{
    $rand = bin2hex(random_bytes(16));
    return 'ztsa_' . strtolower($uid) . '_' . $rand;
}

function subapi_hash_key(string $plainKey): string
{
    return hash('sha256', trim($plainKey));
}

function subapi_mask_key(string $plainKey): string
{
    $plainKey = trim($plainKey);
    if ($plainKey === '') {
        return '';
    }

    $len = strlen($plainKey);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }

    return substr($plainKey, 0, 6) . str_repeat('*', max(0, $len - 10)) . substr($plainKey, -4);
}

function subapi_normalize_status(?string $status): string
{
    $status = strtoupper(trim((string)$status));
    return in_array($status, ['ACTIVE', 'DISABLED', 'REVOKED'], true) ? $status : 'ACTIVE';
}

function subapi_allowed_role(string $role): bool
{
    $role = strtoupper(trim($role));
    return in_array($role, ['SUBADMIN', 'ADMIN'], true);
}

function subapi_load_user(string $uid): array
{
    $user = fb_get('USERS/' . trim($uid));
    return is_array($user) ? $user : [];
}

function subapi_load_wallet(string $uid): array
{
    $wallet = fb_get('USER_WALLETS/' . trim($uid));
    return is_array($wallet) ? $wallet : [];
}

function subapi_load_role_settings(string $uid, ?string $role = null): array
{
    $settings = fb_get('USER_ROLE_SETTINGS/' . trim($uid));

    if (!is_array($settings)) {
        $settings = role_default_settings($role ?: 'USER');
    } elseif (function_exists('role_settings_with_defaults')) {
        $settings = role_settings_with_defaults($settings, $role ?: 'USER');
    }

    return is_array($settings) ? $settings : [];
}

function subapi_user_can_use_api(array $user, array $roleSettings): bool
{
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $apiEnabled = (bool)($roleSettings['api_enabled'] ?? false);

    if (!subapi_allowed_role($role)) {
        return false;
    }

    if ($status !== 'ACTIVE') {
        return false;
    }

    return $apiEnabled;
}

function subapi_wallet_available_balance(array $wallet): float
{
    return (float)($wallet['available_balance'] ?? 0);
}

function subapi_validate_amount_limits(float $amount, array $roleSettings): array
{
    $minAmount = (float)($roleSettings['min_amount'] ?? 0);
    $maxAmount = (float)($roleSettings['max_amount'] ?? 0);

    if ($amount <= 0) {
        return [false, 'Amount must be greater than 0'];
    }

    if ($minAmount > 0 && $amount < $minAmount) {
        return [false, 'Amount is below minimum limit'];
    }

    if ($maxAmount > 0 && $amount > $maxAmount) {
        return [false, 'Amount is above maximum limit'];
    }

    return [true, 'OK'];
}

function subapi_create_key_record(string $uid, string $plainKey, string $createdByUid = ''): array
{
    $now = subapi_now();
    $keyId = subapi_make_key_id();

    return [
        'key_id' => $keyId,
        'uid' => $uid,
        'key_hash' => subapi_hash_key($plainKey),
        'key_mask' => subapi_mask_key($plainKey),
        'status' => 'ACTIVE',
        'last_used_at' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'created_by_uid' => $createdByUid,
    ];
}

function subapi_store_key_record(string $uid, array $record): bool
{
    $keyId = (string)($record['key_id'] ?? '');
    if ($keyId === '') {
        return false;
    }

    return fb_put('USER_API_KEYS/' . trim($uid) . '/' . $keyId, $record);
}

function subapi_list_keys(string $uid): array
{
    $items = fb_get('USER_API_KEYS/' . trim($uid));
    return is_array($items) ? $items : [];
}

function subapi_create_key(string $uid, string $createdByUid = ''): array
{
    $uid = trim($uid);
    $createdByUid = trim($createdByUid);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));

    if (!subapi_allowed_role($role)) {
        return [
            'ok' => false,
            'code' => 'ROLE_NOT_ALLOWED',
            'message' => 'Only SUBADMIN or ADMIN can have API keys',
            'data' => [],
        ];
    }

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'User account is inactive',
            'data' => [],
        ];
    }

    $plainKey = subapi_generate_plain_key($uid);
    $record = subapi_create_key_record($uid, $plainKey, $createdByUid);

    $tries = 0;
    while ($tries < 10) {
        $exists = fb_get('USER_API_KEYS/' . $uid . '/' . $record['key_id']);
        if (!is_array($exists)) {
            break;
        }

        $record['key_id'] = subapi_make_key_id();
        $tries++;
    }

    if ($tries >= 10) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Could not generate unique API key ID',
            'data' => [],
        ];
    }

    if (!subapi_store_key_record($uid, $record)) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to save API key',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('SUBAPI_KEY_CREATE', (string)$record['key_id'], 'Subadmin API key created', [
            'uid' => $uid,
            'key_id' => (string)$record['key_id'],
            'created_by_uid' => $createdByUid,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'API key created successfully',
        'data' => [
            'uid' => $uid,
            'key_id' => (string)$record['key_id'],
            'key_mask' => (string)$record['key_mask'],
            'plain_key' => $plainKey,
            'status' => 'ACTIVE',
            'created_at' => (int)$record['created_at'],
        ],
    ];
}

function subapi_update_key_status(string $uid, string $keyId, string $status, string $updatedByUid = ''): array
{
    $uid = trim($uid);
    $keyId = trim($keyId);
    $updatedByUid = trim($updatedByUid);
    $status = subapi_normalize_status($status);

    if ($uid === '' || $keyId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid and key_id are required',
            'data' => [],
        ];
    }

    $existing = fb_get('USER_API_KEYS/' . $uid . '/' . $keyId);
    if (!is_array($existing)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'API key not found',
            'data' => [],
        ];
    }

    $patch = [
        'status' => $status,
        'updated_at' => subapi_now(),
    ];

    if ($updatedByUid !== '') {
        $patch['updated_by_uid'] = $updatedByUid;
    }

    if (!fb_patch('USER_API_KEYS/' . $uid . '/' . $keyId, $patch)) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to update API key status',
            'data' => [],
        ];
    }

    if (function_exists('system_log')) {
        system_log('SUBAPI_KEY_STATUS_UPDATE', $keyId, 'Subadmin API key status updated', [
            'uid' => $uid,
            'key_id' => $keyId,
            'status' => $status,
            'updated_by_uid' => $updatedByUid,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'API key status updated successfully',
        'data' => [
            'uid' => $uid,
            'key_id' => $keyId,
            'status' => $status,
            'updated_at' => (int)$patch['updated_at'],
        ],
    ];
}

function subapi_find_key_by_plain(string $plainKey): array
{
    $plainKey = trim($plainKey);
    if ($plainKey === '') {
        return [];
    }

    $hash = subapi_hash_key($plainKey);
    $all = fb_get('USER_API_KEYS');

    if (!is_array($all)) {
        return [];
    }

    foreach ($all as $uid => $userKeys) {
        if (!is_array($userKeys)) {
            continue;
        }

        foreach ($userKeys as $keyId => $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string)($row['key_hash'] ?? '') !== $hash) {
                continue;
            }

            $row['uid'] = (string)($row['uid'] ?? $uid);
            $row['key_id'] = (string)($row['key_id'] ?? $keyId);
            return $row;
        }
    }

    return [];
}

function subapi_touch_key_usage(string $uid, string $keyId): void
{
    fb_patch('USER_API_KEYS/' . trim($uid) . '/' . trim($keyId), [
        'last_used_at' => subapi_now(),
        'updated_at' => subapi_now(),
    ]);
}

function subapi_revoke_key(string $uid, string $keyId): bool
{
    return fb_patch('USER_API_KEYS/' . trim($uid) . '/' . trim($keyId), [
        'status' => 'REVOKED',
        'updated_at' => subapi_now(),
    ]);
}

function subapi_disable_key(string $uid, string $keyId): bool
{
    return fb_patch('USER_API_KEYS/' . trim($uid) . '/' . trim($keyId), [
        'status' => 'DISABLED',
        'updated_at' => subapi_now(),
    ]);
}

function subapi_enable_key(string $uid, string $keyId): bool
{
    return fb_patch('USER_API_KEYS/' . trim($uid) . '/' . trim($keyId), [
        'status' => 'ACTIVE',
        'updated_at' => subapi_now(),
    ]);
}

function subapi_log_request(string $uid, array $row): bool
{
    $uid = trim($uid);
    if ($uid === '') {
        return false;
    }

    $requestId = (string)($row['request_id'] ?? subapi_make_uid('REQ'));
    $row['request_id'] = $requestId;
    $row['uid'] = $uid;
    $row['created_at'] = (int)($row['created_at'] ?? subapi_now());
    $row['updated_at'] = (int)($row['updated_at'] ?? $row['created_at']);

    return fb_put('USER_API_REQUESTS/' . $uid . '/' . $requestId, $row);
}

function subapi_list_request_logs(string $uid): array
{
    $items = fb_get('USER_API_REQUESTS/' . trim($uid));
    return is_array($items) ? $items : [];
}

function subapi_extract_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    $header = trim((string)$header);

    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }

    return '';
}

function subapi_authenticate_request(): array
{
    $plainKey = subapi_extract_bearer_token();

    if ($plainKey === '') {
        $plainKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    if ($plainKey === '' && defined('SUBADMIN_API_ALLOW_QUERY_KEY') && (bool)SUBADMIN_API_ALLOW_QUERY_KEY) {
        $plainKey = trim((string)($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
    }

    if ($plainKey === '') {
        api_response(false, 'API_KEY_REQUIRED', 'API key is required', [], 401);
    }

    $keyRow = subapi_find_key_by_plain($plainKey);
    if (!$keyRow) {
        api_response(false, 'INVALID_API_KEY', 'Invalid API key', [], 401);
    }

    $uid = (string)($keyRow['uid'] ?? '');
    $keyId = (string)($keyRow['key_id'] ?? '');
    $keyStatus = subapi_normalize_status((string)($keyRow['status'] ?? 'ACTIVE'));

    if ($uid === '' || $keyId === '') {
        api_response(false, 'INVALID_API_KEY', 'Invalid API key record', [], 401);
    }

    if ($keyStatus !== 'ACTIVE') {
        api_response(false, 'API_KEY_DISABLED', 'API key is not active', [], 403);
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        api_response(false, 'USER_NOT_FOUND', 'API user not found', [], 404);
    }

    $roleSettings = subapi_load_role_settings($uid, (string)($user['role'] ?? 'USER'));
    if (!subapi_user_can_use_api($user, $roleSettings)) {
        api_response(false, 'API_NOT_ALLOWED', 'User is not allowed to use API', [], 403);
    }

    subapi_touch_key_usage($uid, $keyId);

    return [
        'uid' => $uid,
        'key_id' => $keyId,
        'user' => $user,
        'role_settings' => $roleSettings,
        'wallet' => subapi_load_wallet($uid),
        'plain_key' => $plainKey,
        'key_row' => $keyRow,
    ];
}

/* ============================================================
 * API/PANEL TOPUP HOLD SETTLEMENT HELPERS
 * ============================================================ */

function subapi_is_topup_hold_request(array $request): bool
{
    $uid = trim((string)($request['uid'] ?? ''));
    $source = strtoupper(trim((string)($request['source'] ?? '')));
    $heldAmount = (float)($request['held_amount'] ?? 0);

    return $uid !== '' && $source === 'SUBADMIN_API' && $heldAmount > 0;
}

function subapi_is_already_settled(array $request): bool
{
    return !empty($request['hold_settled_at']);
}

function subapi_topup_numeric_first(array $row, array $keys): float
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && is_numeric($row[$key]) && (float)$row[$key] > 0) {
            return subapi_round_money((float)$row[$key]);
        }
    }

    return 0.0;
}

function subapi_topup_current_hold_amount(array $request, array $wallet = []): array
{
    $uid = trim((string)($request['uid'] ?? ''));
    if (!$wallet && $uid !== '' && function_exists('subapi_load_wallet')) {
        $wallet = subapi_load_wallet($uid);
    }
    $wallet = is_array($wallet) ? $wallet : [];

    $currentCurrency = strtoupper(trim((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? '')));
    if ($currentCurrency === '' && function_exists('wallet_currency_for_uid')) {
        $currentCurrency = wallet_currency_for_uid($uid, $wallet);
    }
    if ($currentCurrency === '') {
        $currentCurrency = strtoupper(trim((string)($request['wallet_currency'] ?? $request['wallet_debit_currency'] ?? 'BDT')));
    }
    $currentCurrency = $currentCurrency === 'MYR' ? 'MYR' : 'BDT';

    $hold = subapi_round_money((float)($wallet['hold_balance'] ?? 0));
    $originalCurrency = strtoupper(trim((string)($request['wallet_debit_currency'] ?? $request['wallet_currency'] ?? $currentCurrency)));
    $originalCurrency = $originalCurrency === 'MYR' ? 'MYR' : 'BDT';
    $originalAmount = subapi_topup_numeric_first($request, [
        'wallet_debit_amount',
        'wallet_hold_amount',
        'held_amount',
        'wallet_debit',
        'charged_amount',
        'amount',
    ]);
    $bdtAmount = subapi_topup_numeric_first($request, [
        'wallet_debit_bdt',
        'total_debit_bdt',
        'topup_amount_bdt',
        'amount_bdt',
    ]);
    $myrAmount = subapi_topup_numeric_first($request, [
        'wallet_debit_myr',
        'topup_amount_myr',
        'amount_myr',
    ]);

    if ($originalCurrency === 'MYR' && $myrAmount <= 0 && $originalAmount > 0) {
        $myrAmount = $originalAmount;
    }
    if ($originalCurrency === 'BDT' && $bdtAmount <= 0 && $originalAmount > 0) {
        $bdtAmount = $originalAmount;
    }

    $rate = subapi_topup_numeric_first($wallet, ['rate_myr_bdt', 'conversion_rate_myr_bdt', 'last_rate_myr_bdt']);
    if ($rate <= 0) {
        $rate = subapi_topup_numeric_first($request, ['conversion_rate_myr_bdt', 'rate_snapshot', 'rate_used', 'RATE_SNAPSHOT']);
    }

    $amount = $originalAmount;
    if ($currentCurrency === 'BDT') {
        if ($bdtAmount > 0) {
            $amount = $bdtAmount;
        } elseif ($myrAmount > 0 && $rate > 0) {
            $amount = subapi_round_money($myrAmount * $rate);
        }
    } else {
        if ($myrAmount > 0) {
            $amount = $myrAmount;
        } elseif ($bdtAmount > 0 && $rate > 0) {
            $amount = subapi_round_money($bdtAmount / $rate);
        }
    }

    $amount = subapi_round_money(max(0, $amount));
    if ($hold > 0 && $amount > $hold) {
        $amount = $hold;
    }

    return [
        'ok' => $amount > 0,
        'amount' => $amount,
        'wallet_currency' => $currentCurrency,
        'wallet' => $wallet,
        'hold_balance' => $hold,
        'rate_used' => $rate,
        'original_wallet_debit' => $originalAmount,
        'original_wallet_debit_currency' => $originalCurrency,
    ];
}

function subapi_settle_topup_success(array &$request, string $message = 'Topup completed successfully'): bool
{
    if (!subapi_is_topup_hold_request($request)) {
        return true;
    }

    if (subapi_is_already_settled($request)) {
        return true;
    }

    $uid = trim((string)($request['uid'] ?? ''));
    $requestId = trim((string)($request['request_id'] ?? ''));
    $keyId = trim((string)($request['source_key_id'] ?? ''));
    $now = subapi_now();

    $wallet = subapi_load_wallet($uid);
    $resolvedHold = subapi_topup_current_hold_amount($request, $wallet);
    $amount = subapi_round_money((float)($resolvedHold['amount'] ?? 0));
    if ($amount <= 0) {
        return false;
    }
    $walletCurrency = (string)($resolvedHold['wallet_currency'] ?? 'BDT');
    $currentAvailable = subapi_round_money((float)($wallet['available_balance'] ?? 0));
    $currentHold = subapi_round_money((float)($wallet['hold_balance'] ?? 0));
    $currentTopupSpent = subapi_round_money((float)($wallet['total_topup_spent'] ?? 0));

    $newHold = max(0, subapi_round_money($currentHold - $amount));
    $newAvailable = $currentAvailable;
    $newTopupSpent = subapi_round_money($currentTopupSpent + $amount);

    $walletOk = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'total_topup_spent' => $newTopupSpent,
        'updated_at' => $now,
    ]);

    if (!$walletOk) {
        return false;
    }

    $ledgerId = subapi_make_uid('LED');
    $ledgerMonth = date('Y-m', $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'API_TOPUP_SUCCESS',
        'direction' => 'DEBIT_HOLD',
        'amount' => $amount,
        'currency' => $walletCurrency,
        'wallet_currency' => $walletCurrency,
        'before_available' => $currentAvailable,
        'after_available' => $newAvailable,
        'before_hold' => $currentHold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'key_id' => $keyId,
        'note' => 'Held balance settled on successful API topup',
        'created_at' => $now,
    ]);

    fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, [
        'status' => 'SUCCESS',
        'message' => $message,
        'updated_at' => $now,
    ]);

    $request['hold_settled_at'] = $now;
    $request['hold_settlement_status'] = 'SUCCESS';
    $request['held_amount'] = $amount;
    $request['settled_hold_amount'] = $amount;
    $request['settled_hold_currency'] = $walletCurrency;

    return true;
}

function subapi_settle_topup_failed(array &$request, string $message = 'Topup failed and held balance released'): bool
{
    if (!subapi_is_topup_hold_request($request)) {
        return true;
    }

    if (subapi_is_already_settled($request)) {
        return true;
    }

    $uid = trim((string)($request['uid'] ?? ''));
    $requestId = trim((string)($request['request_id'] ?? ''));
    $keyId = trim((string)($request['source_key_id'] ?? ''));
    $now = subapi_now();

    $wallet = subapi_load_wallet($uid);
    $resolvedHold = subapi_topup_current_hold_amount($request, $wallet);
    $amount = subapi_round_money((float)($resolvedHold['amount'] ?? 0));
    if ($amount <= 0) {
        return false;
    }
    $walletCurrency = (string)($resolvedHold['wallet_currency'] ?? 'BDT');
    $currentAvailable = subapi_round_money((float)($wallet['available_balance'] ?? 0));
    $currentHold = subapi_round_money((float)($wallet['hold_balance'] ?? 0));
    $currentRefund = subapi_round_money((float)($wallet['total_refund'] ?? 0));

    $newAvailable = subapi_round_money($currentAvailable + $amount);
    $newHold = max(0, subapi_round_money($currentHold - $amount));
    $newRefund = subapi_round_money($currentRefund + $amount);

    $walletOk = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'total_refund' => $newRefund,
        'updated_at' => $now,
    ]);

    if (!$walletOk) {
        return false;
    }

    $ledgerId = subapi_make_uid('LED');
    $ledgerMonth = date('Y-m', $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'API_TOPUP_FAILED_RELEASE',
        'direction' => 'RELEASE_HOLD',
        'amount' => $amount,
        'currency' => $walletCurrency,
        'wallet_currency' => $walletCurrency,
        'before_available' => $currentAvailable,
        'after_available' => $newAvailable,
        'before_hold' => $currentHold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'key_id' => $keyId,
        'note' => 'Held balance released after failed API topup',
        'created_at' => $now,
    ]);

    fb_patch('USER_API_REQUESTS/' . $uid . '/' . $requestId, [
        'status' => 'FAILED',
        'message' => $message,
        'updated_at' => $now,
    ]);

    $request['hold_settled_at'] = $now;
    $request['hold_settlement_status'] = 'FAILED';
    $request['held_amount'] = $amount;
    $request['refund_amount'] = $amount;
    $request['refund_currency'] = $walletCurrency;

    return true;
}

/* ============================================================
 * SUBADMIN PANEL TOPUP
 * ============================================================ */

function subapi_create_panel_topup(
    string $uid,
    string $topupNumber,
    string $operator,
    float $amount,
    string $note = ''
): array {
    $uid = trim($uid);
    $topupNumber = trim($topupNumber);
    $operator = trim($operator);
    $note = trim($note);
    $amount = subapi_round_money($amount);
    $countryCode = 'BD';

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    if ($topupNumber === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'topup_number is required',
            'data' => [],
        ];
    }

    if ($operator === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'operator is required',
            'data' => [],
        ];
    }

    if (function_exists('normalize_operator')) {
        $operator = normalize_operator($operator);
    } else {
        $operator = strtoupper($operator);
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $roleSettings = subapi_load_role_settings($uid, $role);

    if (!subapi_allowed_role($role)) {
        return [
            'ok' => false,
            'code' => 'ROLE_NOT_ALLOWED',
            'message' => 'Only SUBADMIN or ADMIN can create topup from panel',
            'data' => [],
        ];
    }

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!(bool)($roleSettings['topup_enabled'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'TOPUP_DISABLED',
            'message' => 'Topup is disabled for this account',
            'data' => [],
        ];
    }

    [$limitOk, $limitMessage] = subapi_validate_amount_limits($amount, $roleSettings);
    if (!$limitOk) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => $limitMessage,
            'data' => [],
        ];
    }

    $wallet = subapi_load_wallet($uid);
    $availableBalance = subapi_round_money((float)($wallet['available_balance'] ?? 0));
    $holdBalance = subapi_round_money((float)($wallet['hold_balance'] ?? 0));
    $financials = topup_calculate_payment_context($uid, $amount, $user, $wallet, $roleSettings);
    if (empty($financials['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($financials['code'] ?? 'TOPUP_CALCULATION_FAILED'),
            'message' => (string)($financials['message'] ?? 'Topup calculation failed'),
            'data' => [],
        ];
    }
    $walletDebit = (float)$financials['wallet_debit_amount'];

    if ($availableBalance < $walletDebit) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient available balance',
            'data' => [
                'available_balance' => $availableBalance,
                'required_amount' => $walletDebit,
            ],
        ];
    }

    $requestId = subapi_make_uid('REQ');
    $now = subapi_now();

    $newAvailable = subapi_round_money($availableBalance - $walletDebit);
    $newHold = subapi_round_money($holdBalance + $walletDebit);

    $walletOk = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'updated_at' => $now,
    ]);

    if (!$walletOk) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to move balance into hold',
            'data' => [],
        ];
    }

    $topupRow = [
        'request_id' => $requestId,
        'uid' => $uid,
        'user_phone' => (string)($user['phone'] ?? ''),
        'topup_number' => $topupNumber,
        'operator' => $operator,
        'country_code' => $countryCode,
        'execution_mode' => function_exists('topup_operator_execution_mode') ? topup_operator_execution_mode($countryCode, $operator) : 'WORKER_USSD',
        'worker_claimable' => function_exists('topup_operator_worker_claimable') ? topup_operator_worker_claimable($countryCode, $operator) : true,
        'WORKER_CLAIMABLE' => function_exists('topup_operator_worker_claimable') ? topup_operator_worker_claimable($countryCode, $operator) : true,
        'manual_telegram_required' => function_exists('topup_operator_worker_claimable') ? !topup_operator_worker_claimable($countryCode, $operator) : false,
        'amount' => $amount,
        'topup_amount' => (float)($financials['topup_amount'] ?? $amount),
        'topup_currency' => (string)($financials['topup_currency'] ?? 'BDT'),
        'amount_bdt' => (float)($financials['amount_bdt'] ?? $amount),
        'topup_amount_bdt' => (float)($financials['topup_amount_bdt'] ?? $amount),
        'amount_myr' => (float)($financials['amount_myr'] ?? 0),
        'topup_amount_myr' => (float)($financials['topup_amount_myr'] ?? 0),
        'account_country' => (string)($financials['account_country'] ?? ''),
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
        'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
        'commission_amount' => (float)($financials['commission_amount'] ?? $financials['commission_bdt'] ?? 0),
        'commission_credit' => (float)($financials['commission_credit'] ?? 0),
        'fee_amount' => (float)($financials['fee_amount'] ?? 0),
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_myr' => (float)($financials['wallet_debit_myr'] ?? 0),
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'wallet_currency' => $financials['wallet_currency'],
        'display_currency' => (string)($financials['display_currency'] ?? $financials['wallet_currency']),
        'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
        'rate_snapshot' => $financials['rate_snapshot'] ?? null,
        'rate_used' => $financials['rate_used'],
        'balance_before' => $availableBalance,
        'balance_after' => $newAvailable,
        'calculation_version' => (string)($financials['calculation_version'] ?? ''),
        'total_debit_bdt' => $financials['total_debit_bdt'],
        'total_debit' => $walletDebit,
        'charged_amount' => $walletDebit,
        'status' => 'PENDING',
        'request_pin_verified' => true,
        'wallet_hold_amount' => 0,
        'held_amount' => $walletDebit,
        'hold_settled_at' => 0,
        'hold_settlement_status' => 'PENDING',
        'assigned_device_id' => '',
        'assigned_slot' => '',
        'source' => 'SUBADMIN_API',
        'request_source' => 'SUBADMIN_PANEL',
        'source_key_id' => 'PANEL',
        'note' => $note,
        'created_by_admin' => false,
        'created_from_panel' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $topupOk = fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $topupRow);

    if (!$topupOk) {
        fb_patch('USER_WALLETS/' . $uid, [
            'available_balance' => $availableBalance,
            'hold_balance' => $holdBalance,
            'updated_at' => subapi_now(),
        ]);

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create topup request',
            'data' => [],
        ];
    }

    fb_put('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'type' => 'TOPUP',
        'uid' => $uid,
        'status' => 'PENDING',
        'message' => 'Topup request created from subadmin panel',
        'updated_at' => $now,
    ]);

    $ledgerId = subapi_make_uid('LED');
    $ledgerMonth = date('Y-m', $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'SUBADMIN_PANEL_TOPUP_HOLD',
        'direction' => 'HOLD',
        'amount' => $walletDebit,
        'account_country' => (string)($financials['account_country'] ?? ''),
        'topup_amount_bdt' => $amount,
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'commission_applicable' => (bool)($financials['commission_applicable'] ?? false),
        'commission_type' => (string)($financials['commission_type'] ?? 'NONE'),
        'commission_amount' => (float)($financials['commission_amount'] ?? $financials['commission_bdt'] ?? 0),
        'commission_credit' => (float)($financials['commission_credit'] ?? 0),
        'fee_amount' => (float)($financials['fee_amount'] ?? 0),
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'wallet_currency' => $financials['wallet_currency'],
        'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
        'rate_snapshot' => $financials['rate_snapshot'] ?? null,
        'rate_used' => $financials['rate_used'],
        'calculation_version' => (string)($financials['calculation_version'] ?? ''),
        'before_available' => $availableBalance,
        'after_available' => $newAvailable,
        'before_hold' => $holdBalance,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'key_id' => 'PANEL',
        'note' => 'Balance moved to hold from subadmin panel topup',
        'created_at' => $now,
    ]);

    subapi_log_request($uid, [
        'request_id' => $requestId,
        'key_id' => 'PANEL',
        'action' => 'SUBADMIN_PANEL_TOPUP_CREATE',
        'request_type' => 'TOPUP',
        'status' => 'PENDING',
        'operator' => $operator,
        'topup_number' => $topupNumber,
        'amount' => $amount,
        'commission_per_1000' => $financials['commission_per_1000'],
        'commission_bdt' => $financials['commission_bdt'],
        'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => $financials['wallet_debit_currency'],
        'rate_used' => $financials['rate_used'],
        'message' => $note !== '' ? $note : 'Topup created from subadmin panel',
        'source' => 'SUBADMIN_PANEL',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (function_exists('system_log')) {
        system_log('SUBADMIN_PANEL_TOPUP_CREATE', $requestId, 'Topup created from subadmin panel', [
            'uid' => $uid,
            'operator' => $operator,
            'topup_number' => $topupNumber,
            'amount' => $amount,
            'commission_per_1000' => $financials['commission_per_1000'],
            'commission_bdt' => $financials['commission_bdt'],
            'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
            'wallet_debit_amount' => $walletDebit,
            'wallet_debit_currency' => $financials['wallet_debit_currency'],
            'account_country' => (string)($financials['account_country'] ?? ''),
            'wallet_currency' => (string)($financials['wallet_currency'] ?? ''),
            'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
            'rate_snapshot' => $financials['rate_snapshot'] ?? null,
            'rate_used' => $financials['rate_used'],
        ]);
    }

    if (function_exists('topup_notify_telegram_request')) {
        topup_notify_telegram_request($topupRow);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Topup request created successfully',
        'data' => [
            'request_id' => $requestId,
            'uid' => $uid,
            'operator' => $operator,
            'topup_number' => $topupNumber,
            'amount' => $amount,
            'amount_bdt' => $amount,
            'commission_per_1000' => $financials['commission_per_1000'],
            'commission_bdt' => $financials['commission_bdt'],
            'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
            'wallet_debit_amount' => $walletDebit,
            'wallet_debit_currency' => $financials['wallet_debit_currency'],
            'wallet_currency' => (string)($financials['wallet_currency'] ?? ''),
            'account_country' => (string)($financials['account_country'] ?? ''),
            'rate_applicable' => (bool)($financials['rate_applicable'] ?? false),
            'rate_snapshot' => $financials['rate_snapshot'] ?? null,
            'rate_used' => $financials['rate_used'],
            'total_debit' => $walletDebit,
            'status' => 'PENDING',
            'available_balance' => $newAvailable,
            'hold_balance' => $newHold,
        ],
    ];
}

/* ============================================================
 * SUBADMIN PANEL BUNDLE HELPERS
 * Commission rule:
 * - Admin creates base offer with admin_commission.
 * - Subadmin customizes user_commission only.
 * - subadmin_profit = admin_commission - user_commission.
 * - Profit is NOT credited at request create time.
 * - Profit is credited only inside bundle_mark_success().
 * ============================================================ */

function subapi_bundle_panel_visible_user(string $uid, array $user): array
{
    $role = strtoupper(trim((string)($user['role'] ?? '')));
    $user['uid'] = (string)($user['uid'] ?? $uid);

    /*
     * Subadmin panel uses the current panel owner as the commission owner.
     * This also lets ADMIN test the same panel flow safely.
     */
    if (in_array($role, ['SUBADMIN', 'ADMIN'], true)) {
        $user['role'] = 'SUBADMIN';
        $user['parent_subadmin_uid'] = '';
        $user['created_by_uid'] = '';
    }

    return $user;
}

function subapi_bundle_normalize_operator(string $operator): string
{
    if (function_exists('normalize_operator')) {
        return normalize_operator($operator);
    }

    return strtoupper(trim($operator));
}

function subapi_bundle_offer_public_row(array $row): array
{
    $amount = subapi_round_money((float)($row['amount'] ?? 0));
    $adminCommission = subapi_round_money((float)($row['admin_commission'] ?? 0));
    $userCommission = subapi_round_money((float)($row['user_commission'] ?? 0));
    $subadminProfit = subapi_round_money((float)($row['subadmin_profit'] ?? max(0, $adminCommission - $userCommission)));
    $netCost = subapi_round_money((float)($row['net_cost_after_commission'] ?? ($amount - $userCommission)));

    return [
        'offer_id' => trim((string)($row['offer_id'] ?? '')),
        'operator' => subapi_bundle_normalize_operator((string)($row['operator'] ?? '')),
        'bundle_name' => (string)($row['bundle_name'] ?? $row['name'] ?? ''),
        'name' => (string)($row['name'] ?? $row['bundle_name'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'amount' => $amount,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'max_user_commission' => $adminCommission,
        'net_cost_after_commission' => $netCost,
        'duration_value' => (float)($row['duration_value'] ?? 0),
        'duration_unit' => (string)($row['duration_unit'] ?? ''),
        'duration_seconds' => (int)($row['duration_seconds'] ?? 0),
        'expires_at' => (int)($row['expires_at'] ?? 0),
        'expired' => (bool)($row['expired'] ?? false),
        'status' => strtoupper(trim((string)($row['status'] ?? 'ACTIVE'))),
        'active' => (bool)($row['active'] ?? true),
        'customized_by_subadmin' => (bool)($row['customized_by_subadmin'] ?? false),
        'subadmin_uid' => (string)($row['subadmin_uid'] ?? ''),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function subapi_panel_bundle_offer_detail(string $uid, string $offerId): array
{
    $uid = trim($uid);
    $offerId = trim($offerId);

    if ($uid === '' || $offerId === '') {
        return [];
    }

    if (!function_exists('bundle_load_offer') || !function_exists('bundle_build_visible_offer_for_user')) {
        return [];
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        return [];
    }

    $user = subapi_bundle_panel_visible_user($uid, $user);

    $baseOffer = bundle_load_offer($offerId);
    if (!is_array($baseOffer) || empty($baseOffer)) {
        return [];
    }

    $baseOffer['offer_id'] = (string)($baseOffer['offer_id'] ?? $offerId);
    $visible = bundle_build_visible_offer_for_user($baseOffer, $user);

    return subapi_bundle_offer_public_row($visible);
}

function subapi_panel_bundle_offers(
    string $uid,
    string $operatorFilter = '',
    string $statusFilter = 'ACTIVE'
): array {
    $uid = trim($uid);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $user['uid'] = $uid;

    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $roleSettings = subapi_load_role_settings($uid, $role);
    $wallet = subapi_load_wallet($uid);

    if (!subapi_allowed_role($role)) {
        return [
            'ok' => false,
            'code' => 'ROLE_NOT_ALLOWED',
            'message' => 'Only SUBADMIN or ADMIN can load bundle offers',
            'data' => [],
        ];
    }

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!(bool)($roleSettings['bundle_enabled'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'BUNDLE_DISABLED',
            'message' => 'Bundle is disabled for this account',
            'data' => [],
        ];
    }

    if (!function_exists('bundle_build_visible_offer_for_user')) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Bundle helper not loaded',
            'data' => [],
        ];
    }

    if (function_exists('bundle_expire_old_offers')) {
        bundle_expire_old_offers();
    }

    $operatorFilter = subapi_bundle_normalize_operator($operatorFilter);
    $statusFilter = strtoupper(trim($statusFilter));
    if ($statusFilter === '') {
        $statusFilter = 'ACTIVE';
    }

    $visibleUser = subapi_bundle_panel_visible_user($uid, $user);

    $items = fb_get('BUNDLE_OFFERS');
    if (!is_array($items)) {
        $items = [];
    }

    $out = [];
    $now = function_exists('bundle_now') ? bundle_now() : subapi_now();

    foreach ($items as $offerKey => $baseOffer) {
        if (!is_array($baseOffer)) {
            continue;
        }

        $baseOffer['offer_id'] = (string)($baseOffer['offer_id'] ?? $offerKey);

        if (function_exists('bundle_is_active_offer') && !bundle_is_active_offer($baseOffer, $now)) {
            continue;
        }

        $visible = bundle_build_visible_offer_for_user($baseOffer, $visibleUser);
        $row = subapi_bundle_offer_public_row($visible);

        $offerId = trim((string)($row['offer_id'] ?? ''));
        $operator = subapi_bundle_normalize_operator((string)($row['operator'] ?? ''));
        $rowStatus = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
        $active = (bool)($row['active'] ?? true);
        $expiresAt = (int)($row['expires_at'] ?? 0);
        $expired = $expiresAt > 0 && $expiresAt <= $now;

        if ($offerId === '') {
            continue;
        }

        if ($operatorFilter !== '' && $operator !== $operatorFilter) {
            continue;
        }

        if ($statusFilter !== 'ALL' && $rowStatus !== $statusFilter) {
            continue;
        }

        if (!$active || $rowStatus !== 'ACTIVE' || $expired) {
            continue;
        }

        $row['operator'] = $operator;
        $row['expired'] = $expired;
        $out[] = $row;
    }

    usort($out, static function (array $a, array $b): int {
        $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['created_at'] ?? 0));
        $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['created_at'] ?? 0));
        return $bTime <=> $aTime;
    });

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle offers loaded successfully',
        'data' => [
            'uid' => $uid,
            'total' => count($out),
            'items' => array_values($out),
            'wallet' => [
                'available_balance' => (float)($wallet['available_balance'] ?? 0),
                'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
            ],
        ],
    ];
}

function subapi_save_panel_bundle_commission(
    string $uid,
    string $offerId,
    float $userCommission,
    bool $active = true
): array {
    $uid = trim($uid);
    $offerId = trim($offerId);
    $userCommission = subapi_round_money($userCommission);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    if ($offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'offer_id is required',
            'data' => [],
        ];
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));

    if (!subapi_allowed_role($role)) {
        return [
            'ok' => false,
            'code' => 'ROLE_NOT_ALLOWED',
            'message' => 'Only SUBADMIN or ADMIN can customize bundle commission',
            'data' => [],
        ];
    }

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!function_exists('bundle_subadmin_save_custom_offer')) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Bundle commission helper not loaded',
            'data' => [],
        ];
    }

    $baseOffer = function_exists('bundle_load_offer') ? bundle_load_offer($offerId) : [];
    if (!is_array($baseOffer) || empty($baseOffer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    $adminCommission = subapi_round_money((float)($baseOffer['admin_commission'] ?? 0));

    if ($userCommission < 0) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'User commission cannot be negative',
            'data' => [
                'offer_id' => $offerId,
                'admin_commission' => $adminCommission,
                'max_user_commission' => $adminCommission,
            ],
        ];
    }

    if ($userCommission > $adminCommission) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'User commission cannot be higher than admin commission',
            'data' => [
                'offer_id' => $offerId,
                'admin_commission' => $adminCommission,
                'max_user_commission' => $adminCommission,
            ],
        ];
    }

    $save = bundle_subadmin_save_custom_offer($uid, $offerId, $userCommission, $active);

    if (!($save['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($save['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($save['message'] ?? 'Failed to save bundle commission'),
            'data' => (array)($save['data'] ?? []),
        ];
    }

    $offer = subapi_panel_bundle_offer_detail($uid, $offerId);

    if (function_exists('system_log')) {
        system_log('SUBADMIN_PANEL_BUNDLE_COMMISSION_SAVE', $offerId, 'Subadmin panel bundle commission saved', [
            'uid' => $uid,
            'offer_id' => $offerId,
            'admin_commission' => $adminCommission,
            'user_commission' => $userCommission,
            'subadmin_profit' => max(0, subapi_round_money($adminCommission - $userCommission)),
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle commission saved successfully',
        'data' => [
            'custom' => (array)($save['data'] ?? []),
            'offer' => $offer,
        ],
    ];
}

function subapi_reset_panel_bundle_commission(string $uid, string $offerId): array
{
    $uid = trim($uid);
    $offerId = trim($offerId);

    if ($uid === '' || $offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid and offer_id are required',
            'data' => [],
        ];
    }

    if (function_exists('bundle_subadmin_disable_custom_offer')) {
        $res = bundle_subadmin_disable_custom_offer($uid, $offerId);
    } else {
        $now = subapi_now();
        $ok = fb_patch('SUBADMIN_BUNDLE_OFFERS/' . $uid . '/' . $offerId, [
            'active' => false,
            'status' => 'INACTIVE',
            'updated_at' => $now,
        ]);

        $res = [
            'ok' => $ok,
            'code' => $ok ? 'SUCCESS' : 'SERVER_ERROR',
            'message' => $ok ? 'Custom commission reset successfully' : 'Failed to reset custom commission',
            'data' => [
                'uid' => $uid,
                'offer_id' => $offerId,
                'updated_at' => $now,
            ],
        ];
    }

    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string)($res['code'] ?? 'SERVER_ERROR'),
            'message' => (string)($res['message'] ?? 'Failed to reset commission'),
            'data' => (array)($res['data'] ?? []),
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle commission reset successfully',
        'data' => [
            'offer' => subapi_panel_bundle_offer_detail($uid, $offerId),
        ],
    ];
}

function subapi_create_panel_bundle(
    string $uid,
    string $offerId,
    string $bundleNumber,
    string $note = ''
): array {
    $uid = trim($uid);
    $offerId = trim($offerId);
    $bundleNumber = trim($bundleNumber);
    $note = trim($note);

    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'uid is required',
            'data' => [],
        ];
    }

    if ($offerId === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'offer_id is required',
            'data' => [],
        ];
    }

    if ($bundleNumber === '') {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'bundle_number is required',
            'data' => [],
        ];
    }

    $user = subapi_load_user($uid);
    if (!$user) {
        return [
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $user['uid'] = $uid;

    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    $status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));
    $roleSettings = subapi_load_role_settings($uid, $role);

    if (!subapi_allowed_role($role)) {
        return [
            'ok' => false,
            'code' => 'ROLE_NOT_ALLOWED',
            'message' => 'Only SUBADMIN or ADMIN can create bundle request from panel',
            'data' => [],
        ];
    }

    if ($status !== 'ACTIVE') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive',
            'data' => [],
        ];
    }

    if (!(bool)($roleSettings['bundle_enabled'] ?? false)) {
        return [
            'ok' => false,
            'code' => 'BUNDLE_DISABLED',
            'message' => 'Bundle is disabled for this account',
            'data' => [],
        ];
    }

    if (!function_exists('bundle_load_offer') || !function_exists('bundle_is_active_offer')) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Bundle helper not loaded',
            'data' => [],
        ];
    }

    if (function_exists('bundle_expire_old_offers')) {
        bundle_expire_old_offers();
    }

    $baseOffer = bundle_load_offer($offerId);
    if (!is_array($baseOffer) || empty($baseOffer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_NOT_FOUND',
            'message' => 'Bundle offer not found',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    $baseOffer['offer_id'] = (string)($baseOffer['offer_id'] ?? $offerId);

    if (!bundle_is_active_offer($baseOffer)) {
        return [
            'ok' => false,
            'code' => 'OFFER_INACTIVE',
            'message' => 'Bundle offer is inactive or expired',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    $visibleUser = subapi_bundle_panel_visible_user($uid, $user);

    $offer = $baseOffer;
    if (function_exists('bundle_build_visible_offer_for_user')) {
        $offer = bundle_build_visible_offer_for_user($baseOffer, $visibleUser);
    }

    $operator = subapi_bundle_normalize_operator((string)($offer['operator'] ?? ''));
    $bundleName = trim((string)($offer['bundle_name'] ?? $offer['name'] ?? ''));

    $priceAmount = subapi_round_money((float)(
        $offer['price_amount']
        ?? $offer['offer_price']
        ?? $offer['price']
        ?? $offer['amount']
        ?? 0
    ));

    $adminCommission = subapi_round_money((float)($offer['admin_commission'] ?? 0));
    if ($adminCommission < 0) {
        $adminCommission = 0.0;
    }

    if ($priceAmount > 0 && $adminCommission > $priceAmount) {
        $adminCommission = $priceAmount;
    }

    $customizedBySubadmin = (bool)($offer['customized_by_subadmin'] ?? false);

    /*
     * Final commission rule:
     *
     * Default:
     * Price 400, Admin Commission 50
     * User Commission/Discount = 50
     * User Pay/Hold = 350
     * Subadmin Profit on success = 0
     *
     * Custom:
     * Price 400, Admin Commission 50
     * User Commission/Discount = 20
     * User Pay/Hold = 380
     * Subadmin Profit on success = 30
     */
    if ($customizedBySubadmin) {
        $userCommission = subapi_round_money((float)($offer['user_commission'] ?? 0));

        if ($userCommission < 0) {
            $userCommission = 0.0;
        }

        if ($userCommission > $adminCommission) {
            $userCommission = $adminCommission;
        }

        $subadminProfit = max(0, subapi_round_money($adminCommission - $userCommission));
    } else {
        $userCommission = $adminCommission;
        $subadminProfit = 0.0;
    }

    $payableAmount = max(0, subapi_round_money($priceAmount - $userCommission));
    $subadminUid = trim((string)($offer['subadmin_uid'] ?? $uid));
    if ($subadminUid === '') {
        $subadminUid = $uid;
    }

    if ($operator === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Offer operator is missing',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    if ($bundleName === '') {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Offer bundle name is missing',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    if ($priceAmount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Offer amount is invalid',
            'data' => [
                'offer_id' => $offerId,
            ],
        ];
    }

    if ($payableAmount <= 0) {
        return [
            'ok' => false,
            'code' => 'INVALID_OFFER',
            'message' => 'Payable amount is invalid',
            'data' => [
                'offer_id' => $offerId,
                'price_amount' => $priceAmount,
                'user_commission' => $userCommission,
                'payable_amount' => $payableAmount,
            ],
        ];
    }

    $wallet = subapi_load_wallet($uid);
    $currentAvailable = subapi_round_money((float)($wallet['available_balance'] ?? 0));
    $currentHold = subapi_round_money((float)($wallet['hold_balance'] ?? 0));
    $bundleFinancials = bundle_wallet_breakdown($uid, $payableAmount, $user, $wallet);
    $walletHoldAmount = (float)$bundleFinancials['wallet_hold_amount'];

    if ($currentAvailable < $walletHoldAmount) {
        return [
            'ok' => false,
            'code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Not enough available balance',
            'data' => [
                'available_balance' => $currentAvailable,
                'required_amount' => $walletHoldAmount,
                'price_amount' => $priceAmount,
                'user_commission' => $userCommission,
            ],
        ];
    }

    $requestId = function_exists('bundle_make_request_id') ? bundle_make_request_id() : subapi_make_uid('BR');
    $now = function_exists('bundle_now') ? bundle_now() : subapi_now();
    $userPhone = trim((string)($user['phone'] ?? ''));

    $newAvailable = subapi_round_money($currentAvailable - $walletHoldAmount);
    $newHold = subapi_round_money($currentHold + $walletHoldAmount);

    $walletHoldOk = fb_patch('USER_WALLETS/' . $uid, [
        'available_balance' => $newAvailable,
        'hold_balance' => $newHold,
        'updated_at' => $now,
    ]);

    if (!$walletHoldOk) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to hold wallet balance',
            'data' => [],
        ];
    }

    $extra = [
        'offer_id' => $offerId,
        'offer_source' => 'SUBADMIN_PANEL',
        'source' => 'SUBADMIN_API',
        'source_key_id' => 'PANEL',
        'request_source' => 'SUBADMIN_PANEL',
        'created_from_panel' => true,
        'created_from_api' => false,

        'price_amount' => $priceAmount,
        'offer_price' => $priceAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'payable_amount_bdt' => $payableAmount,

        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'subadmin_commission' => $subadminProfit,
        'subadmin_uid' => $subadminUid,
        'customized_by_subadmin' => $customizedBySubadmin,

        'wallet_hold_amount' => $walletHoldAmount,
        'held_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'wallet_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],
        'hold_settled_at' => 0,
        'hold_settlement_status' => 'PENDING',
    ];

    $requestSaved = false;

    if (function_exists('create_bundle_pending_request')) {
        $requestSaved = create_bundle_pending_request(
            $requestId,
            $uid,
            $userPhone,
            $bundleNumber,
            $operator,
            $bundleName,
            $priceAmount,
            $note !== '' ? $note : 'Bundle request created from subadmin panel',
            false,
            '',
            $extra
        );
    } else {
        $requestSaved = fb_put('BUNDLE_REQUESTS/PENDING/' . $requestId, array_merge([
            'request_id' => $requestId,
            'uid' => $uid,
            'user_phone' => $userPhone,
            'bundle_number' => $bundleNumber,
            'operator' => $operator,
            'bundle_name' => $bundleName,
            'amount' => $priceAmount,
            'price_amount' => $priceAmount,
            'offer_price' => $priceAmount,
            'you_pay' => $payableAmount,
            'payable_amount' => $payableAmount,
            'note' => $note,
            'wallet_hold_amount' => $walletHoldAmount,
            'held_amount' => $walletHoldAmount,
            'wallet_debit_amount' => $walletHoldAmount,
            'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
            'wallet_currency' => $bundleFinancials['wallet_currency'],
            'rate_used' => $bundleFinancials['rate_used'],
            'status' => 'WAITING_ADMIN',
            'telegram_sent' => false,
            'telegram_queue_id' => '',
            'commission_status' => 'PENDING',
            'commission_credited_at' => 0,
            'user_commission_credited' => false,
            'subadmin_profit_credited' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $extra));
    }

    if (!$requestSaved) {
        fb_patch('USER_WALLETS/' . $uid, [
            'available_balance' => $currentAvailable,
            'hold_balance' => $currentHold,
            'updated_at' => subapi_now(),
        ]);

        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to create bundle request',
            'data' => [],
        ];
    }

    fb_put('REQUEST_STATUS/' . $requestId, [
        'request_id' => $requestId,
        'type' => 'BUNDLE',
        'uid' => $uid,
        'status' => 'WAITING_ADMIN',
        'message' => 'Bundle request created from subadmin panel',
        'updated_at' => $now,
    ]);

    $ledgerId = subapi_make_uid('LED');
    $ledgerMonth = date('Y-m', $now);

    fb_put('WALLET_LEDGER/' . $uid . '/' . $ledgerMonth . '/' . $ledgerId, [
        'ledger_id' => $ledgerId,
        'uid' => $uid,
        'type' => 'SUBADMIN_PANEL_BUNDLE_HOLD',
        'direction' => 'HOLD',
        'amount' => $walletHoldAmount,
        'currency' => $bundleFinancials['wallet_currency'],
        'wallet_currency' => $bundleFinancials['wallet_currency'],
        'price_amount' => $priceAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'payable_amount_bdt' => $payableAmount,
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'before_available' => $currentAvailable,
        'after_available' => $newAvailable,
        'before_hold' => $currentHold,
        'after_hold' => $newHold,
        'ref_id' => $requestId,
        'key_id' => 'PANEL',
        'offer_id' => $offerId,
        'note' => 'Payable amount moved to hold for subadmin panel bundle request',
        'created_at' => $now,
    ]);

    subapi_log_request($uid, [
        'request_id' => $requestId,
        'key_id' => 'PANEL',
        'action' => 'SUBADMIN_PANEL_BUNDLE_CREATE',
        'request_type' => 'BUNDLE',
        'status' => 'PENDING',
        'operator' => $operator,
        'bundle_number' => $bundleNumber,
        'topup_number' => $bundleNumber,
        'number' => $bundleNumber,
        'offer_id' => $offerId,
        'bundle_name' => $bundleName,
        'amount' => $priceAmount,
        'price_amount' => $priceAmount,
        'you_pay' => $payableAmount,
        'payable_amount' => $payableAmount,
        'payable_amount_bdt' => $payableAmount,
        'wallet_hold_amount' => $walletHoldAmount,
        'wallet_debit_amount' => $walletHoldAmount,
        'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
        'rate_used' => $bundleFinancials['rate_used'],
        'admin_commission' => $adminCommission,
        'user_commission' => $userCommission,
        'subadmin_profit' => $subadminProfit,
        'subadmin_commission' => $subadminProfit,
        'commission_status' => 'PENDING',
        'message' => $note !== '' ? $note : 'Bundle request created from subadmin panel',
        'source' => 'SUBADMIN_PANEL',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (function_exists('system_log')) {
        system_log('SUBADMIN_PANEL_BUNDLE_CREATE', $requestId, 'Bundle request created from subadmin panel', [
            'uid' => $uid,
            'offer_id' => $offerId,
            'operator' => $operator,
            'bundle_number' => $bundleNumber,
            'bundle_name' => $bundleName,
            'price_amount' => $priceAmount,
            'payable_amount' => $payableAmount,
            'wallet_hold_amount' => $walletHoldAmount,
            'wallet_debit_amount' => $walletHoldAmount,
            'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
            'rate_used' => $bundleFinancials['rate_used'],
            'admin_commission' => $adminCommission,
            'user_commission' => $userCommission,
            'subadmin_profit' => $subadminProfit,
        ]);
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Bundle request created successfully',
        'data' => [
            'request_id' => $requestId,
            'uid' => $uid,
            'status' => 'WAITING_ADMIN',
            'offer_id' => $offerId,
            'operator' => $operator,
            'bundle_number' => $bundleNumber,
            'bundle_name' => $bundleName,
            'amount' => $priceAmount,
            'price_amount' => $priceAmount,
            'you_pay' => $payableAmount,
            'payable_amount' => $payableAmount,
            'wallet_hold_amount' => $walletHoldAmount,
            'wallet_debit_amount' => $walletHoldAmount,
            'wallet_debit_currency' => $bundleFinancials['wallet_currency'],
            'rate_used' => $bundleFinancials['rate_used'],
            'admin_commission' => $adminCommission,
            'user_commission' => $userCommission,
            'subadmin_profit' => $subadminProfit,
            'subadmin_commission' => $subadminProfit,
            'created_at' => $now,
            'wallet' => [
                'available_balance' => $newAvailable,
                'hold_balance' => $newHold,
            ],
        ],
    ];
}
