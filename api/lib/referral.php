<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mfs_fee_tiers.php';

const REFERRAL_DEFAULT_ELIGIBLE_AFTER = 1789142400; // 2026-09-12 00:00 Asia/Kuala_Lumpur

function referral_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function referral_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    $value = strtoupper(trim((string)$value));
    if (in_array($value, ['1', 'TRUE', 'YES', 'ON', 'ACTIVE', 'ENABLED'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'FALSE', 'NO', 'OFF', 'INACTIVE', 'DISABLED'], true)) {
        return false;
    }
    return $default;
}

function referral_money($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '', trim($value));
    }
    return round(is_numeric($value) ? (float)$value : 0.0, 2);
}

function referral_clean_code($value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', trim((string)$value)) ?? '');
}

function referral_clean_uid($value): string
{
    $uid = trim((string)$value);
    return preg_match('/^[A-Za-z0-9_-]{2,128}$/', $uid) === 1 ? $uid : '';
}

function referral_user_role(array $user): string
{
    $role = strtoupper(trim((string)($user['role'] ?? 'USER')));
    return in_array($role, ['USER', 'RETAILER'], true) ? $role : 'USER';
}

function referral_user_active(array $user): bool
{
    $status = strtoupper(trim((string)($user['account_status'] ?? $user['status'] ?? '')));
    return $status === 'ACTIVE';
}

function referral_user_country(array $user, array $wallet = []): string
{
    if (function_exists('auth_pricing_country_from_user')) {
        $country = auth_pricing_country_from_user($user, $wallet);
        if (in_array($country, ['BD', 'MY'], true)) {
            return $country;
        }
    }

    $currency = strtoupper(trim((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? $user['wallet_currency'] ?? '')));
    return $currency === 'MYR' ? 'MY' : 'BD';
}

function referral_default_config(): array
{
    return [
        'enabled' => true,
        'claim_window_days' => 7,
        'eligible_after' => REFERRAL_DEFAULT_ELIGIBLE_AFTER,
        'config_version' => 1,
        'providers' => [
            'BKASH' => true,
            'NAGAD' => true,
        ],
        'my_commissions' => [
            'USER' => ['TIER1' => 1.00, 'TIER2' => 1.20, 'TIER3' => 2.00],
            'RETAILER' => ['TIER1' => 0.10, 'TIER2' => 0.15, 'TIER3' => 0.20],
        ],
        'bd_reward_bdt' => 10.00,
        'updated_at' => 0,
        'updated_by' => '',
    ];
}

function referral_config(bool $refresh = false): array
{
    static $cached = null;
    if (!$refresh && is_array($cached)) {
        return $cached;
    }

    $defaults = referral_default_config();
    $stored = fb_get('REFERRAL_CONFIG');
    $stored = is_array($stored) ? $stored : [];
    $providers = is_array($stored['providers'] ?? null) ? (array)$stored['providers'] : [];
    $commissions = is_array($stored['my_commissions'] ?? null) ? (array)$stored['my_commissions'] : [];

    $normalized = $defaults;
    $normalized['enabled'] = referral_bool($stored['enabled'] ?? $defaults['enabled'], true);
    $normalized['claim_window_days'] = max(1, min(90, (int)($stored['claim_window_days'] ?? $defaults['claim_window_days'])));
    $normalized['eligible_after'] = max(0, (int)($stored['eligible_after'] ?? $defaults['eligible_after']));
    $normalized['config_version'] = max(1, (int)($stored['config_version'] ?? $defaults['config_version']));
    foreach (['BKASH', 'NAGAD'] as $provider) {
        $normalized['providers'][$provider] = referral_bool($providers[$provider] ?? $defaults['providers'][$provider], true);
    }
    foreach (['USER', 'RETAILER'] as $role) {
        $roleRows = is_array($commissions[$role] ?? null) ? (array)$commissions[$role] : [];
        foreach (['TIER1', 'TIER2', 'TIER3'] as $tier) {
            $value = referral_money($roleRows[$tier] ?? $defaults['my_commissions'][$role][$tier]);
            $normalized['my_commissions'][$role][$tier] = max(0.0, $value);
        }
    }
    $normalized['bd_reward_bdt'] = max(0.0, referral_money($stored['bd_reward_bdt'] ?? $defaults['bd_reward_bdt']));
    $normalized['updated_at'] = max(0, (int)($stored['updated_at'] ?? 0));
    $normalized['updated_by'] = trim((string)($stored['updated_by'] ?? ''));

    $cached = $normalized;
    return $normalized;
}

function referral_current_my_fees(): array
{
    $stored = fb_get('MFS_SETTINGS/fees/MY');
    return mfs_normalize_my_fee_tiers(is_array($stored) ? $stored : []);
}

