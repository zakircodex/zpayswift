<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_creator_payout_batch_limit(): int
{
    $value = defined('ZNEWS_CREATOR_PAYOUT_BATCH_LIMIT')
        ? (int)constant('ZNEWS_CREATOR_PAYOUT_BATCH_LIMIT')
        : 5;
    return max(1, min(5, $value));
}

function znews_creator_live_account_status(array $user): string
{
    $status = strtoupper(trim((string)($user['status'] ?? $user['STATUS'] ?? '')));
    return $status === '' ? 'UNKNOWN' : $status;
}

function znews_creator_live_wallet_currency(array $user, array $wallet): string
{
    if (function_exists('wallet_account_currency')) {
        $resolved = strtoupper(trim((string)wallet_account_currency($user, $wallet)));
        if (in_array($resolved, ['BDT', 'MYR'], true)) {
            return $resolved;
        }
    }

    foreach ([
        $wallet['currency'] ?? null,
        $wallet['wallet_currency'] ?? null,
        $user['wallet_currency'] ?? null,
        $user['currency'] ?? null,
        $user['account_currency'] ?? null,
    ] as $candidate) {
        $candidate = strtoupper(trim((string)$candidate));
        if (in_array($candidate, ['BDT', 'MYR'], true)) {
            return $candidate;
        }
    }

    return znews_creator_country_snapshot($user) === 'MY' ? 'MYR' : 'BDT';
}

function znews_creator_payout_validate_one(string $uid): array
{
    $uid = znews_firebase_key($uid, 'creator_uid');
    $creator = fb_get(znews_creator_registry_path($uid));
    if (!is_array($creator)) {
        return [
            'ok' => false,
            'creator_uid' => $uid,
            'code' => 'ZNEWS_CREATOR_NOT_REGISTERED',
            'message' => 'Creator is not registered in Z Sky 24.',
        ];
    }

    $creatorStatus = znews_creator_normalize_status($creator['status'] ?? 'ACTIVE');
    if ($creatorStatus !== 'ACTIVE') {
        return [
            'ok' => false,
            'creator_uid' => $uid,
            'code' => 'ZNEWS_CREATOR_NOT_ACTIVE',
            'message' => 'Blocked creators cannot receive a payout.',
            'creator_status' => $creatorStatus,
        ];
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        return [
            'ok' => false,
            'creator_uid' => $uid,
            'code' => 'ZNEWS_ZPAY_ACCOUNT_NOT_FOUND',
            'message' => 'The linked Z-Pay account was not found.',
        ];
    }

    $accountStatus = znews_creator_live_account_status($user);
    if ($accountStatus !== 'ACTIVE') {
        return [
            'ok' => false,
            'creator_uid' => $uid,
            'code' => 'ZNEWS_ZPAY_ACCOUNT_NOT_ACTIVE',
            'message' => 'Only active Z-Pay accounts can receive a payout.',
            'zpay_status' => $accountStatus,
        ];
    }

    $role = strtoupper(trim((string)($user['role'] ?? '')));
    if (!in_array($role, ['USER', 'RETAILER'], true)) {
        return [
            'ok' => false,
            'creator_uid' => $uid,
            'code' => 'ZNEWS_ZPAY_ROLE_NOT_ELIGIBLE',
            'message' => 'This Z-Pay account role cannot receive creator revenue.',
            'zpay_role' => $role,
        ];
    }

    $wallet = fb_get('USER_WALLETS/' . $uid);
    $wallet = is_array($wallet) ? $wallet : [];
    $currency = znews_creator_live_wallet_currency($user, $wallet);
    if (!in_array($currency, ['BDT', 'MYR'], true)) {
        return [
            'ok' => false,
            'creator_uid' => $uid,
            'code' => 'ZNEWS_PAYOUT_CURRENCY_UNSUPPORTED',
            'message' => 'The creator payout currency is not supported.',
        ];
    }

    return [
        'ok' => true,
        'creator_uid' => $uid,
        'zpay_uid' => $uid,
        'name' => trim((string)($user['name'] ?? $creator['name'] ?? 'Z-Pay creator')),
        'zpay_account_masked' => znews_creator_mask_account($user),
        'creator_status' => $creatorStatus,
        'zpay_status' => $accountStatus,
        'wallet_currency' => $currency,
        'account_country' => znews_creator_country_snapshot($user),
    ];
}

function znews_creator_payout_batch_preflight(array $creatorUids): array
{
    $limit = znews_creator_payout_batch_limit();
    $normalized = [];
    foreach ($creatorUids as $uid) {
        $uid = trim((string)$uid);
        if ($uid === '') {
            continue;
        }
        $normalized[$uid] = $uid;
    }
    $uids = array_values($normalized);

    if (!$uids) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_PAYOUT_BATCH_EMPTY',
            'message' => 'Select at least one creator.',
            'http_status' => 422,
            'batch_limit' => $limit,
        ];
    }
    if (count($uids) > $limit) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_PAYOUT_BATCH_LIMIT_EXCEEDED',
            'message' => 'A creator payout batch can contain no more than five creators.',
            'http_status' => 422,
            'batch_limit' => $limit,
            'requested_count' => count($uids),
        ];
    }

    $eligible = [];
    $rejected = [];
    foreach ($uids as $uid) {
        $result = znews_creator_payout_validate_one($uid);
        if (!empty($result['ok'])) {
            $eligible[] = $result;
        } else {
            $rejected[] = $result;
        }
    }

    if ($rejected) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_PAYOUT_BATCH_NOT_ELIGIBLE',
            'message' => 'One or more creators are not eligible for payout.',
            'http_status' => 422,
            'batch_limit' => $limit,
            'eligible' => $eligible,
            'rejected' => $rejected,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ZNEWS_PAYOUT_BATCH_READY',
        'message' => 'Creator payout batch passed preflight.',
        'batch_limit' => $limit,
        'count' => count($eligible),
        'creators' => $eligible,
        'currency_counts' => [
            'BDT' => count(array_filter($eligible, static fn(array $row): bool => ($row['wallet_currency'] ?? '') === 'BDT')),
            'MYR' => count(array_filter($eligible, static fn(array $row): bool => ($row['wallet_currency'] ?? '') === 'MYR')),
        ],
    ];
}