function referral_validate_admin_config(array $body): array
{
    $current = referral_config(true);
    $providers = is_array($body['providers'] ?? null) ? (array)$body['providers'] : [];
    $commissions = is_array($body['my_commissions'] ?? null) ? (array)$body['my_commissions'] : [];
    $candidate = $current;
    $candidate['enabled'] = referral_bool($body['enabled'] ?? $current['enabled'], $current['enabled']);
    $candidate['claim_window_days'] = (int)($body['claim_window_days'] ?? $current['claim_window_days']);
    $candidate['eligible_after'] = (int)($body['eligible_after'] ?? $current['eligible_after']);
    $candidate['bd_reward_bdt'] = referral_money($body['bd_reward_bdt'] ?? $current['bd_reward_bdt']);

    if ($candidate['claim_window_days'] < 1 || $candidate['claim_window_days'] > 90) {
        return ['ok' => false, 'code' => 'INVALID_CLAIM_WINDOW', 'message' => 'Claim window must be between 1 and 90 days.'];
    }
    if ($candidate['eligible_after'] < 0) {
        return ['ok' => false, 'code' => 'INVALID_ELIGIBLE_AFTER', 'message' => 'Eligible-after time is invalid.'];
    }
    if ($candidate['bd_reward_bdt'] < 0 || $candidate['bd_reward_bdt'] > 10000) {
        return ['ok' => false, 'code' => 'INVALID_BD_REWARD', 'message' => 'Bangladesh reward must be between BDT 0 and BDT 10,000.'];
    }

    foreach (['BKASH', 'NAGAD'] as $provider) {
        $candidate['providers'][$provider] = referral_bool($providers[$provider] ?? $current['providers'][$provider], $current['providers'][$provider]);
    }

    $feeTiers = referral_current_my_fees();
    foreach (['USER', 'RETAILER'] as $role) {
        $roleRows = is_array($commissions[$role] ?? null) ? (array)$commissions[$role] : [];
        foreach (['TIER1', 'TIER2', 'TIER3'] as $tier) {
            $raw = $roleRows[$tier] ?? $current['my_commissions'][$role][$tier];
            if (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_COMMISSION',
                    'message' => 'Commission values must be valid non-negative numbers.',
                    'data' => ['field' => 'my_commissions.' . $role . '.' . $tier],
                ];
            }
            $amount = referral_money($raw);
            $fee = (float)($feeTiers[$tier][$role] ?? 0.0);
            if ($amount > $fee) {
                return [
                    'ok' => false,
                    'code' => 'COMMISSION_EXCEEDS_FEE',
                    'message' => $role . ' ' . $tier . ' commission cannot exceed its MFS fee.',
                    'data' => ['field' => 'my_commissions.' . $role . '.' . $tier, 'maximum' => $fee],
                ];
            }
            $candidate['my_commissions'][$role][$tier] = $amount;
        }
    }

    return ['ok' => true, 'config' => $candidate];
}

function referral_save_admin_config(array $body, string $adminUid): array
{
    $validated = referral_validate_admin_config($body);
    if (empty($validated['ok'])) {
        return $validated;
    }

    $config = (array)$validated['config'];
    $config['config_version'] = max(1, (int)($config['config_version'] ?? 1) + 1);
    $config['updated_at'] = referral_now();
    $config['updated_by'] = referral_clean_uid($adminUid);
    if (!fb_put('REFERRAL_CONFIG', $config)) {
        return ['ok' => false, 'code' => 'REFERRAL_CONFIG_SAVE_FAILED', 'message' => 'Failed to save referral settings.'];
    }
    referral_config(true);
    return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Referral settings saved.', 'data' => ['config' => $config]];
}

function referral_load_user(string $uid): array
{
    $uid = referral_clean_uid($uid);
    $row = $uid !== '' ? fb_get('USERS/' . $uid) : null;
    if (!is_array($row)) {
        return [];
    }
    $row['uid'] = (string)($row['uid'] ?? $uid);
    return $row;
}

function referral_load_wallet(string $uid): array
{
    $row = fb_get('USER_WALLETS/' . referral_clean_uid($uid));
    return is_array($row) ? $row : [];
}

function referral_code_for_uid(string $uid): string
{
    $uid = referral_clean_uid($uid);
    if ($uid === '') {
        return '';
    }
    $secret = defined('APP_KEY') ? (string)APP_KEY : __FILE__;
    return 'ZP' . strtoupper(substr(hash_hmac('sha256', $uid, $secret), 0, 10));
}

function referral_ensure_code(string $uid): string
{
    $uid = referral_clean_uid($uid);
    if ($uid === '') {
        return '';
    }
    $existing = referral_clean_code(fb_get('USER_REFERRAL_CODES/' . $uid));
    if ($existing !== '') {
        $owner = referral_clean_uid(fb_get('REFERRAL_CODES/' . $existing . '/uid'));
        if ($owner === $uid) {
            return $existing;
        }
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $base = referral_code_for_uid($uid);
        $code = $attempt === 0 ? $base : $base . strtoupper(substr(hash('sha256', $uid . '|' . $attempt), 0, 2));
        $path = 'REFERRAL_CODES/' . $code;
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || empty($snapshot['etag'])) {
            continue;
        }
        $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $owner = referral_clean_uid($current['uid'] ?? '');
        if ($owner !== '' && $owner !== $uid) {
            continue;
        }
        $row = [
            'code' => $code,
            'uid' => $uid,
            'active' => true,
            'created_at' => (int)($current['created_at'] ?? referral_now()),
            'updated_at' => referral_now(),
        ];
        $save = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (($save['status'] ?? 0) === 412) {
            continue;
        }
        if (!empty($save['ok']) && fb_put('USER_REFERRAL_CODES/' . $uid, $code)) {
            return $code;
        }
    }
    return '';
}

function referral_has_any_mfs_request(string $uid): bool
{
    $uid = referral_clean_uid($uid);
    if ($uid === '') {
        return true;
    }
    $statusCounters = fb_get('MFS_USER_STATUS_COUNTERS/' . $uid, ['shallow' => 'true']);
    if (is_array($statusCounters) && $statusCounters !== []) {
        return true;
    }
    $userRequests = fb_get('USER_API_REQUESTS/' . $uid, ['shallow' => 'true']);
    if (is_array($userRequests) && $userRequests !== []) {
        return true;
    }
    $history = fb_get('MFS_HISTORY/' . $uid);
    if (is_array($history) && $history !== []) {
        return true;
    }
    return false;
}

function referral_claim_eligibility(array $user, array $config, bool $checkMfs = true): array
{
    if (empty($config['enabled'])) {
        return ['eligible' => false, 'code' => 'REFERRAL_DISABLED', 'message' => 'Referral program is currently unavailable.'];
    }
    if (!referral_user_active($user)) {
        return ['eligible' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Your account must be active before claiming a referral.'];
    }
    $uid = referral_clean_uid($user['uid'] ?? '');
    $createdAt = max(0, (int)($user['created_at'] ?? 0));
    $eligibleAfter = max(0, (int)($config['eligible_after'] ?? 0));
    if ($createdAt <= 0 || ($eligibleAfter > 0 && $createdAt < $eligibleAfter)) {
        return ['eligible' => false, 'code' => 'ACCOUNT_NOT_ELIGIBLE', 'message' => 'This account is not eligible to claim a referral code.'];
    }
    $expiresAt = $createdAt + (max(1, (int)$config['claim_window_days']) * 86400);
    if (referral_now() > $expiresAt) {
        return ['eligible' => false, 'code' => 'CLAIM_WINDOW_EXPIRED', 'message' => 'The referral claim period for this account has expired.', 'expires_at' => $expiresAt];
    }
    if ($checkMfs && referral_has_any_mfs_request($uid)) {
        return ['eligible' => false, 'code' => 'FIRST_REQUEST_ALREADY_CREATED', 'message' => 'A referral code must be claimed before the first bKash or Nagad request.'];
    }
    return ['eligible' => true, 'code' => 'ELIGIBLE', 'message' => 'This account can claim one referral code.', 'expires_at' => $expiresAt];
}

function referral_would_create_cycle(string $referredUid, string $referrerUid): bool
{
    $cursor = referral_clean_uid($referrerUid);
    for ($depth = 0; $depth < 25 && $cursor !== ''; $depth++) {
        if ($cursor === $referredUid) {
            return true;
        }
        $row = fb_get('USER_REFERRALS/' . $cursor);
        $cursor = is_array($row) ? referral_clean_uid($row['referrer_uid'] ?? '') : '';
    }
    return false;
}

function referral_device_fingerprint($deviceKey): string
{
    $deviceKey = strtolower(trim((string)$deviceKey));
    if (preg_match('/^zprd-[a-f0-9]{64}$/', $deviceKey) !== 1) {
        return '';
    }
    return hash('sha256', 'ZPAY_REFERRAL_DEVICE_V1|' . $deviceKey);
}

function referral_claim_device_lock(string $uid, string $deviceKey): array
{
    $uid = referral_clean_uid($uid);
    $fingerprint = referral_device_fingerprint($deviceKey);
    if ($uid === '' || $fingerprint === '') {
        return ['ok' => false, 'code' => 'DEVICE_VERIFICATION_REQUIRED', 'message' => 'Open Z-Pay Swift on your Android device to verify this referral.'];
    }

    $path = 'REFERRAL_DEVICE_LOCKS/' . $fingerprint;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || empty($snapshot['etag'])) {
            return ['ok' => false, 'code' => 'DEVICE_LOCK_UNAVAILABLE', 'message' => 'Device verification is temporarily unavailable.'];
        }
        $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $ownerUid = referral_clean_uid($current['uid'] ?? '');
        if ($ownerUid !== '' && $ownerUid !== $uid) {
            return [
                'ok' => false,
                'code' => 'DEVICE_ALREADY_USED',
                'message' => 'This device is already linked to another account for referral rewards.',
                'device_fingerprint' => $fingerprint,
            ];
        }
        $now = referral_now();
        $row = [
            'uid' => $uid,
            'device_fingerprint' => $fingerprint,
            'created_at' => (int)($current['created_at'] ?? $now),
            'verified_at' => $now,
            'updated_at' => $now,
        ];
        $save = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        if (empty($save['ok'])) {
            return ['ok' => false, 'code' => 'DEVICE_LOCK_FAILED', 'message' => 'Device verification could not be completed.'];
        }
        fb_put('REFERRAL_USER_DEVICES/' . $uid . '/' . $fingerprint, [
            'device_fingerprint' => $fingerprint,
            'verified_at' => $now,
        ]);
        return ['ok' => true, 'code' => 'DEVICE_VERIFIED', 'device_fingerprint' => $fingerprint, 'verified_at' => $now];
    }
    return ['ok' => false, 'code' => 'DEVICE_LOCK_CONFLICT', 'message' => 'Device verification is busy. Please retry.'];
}

function referral_mask_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'Z-Pay user';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $first = (string)($parts[0] ?? $name);
    if (function_exists('mb_substr')) {
        return mb_substr($first, 0, 1, 'UTF-8') . str_repeat('*', max(2, min(6, mb_strlen($first, 'UTF-8') - 1)));
    }
    return substr($first, 0, 1) . str_repeat('*', max(2, min(6, strlen($first) - 1)));
}

function referral_member_row(array $relation): array
{
    return [
        'relation_id' => (string)($relation['relation_id'] ?? ''),
        'referrer_uid' => (string)($relation['referrer_uid'] ?? ''),
        'referred_uid' => (string)($relation['referred_uid'] ?? ''),
        'referred_name_masked' => (string)($relation['referred_name_masked'] ?? ''),
        'country' => (string)($relation['country'] ?? ''),
        'program' => (string)($relation['program'] ?? ''),
        'status' => (string)($relation['status'] ?? ''),
        'device_verified' => !empty($relation['device_verified']),
        'created_at' => (int)($relation['created_at'] ?? 0),
        'activated_at' => (int)($relation['activated_at'] ?? 0),
    ];
}

function referral_sync_member(array $relation): bool
{
    $referrerUid = referral_clean_uid($relation['referrer_uid'] ?? '');
    $referredUid = referral_clean_uid($relation['referred_uid'] ?? '');
    if ($referrerUid === '' || $referredUid === '') {
        return false;
    }
    return fb_put('REFERRAL_MEMBERS/' . $referrerUid . '/' . $referredUid, referral_member_row($relation));
}

function referral_payout_id(string $prefix, string $seed): string
{
    return strtoupper($prefix) . '-' . strtoupper(substr(hash('sha256', $seed), 0, 28));
}

function referral_store_payout(array $payout): bool
{
    $payoutId = trim((string)($payout['payout_id'] ?? ''));
    $referrerUid = referral_clean_uid($payout['referrer_uid'] ?? '');
    if ($payoutId === '' || $referrerUid === '') {
        return false;
    }
    $ok = fb_put('REFERRAL_PAYOUTS/' . $payoutId, $payout);
    $mirror = fb_put('USER_REFERRAL_PAYOUTS/' . $referrerUid . '/' . $payoutId, $payout);
    return $ok && $mirror;
}

function referral_record_payout_notification(array $payout): void
{
    $referrerUid = referral_clean_uid($payout['referrer_uid'] ?? '');
    $payoutId = trim((string)($payout['payout_id'] ?? ''));
    $currency = strtoupper(trim((string)($payout['currency'] ?? '')));
    $amount = referral_money($payout['amount'] ?? 0);
    if ($referrerUid === '' || $payoutId === '' || $amount <= 0 || !in_array($currency, ['BDT', 'MYR'], true)) {
        return;
    }
    $amountText = $currency === 'MYR' ? 'RM ' . number_format($amount, 2, '.', '') : 'BDT ' . number_format($amount, 2, '.', '');
    notification_record_user(
        $referrerUid,
        'REFERRAL_REWARD',
        'Referral Reward Received',
        $amountText . ' has been added to your wallet.',
        'REFERRAL_PAYOUT',
        $payoutId,
        'REFERRAL_PAYOUT:' . $payoutId,
        ['amount' => $amount, 'currency' => $currency, 'status' => 'SUCCESSFUL']
    );
}

function referral_execute_payout(array $payout): array
{
    $payoutId = trim((string)($payout['payout_id'] ?? ''));
    $referrerUid = referral_clean_uid($payout['referrer_uid'] ?? '');
    $amount = referral_money($payout['amount'] ?? 0);
    $currency = strtoupper(trim((string)($payout['currency'] ?? '')));
    $type = strtoupper(trim((string)($payout['payout_type'] ?? '')));
    if ($payoutId === '' || $referrerUid === '' || $amount <= 0 || !in_array($currency, ['BDT', 'MYR'], true)) {
        return ['ok' => false, 'code' => 'PAYOUT_INVALID', 'message' => 'Referral payout data is invalid.'];
    }

    $existing = fb_get('REFERRAL_PAYOUTS/' . $payoutId);
    if (is_array($existing) && strtoupper(trim((string)($existing['status'] ?? ''))) === 'COMPLETED') {
        referral_store_payout($existing);
        referral_record_payout_notification($existing);
        return ['ok' => true, 'duplicate' => true, 'code' => 'SUCCESS', 'data' => $existing];
    }

    $now = referral_now();
    $payout = array_merge(is_array($existing) ? $existing : [], $payout, [
        'payout_id' => $payoutId,
        'referrer_uid' => $referrerUid,
        'amount' => $amount,
        'currency' => $currency,
        'status' => 'PROCESSING',
        'attempts' => max(0, (int)($existing['attempts'] ?? 0)) + 1,
        'created_at' => (int)($existing['created_at'] ?? $now),
        'updated_at' => $now,
    ]);
    referral_store_payout($payout);

    $wallet = referral_load_wallet($referrerUid);
    $walletCurrency = function_exists('wallet_currency_for_uid')
        ? wallet_currency_for_uid($referrerUid, $wallet)
        : strtoupper(trim((string)($wallet['wallet_currency'] ?? $wallet['currency'] ?? '')));
    if ($walletCurrency !== $currency) {
        $payout['status'] = 'RETRY_REQUIRED';
        $payout['last_error_code'] = 'WALLET_CURRENCY_MISMATCH';
        $payout['last_error_message'] = 'Referrer wallet currency does not match the referral program.';
        $payout['updated_at'] = referral_now();
        referral_store_payout($payout);
        return ['ok' => false, 'code' => 'WALLET_CURRENCY_MISMATCH', 'message' => $payout['last_error_message'], 'data' => $payout];
    }

    $operationType = $type === 'BD_ONETIME' ? 'REFERRAL_BD_REWARD' : 'REFERRAL_MFS_COMMISSION';
    $operation = wallet_financial_operation_begin(
        $payoutId,
        $operationType,
        'REFERRAL_PAYOUT',
        $referrerUid,
        $amount,
        $currency,
        [
            'relation_id' => (string)($payout['relation_id'] ?? ''),
            'referred_uid' => (string)($payout['referred_uid'] ?? ''),
            'source_request_id' => (string)($payout['source_request_id'] ?? ''),
            'payout_type' => $type,
        ]
    );

    if (!empty($operation['duplicate']) && !empty($operation['completed'])) {
        $payout['status'] = 'COMPLETED';
        $payout['completed_at'] = (int)($payout['completed_at'] ?? referral_now());
        $payout['updated_at'] = referral_now();
        referral_store_payout($payout);
        referral_record_payout_notification($payout);
        return ['ok' => true, 'duplicate' => true, 'code' => 'SUCCESS', 'data' => $payout];
    }
    if (empty($operation['ok']) || empty($operation['claim'])) {
        $payout['status'] = 'RETRY_REQUIRED';
        $payout['last_error_code'] = (string)($operation['code'] ?? 'PAYOUT_OPERATION_UNAVAILABLE');
        $payout['last_error_message'] = (string)($operation['message'] ?? 'Referral payout safety check failed.');
        $payout['updated_at'] = referral_now();
        referral_store_payout($payout);
        return ['ok' => false, 'code' => $payout['last_error_code'], 'message' => $payout['last_error_message'], 'data' => $payout];
    }

    $claim = (array)$operation['claim'];
    $credit = wallet_credit_available(
        $referrerUid,
        $amount,
        $payoutId,
        $operationType,
        $type === 'BD_ONETIME' ? 'Bangladesh referral reward' : 'Referral commission from successful MFS request',
        [
            'ledger_id' => wallet_financial_operation_ledger_id($payoutId, $operationType),
            'request_id' => (string)($payout['source_request_id'] ?? $payoutId),
            'payout_id' => $payoutId,
            'relation_id' => (string)($payout['relation_id'] ?? ''),
            'referred_uid' => (string)($payout['referred_uid'] ?? ''),
            'provider' => (string)($payout['provider'] ?? ''),
            'fee_tier_id' => (string)($payout['fee_tier_id'] ?? ''),
            'currency' => $currency,
            'wallet_currency' => $currency,
        ],
        ['financial_operation' => $claim]
    );
    if (empty($credit['ok'])) {
        wallet_financial_operation_mark_failed($claim, (string)($credit['code'] ?? 'PAYOUT_CREDIT_FAILED'), (string)($credit['message'] ?? 'Referral payout failed.'));
        $payout['status'] = 'RETRY_REQUIRED';
        $payout['last_error_code'] = (string)($credit['code'] ?? 'PAYOUT_CREDIT_FAILED');
        $payout['last_error_message'] = (string)($credit['message'] ?? 'Referral payout failed.');
        $payout['updated_at'] = referral_now();
        referral_store_payout($payout);
        return ['ok' => false, 'code' => $payout['last_error_code'], 'message' => $payout['last_error_message'], 'data' => $payout];
    }

    $completedAt = referral_now();
    wallet_financial_operation_mark_completed($claim, [
        'wallet_applied' => true,
        'ledger_written' => true,
        'ledger_id' => (string)($credit['ledger_id'] ?? ''),
        'payout_id' => $payoutId,
    ]);
    $payout['status'] = 'COMPLETED';
    $payout['ledger_id'] = (string)($credit['ledger_id'] ?? '');
    $payout['completed_at'] = $completedAt;
    $payout['updated_at'] = $completedAt;
    $payout['last_error_code'] = '';
    $payout['last_error_message'] = '';
    referral_store_payout($payout);
    referral_record_payout_notification($payout);

    return ['ok' => true, 'code' => 'SUCCESS', 'message' => 'Referral reward credited.', 'data' => $payout];
}

function referral_bd_payout_for_relation(array $relation): array
{
    if (strtoupper(trim((string)($relation['country'] ?? ''))) !== 'BD' || strtoupper(trim((string)($relation['status'] ?? ''))) !== 'ACTIVE') {
        return ['ok' => true, 'code' => 'NOT_APPLICABLE'];
    }
    $config = referral_config();
    $amount = referral_money($config['bd_reward_bdt'] ?? 0);
    if (empty($config['enabled']) || $amount <= 0) {
        return ['ok' => true, 'code' => 'NO_REWARD_CONFIGURED'];
    }
    $referredUid = referral_clean_uid($relation['referred_uid'] ?? '');
    $payoutId = referral_payout_id('RBD', $referredUid);
    return referral_execute_payout([
        'payout_id' => $payoutId,
        'payout_type' => 'BD_ONETIME',
        'relation_id' => (string)($relation['relation_id'] ?? ''),
        'referrer_uid' => (string)($relation['referrer_uid'] ?? ''),
        'referred_uid' => $referredUid,
        'referred_name_masked' => (string)($relation['referred_name_masked'] ?? ''),
        'country' => 'BD',
        'amount' => $amount,
        'currency' => 'BDT',
        'config_version' => (int)($config['config_version'] ?? 1),
        'source_request_id' => '',
        'provider' => '',
        'fee_tier_id' => '',
    ]);
}

function referral_activate_relation_device(string $uid, string $deviceKey): array
{
    $uid = referral_clean_uid($uid);
    $relation = fb_get('USER_REFERRALS/' . $uid);
    $relation = is_array($relation) ? $relation : [];

    if ($relation === []) {
        return ['ok' => true, 'code' => 'DEVICE_READY', 'message' => 'Referral device is ready.', 'relation' => []];
    }
    if (strtoupper(trim((string)($relation['status'] ?? ''))) === 'BLOCKED_DEVICE') {
        return ['ok' => false, 'code' => 'DEVICE_ALREADY_USED', 'message' => 'This account is not eligible for referral rewards on this device.', 'relation' => $relation];
    }

    $device = referral_claim_device_lock($uid, $deviceKey);

    if (empty($device['ok'])) {
        if ($relation !== [] && ($device['code'] ?? '') === 'DEVICE_ALREADY_USED') {
            $relation['status'] = 'BLOCKED_DEVICE';
            $relation['device_verified'] = false;
            $relation['blocked_reason'] = 'DEVICE_ALREADY_USED';
            $relation['updated_at'] = referral_now();
            fb_put('USER_REFERRALS/' . $uid, $relation);
            referral_sync_member($relation);
        }
        return array_merge($device, ['relation' => $relation]);
    }

    $now = referral_now();
    $relation['status'] = 'ACTIVE';
    $relation['device_verified'] = true;
    $relation['device_fingerprint'] = (string)$device['device_fingerprint'];
    $relation['device_verified_at'] = $now;
    $relation['activated_at'] = (int)($relation['activated_at'] ?? 0) ?: $now;
    $relation['updated_at'] = $now;
    if (!fb_put('USER_REFERRALS/' . $uid, $relation)) {
        return ['ok' => false, 'code' => 'RELATION_ACTIVATION_FAILED', 'message' => 'Referral verification could not be saved.'];
    }
    referral_sync_member($relation);

    $payout = referral_bd_payout_for_relation($relation);
    return [
        'ok' => true,
        'code' => 'REFERRAL_ACTIVE',
        'message' => 'Referral verified successfully.',
        'relation' => $relation,
        'payout' => $payout,
    ];
}

function referral_claim(string $uid, string $code, string $client = 'WEB', string $deviceKey = ''): array
{
    $uid = referral_clean_uid($uid);
    $code = referral_clean_code($code);
    $client = strtoupper(trim($client));
    if ($uid === '' || strlen($code) < 8 || strlen($code) > 20) {
        return ['ok' => false, 'code' => 'INVALID_REFERRAL_CODE', 'message' => 'Enter a valid referral code.'];
    }

    $user = referral_load_user($uid);
    $wallet = referral_load_wallet($uid);
    if ($user === [] || !referral_user_active($user)) {
        return ['ok' => false, 'code' => 'ACCOUNT_INACTIVE', 'message' => 'Your account must be active before claiming a referral.'];
    }

    $existing = fb_get('USER_REFERRALS/' . $uid);
    if (is_array($existing) && $existing !== []) {
        if (referral_clean_code($existing['referral_code'] ?? '') !== $code) {
            return ['ok' => false, 'code' => 'REFERRAL_ALREADY_CLAIMED', 'message' => 'A referral code is already linked to this account.', 'data' => ['relation' => $existing]];
        }
        if ($deviceKey !== '') {
            return referral_activate_relation_device($uid, $deviceKey);
        }
        return ['ok' => true, 'code' => 'REFERRAL_ALREADY_LINKED', 'message' => 'This referral code is already linked.', 'data' => ['relation' => $existing]];
    }

    $config = referral_config();
    $eligibility = referral_claim_eligibility($user, $config, true);
    if (empty($eligibility['eligible'])) {
        return ['ok' => false, 'code' => (string)$eligibility['code'], 'message' => (string)$eligibility['message']];
    }

    $index = fb_get('REFERRAL_CODES/' . $code);
    $index = is_array($index) ? $index : [];
    $referrerUid = referral_clean_uid($index['uid'] ?? '');
    if ($referrerUid === '' || empty($index['active'])) {
        return ['ok' => false, 'code' => 'INVALID_REFERRAL_CODE', 'message' => 'Referral code was not found.'];
    }
    if ($referrerUid === $uid) {
        return ['ok' => false, 'code' => 'SELF_REFERRAL_NOT_ALLOWED', 'message' => 'You cannot use your own referral code.'];
    }
    if (referral_would_create_cycle($uid, $referrerUid)) {
        return ['ok' => false, 'code' => 'REFERRAL_CYCLE_NOT_ALLOWED', 'message' => 'This referral relationship is not allowed.'];
    }

    $referrer = referral_load_user($referrerUid);
    $referrerWallet = referral_load_wallet($referrerUid);
    if ($referrer === [] || !referral_user_active($referrer)) {
        return ['ok' => false, 'code' => 'REFERRER_INACTIVE', 'message' => 'This referral code is not currently active.'];
    }
    $country = referral_user_country($user, $wallet);
    if ($country !== referral_user_country($referrer, $referrerWallet)) {
        return ['ok' => false, 'code' => 'REFERRAL_MARKET_MISMATCH', 'message' => 'Referral accounts must belong to the same country program.'];
    }

    $now = referral_now();
    $relation = [
        'relation_id' => 'RR-' . strtoupper(substr(hash('sha256', $referrerUid . '|' . $uid), 0, 24)),
        'referral_code' => $code,
        'referrer_uid' => $referrerUid,
        'referred_uid' => $uid,
        'referred_name_masked' => referral_mask_name((string)($user['name'] ?? '')),
        'country' => $country,
        'program' => $country === 'BD' ? 'BD_ONETIME' : 'MY_RECURRING',
        'status' => 'PENDING_DEVICE',
        'device_verified' => false,
        'claimed_via' => in_array($client, ['ANDROID', 'WEB'], true) ? $client : 'WEB',
        'claim_expires_at' => (int)($eligibility['expires_at'] ?? 0),
        'config_version' => (int)($config['config_version'] ?? 1),
        'created_at' => $now,
        'updated_at' => $now,
        'activated_at' => 0,
    ];

    $snapshot = fb_get_with_etag('USER_REFERRALS/' . $uid);
    if (empty($snapshot['ok']) || empty($snapshot['etag'])) {
        return ['ok' => false, 'code' => 'REFERRAL_LINK_UNAVAILABLE', 'message' => 'Referral service is temporarily unavailable.'];
    }
    if (is_array($snapshot['value'] ?? null) && $snapshot['value'] !== []) {
        return ['ok' => false, 'code' => 'REFERRAL_ALREADY_CLAIMED', 'message' => 'A referral code is already linked to this account.'];
    }
    $saved = fb_put_if_match('USER_REFERRALS/' . $uid, $relation, (string)$snapshot['etag']);
    if (($saved['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'REFERRAL_ALREADY_CLAIMED', 'message' => 'A referral code is already linked to this account.'];
    }
    if (empty($saved['ok'])) {
        return ['ok' => false, 'code' => 'REFERRAL_LINK_FAILED', 'message' => 'Referral code could not be linked.'];
    }
    referral_sync_member($relation);

    if ($deviceKey !== '') {
        return referral_activate_relation_device($uid, $deviceKey);
    }
    return [
        'ok' => true,
        'code' => 'DEVICE_VERIFICATION_REQUIRED',
        'message' => 'Referral linked. Open the Android app to verify this device and activate rewards.',
        'data' => ['relation' => $relation],
    ];
}

function referral_mfs_snapshot(array $user, array $wallet, array $amounts, string $provider): array
{
    $uid = referral_clean_uid($user['uid'] ?? '');
    $provider = strtoupper(trim($provider));
    $config = referral_config();
    if ($uid === '' || empty($config['enabled']) || empty($config['providers'][$provider])) {
        return [];
    }
    if (referral_user_country($user, $wallet) !== 'MY' || strtoupper(trim((string)($amounts['wallet_currency'] ?? ''))) !== 'MYR') {
        return [];
    }
    $relation = fb_get('USER_REFERRALS/' . $uid);
    if (!is_array($relation)
        || strtoupper(trim((string)($relation['status'] ?? ''))) !== 'ACTIVE'
        || empty($relation['device_verified'])
        || strtoupper(trim((string)($relation['program'] ?? ''))) !== 'MY_RECURRING'
    ) {
        return [];
    }
    $role = referral_user_role($user);
    $tier = strtoupper(trim((string)($amounts['fee_tier_id'] ?? '')));
    if (!in_array($tier, ['TIER1', 'TIER2', 'TIER3'], true)) {
        return [];
    }
    $commission = referral_money($config['my_commissions'][$role][$tier] ?? 0);
    $fee = referral_money($amounts['fee_rm'] ?? 0);
    if ($commission <= 0 || $commission > $fee) {
        return [];
    }
    return [
        'eligible' => true,
        'relation_id' => (string)($relation['relation_id'] ?? ''),
        'referrer_uid' => (string)($relation['referrer_uid'] ?? ''),
        'referred_uid' => $uid,
        'referred_name_masked' => (string)($relation['referred_name_masked'] ?? ''),
        'program' => 'MY_RECURRING',
        'country' => 'MY',
        'provider' => $provider,
        'referred_role' => $role,
        'fee_tier_id' => $tier,
        'source_fee_amount' => $fee,
        'source_fee_currency' => 'MYR',
        'commission_amount' => $commission,
        'commission_currency' => 'MYR',
        'config_version' => (int)($config['config_version'] ?? 1),
        'snapshotted_at' => referral_now(),
    ];
}

function referral_process_mfs_success(string $requestId, array $row): array
{
    $snapshot = is_array($row['referral'] ?? null) ? (array)$row['referral'] : [];
    if (empty($snapshot['eligible']) || strtoupper(trim((string)($snapshot['program'] ?? ''))) !== 'MY_RECURRING') {
        return ['ok' => true, 'code' => 'NOT_APPLICABLE'];
    }
    $amount = referral_money($snapshot['commission_amount'] ?? 0);
    if ($amount <= 0) {
        return ['ok' => true, 'code' => 'NO_COMMISSION'];
    }
    $payoutId = referral_payout_id('RMY', $requestId);
    return referral_execute_payout([
        'payout_id' => $payoutId,
        'payout_type' => 'MY_RECURRING',
        'relation_id' => (string)($snapshot['relation_id'] ?? ''),
        'referrer_uid' => (string)($snapshot['referrer_uid'] ?? ''),
        'referred_uid' => (string)($snapshot['referred_uid'] ?? $row['uid'] ?? ''),
        'referred_name_masked' => (string)($snapshot['referred_name_masked'] ?? ''),
        'country' => 'MY',
        'amount' => $amount,
        'currency' => 'MYR',
        'config_version' => (int)($snapshot['config_version'] ?? 1),
        'source_request_id' => $requestId,
        'provider' => (string)($snapshot['provider'] ?? $row['provider'] ?? ''),
        'fee_tier_id' => (string)($snapshot['fee_tier_id'] ?? ''),
        'source_fee_amount' => referral_money($snapshot['source_fee_amount'] ?? 0),
        'source_fee_currency' => 'MYR',
    ]);
}

function referral_public_relation(array $relation): array
{
    if ($relation === []) {
        return [];
    }
    return [
        'relation_id' => (string)($relation['relation_id'] ?? ''),
        'country' => (string)($relation['country'] ?? ''),
        'program' => (string)($relation['program'] ?? ''),
        'status' => (string)($relation['status'] ?? ''),
        'device_verified' => !empty($relation['device_verified']),
        'created_at' => (int)($relation['created_at'] ?? 0),
        'activated_at' => (int)($relation['activated_at'] ?? 0),
    ];
}

function referral_public_action_data(array $result): array
{
    $internalData = is_array($result['data'] ?? null) ? (array)$result['data'] : [];
    $relation = is_array($result['relation'] ?? null)
        ? (array)$result['relation']
        : (is_array($internalData['relation'] ?? null) ? (array)$internalData['relation'] : []);
    $public = ['relation' => referral_public_relation($relation)];

    $payout = is_array($result['payout'] ?? null) ? (array)$result['payout'] : [];
    if ($payout !== []) {
        $public['reward'] = [
            'processed' => !empty($payout['ok']),
            'code' => (string)($payout['code'] ?? ''),
        ];
    }
    return $public;
}

function referral_history_for_user(string $uid, int $limit = 10, int $before = 0): array
{
    $rows = fb_get('USER_REFERRAL_PAYOUTS/' . referral_clean_uid($uid));
    $rows = is_array($rows) ? $rows : [];
    $items = [];
    foreach ($rows as $payoutId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $createdAt = (int)($row['created_at'] ?? 0);
        if ($before > 0 && $createdAt >= $before) {
            continue;
        }
        $items[] = [
            'payout_id' => (string)($row['payout_id'] ?? $payoutId),
            'payout_type' => (string)($row['payout_type'] ?? ''),
            'amount' => referral_money($row['amount'] ?? 0),
            'currency' => (string)($row['currency'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'provider' => (string)($row['provider'] ?? ''),
            'fee_tier_id' => (string)($row['fee_tier_id'] ?? ''),
            'source_request_id' => (string)($row['source_request_id'] ?? ''),
            'referred_name_masked' => (string)($row['referred_name_masked'] ?? 'Z-Pay user'),
            'created_at' => $createdAt,
            'completed_at' => (int)($row['completed_at'] ?? 0),
        ];
    }
    usort($items, static fn(array $a, array $b): int => (int)$b['created_at'] <=> (int)$a['created_at']);
    $limit = max(1, min(50, $limit));
    $page = array_slice($items, 0, $limit);
    $hasMore = count($items) > count($page);
    return [
        'items' => $page,
        'has_more' => $hasMore,
        'next_before' => $hasMore && $page !== [] ? (int)end($page)['created_at'] : 0,
    ];
}

function referral_status_payload(string $uid, int $limit = 10, int $before = 0, string $scope = 'full'): array
{
    $uid = referral_clean_uid($uid);
    $scope = strtolower(trim($scope));
    if (!in_array($scope, ['core', 'history', 'full'], true)) {
        $scope = 'full';
    }
    if ($scope === 'history') {
        return [
            'scope' => 'history',
            'history' => referral_history_for_user($uid, $limit, $before),
        ];
    }

    $user = referral_load_user($uid);
    $wallet = referral_load_wallet($uid);
    $config = referral_config();
    $country = referral_user_country($user, $wallet);
    $code = referral_ensure_code($uid);
    $relation = fb_get('USER_REFERRALS/' . $uid);
    $relation = is_array($relation) ? $relation : [];
    $eligibility = $relation === []
        ? referral_claim_eligibility($user, $config, true)
        : ['eligible' => false, 'code' => 'REFERRAL_ALREADY_CLAIMED', 'message' => 'A referral is already linked.'];

    $members = fb_get('REFERRAL_MEMBERS/' . $uid, ['shallow' => 'true']);
    $members = is_array($members) ? $members : [];
    $historyAll = fb_get('USER_REFERRAL_PAYOUTS/' . $uid);
    $historyAll = is_array($historyAll) ? $historyAll : [];
    $total = 0.0;
    $rewarded = 0;
    foreach ($historyAll as $row) {
        if (!is_array($row) || strtoupper(trim((string)($row['status'] ?? ''))) !== 'COMPLETED') {
            continue;
        }
        $total += referral_money($row['amount'] ?? 0);
        $rewarded++;
    }
    $payload = [
        'scope' => $scope,
        'enabled' => !empty($config['enabled']),
        'country' => $country,
        'account_role' => referral_user_role($user),
        'program' => $country === 'BD' ? 'BD_ONETIME' : 'MY_RECURRING',
        'reward_currency' => $country === 'BD' ? 'BDT' : 'MYR',
        'referral_code' => $code,
        'share_url' => $code !== '' ? 'https://zpayswift.com/user/referral?code=' . rawurlencode($code) : '',
        'total_earned' => round($total, 2),
        'referred_count' => count($members),
        'rewarded_count' => $rewarded,
        'relation' => referral_public_relation($relation),
        'claim' => $eligibility,
        'device_verification_required' => $relation !== [] && strtoupper(trim((string)($relation['status'] ?? ''))) === 'PENDING_DEVICE',
        'rules' => [
            'claim_window_days' => (int)$config['claim_window_days'],
            'bd_reward_bdt' => (float)$config['bd_reward_bdt'],
            'my_commissions' => $config['my_commissions'],
            'my_fees' => referral_current_my_fees(),
            'providers' => $config['providers'],
        ],
    ];
    if ($scope === 'full') {
        $payload['history'] = referral_history_for_user($uid, $limit, $before);
    }
    return $payload;
}

function referral_admin_payouts(int $limit = 50, string $status = ''): array
{
    $rows = fb_get('REFERRAL_PAYOUTS');
    $rows = is_array($rows) ? $rows : [];
    $status = strtoupper(trim($status));
    $items = [];
    foreach ($rows as $payoutId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['payout_id'] = (string)($row['payout_id'] ?? $payoutId);
        if ($status !== '' && strtoupper(trim((string)($row['status'] ?? ''))) !== $status) {
            continue;
        }
        $items[] = $row;
    }
    usort($items, static fn(array $a, array $b): int => (int)($b['updated_at'] ?? 0) <=> (int)($a['updated_at'] ?? 0));
    return array_slice($items, 0, max(1, min(200, $limit)));
}

function referral_retry_payout(string $payoutId): array
{
    $payoutId = trim($payoutId);
    $payout = $payoutId !== '' ? fb_get('REFERRAL_PAYOUTS/' . $payoutId) : null;
    if (!is_array($payout)) {
        return ['ok' => false, 'code' => 'PAYOUT_NOT_FOUND', 'message' => 'Referral payout was not found.'];
    }
    return referral_execute_payout($payout);
}
