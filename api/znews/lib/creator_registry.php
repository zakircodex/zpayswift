<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_creator_registry_path(string $uid): string
{
    return 'ZNEWS_CREATORS/' . znews_firebase_key($uid, 'uid');
}

function znews_creator_status_index_path(string $status, string $uid): string
{
    $status = strtoupper(trim($status));
    if (!in_array($status, ['ACTIVE', 'BLOCKED'], true)) {
        $status = 'ACTIVE';
    }

    return 'ZNEWS_CREATORS_BY_STATUS/' . $status . '/' . znews_firebase_key($uid, 'uid');
}

function znews_creator_normalize_status($value): string
{
    $status = strtoupper(trim((string)$value));
    return in_array($status, ['ACTIVE', 'BLOCKED'], true) ? $status : 'ACTIVE';
}

function znews_creator_mask_account(array $user): string
{
    $account = trim((string)(
        $user['phone']
        ?? $user['PHONE']
        ?? $user['account_number']
        ?? $user['mobile']
        ?? ''
    ));
    if ($account === '') {
        return '';
    }

    $length = strlen($account);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    $visibleStart = min(2, $length - 2);
    $visibleEnd = min(3, $length - $visibleStart);
    return substr($account, 0, $visibleStart)
        . str_repeat('*', max(2, $length - $visibleStart - $visibleEnd))
        . substr($account, -$visibleEnd);
}

function znews_creator_country_snapshot(array $user): string
{
    $country = strtoupper(trim((string)(
        $user['pricing_country']
        ?? $user['market_country']
        ?? $user['service_country']
        ?? $user['country_code']
        ?? $user['country']
        ?? ''
    )));
    $map = [
        'BD' => 'BD',
        'BGD' => 'BD',
        'BANGLADESH' => 'BD',
        'MY' => 'MY',
        'MYS' => 'MY',
        'MALAYSIA' => 'MY',
    ];
    return $map[$country] ?? '';
}

function znews_creator_currency_snapshot(array $user): string
{
    $currency = strtoupper(trim((string)(
        $user['wallet_currency']
        ?? $user['currency']
        ?? $user['account_currency']
        ?? ''
    )));
    if (in_array($currency, ['BDT', 'MYR'], true)) {
        return $currency;
    }

    return znews_creator_country_snapshot($user) === 'MY' ? 'MYR' : 'BDT';
}

function znews_creator_public_registry(array $row): array
{
    return [
        'creator_uid' => trim((string)($row['creator_uid'] ?? '')),
        'zpay_uid' => trim((string)($row['zpay_uid'] ?? '')),
        'zpay_account_masked' => trim((string)($row['zpay_account_masked'] ?? '')),
        'name' => trim((string)($row['name'] ?? 'Z-Pay creator')),
        'profile_photo_url' => trim((string)($row['profile_photo_url'] ?? '')),
        'status' => znews_creator_normalize_status($row['status'] ?? 'ACTIVE'),
        'payout_eligible' => znews_creator_normalize_status($row['status'] ?? 'ACTIVE') === 'ACTIVE',
        'account_country_snapshot' => trim((string)($row['account_country_snapshot'] ?? '')),
        'wallet_currency_snapshot' => trim((string)($row['wallet_currency_snapshot'] ?? '')),
        'created_at' => max(0, (int)($row['created_at'] ?? 0)),
        'last_seen_at' => max(0, (int)($row['last_seen_at'] ?? 0)),
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
        'blocked_at' => max(0, (int)($row['blocked_at'] ?? 0)),
        'block_reason' => trim((string)($row['block_reason'] ?? '')),
    ];
}

function znews_creator_sync_status_index(string $uid, string $status, int $updatedAt): bool
{
    $uid = znews_firebase_key($uid, 'uid');
    $status = znews_creator_normalize_status($status);
    $other = $status === 'ACTIVE' ? 'BLOCKED' : 'ACTIVE';

    $write = fb_put(znews_creator_status_index_path($status, $uid), [
        'creator_uid' => $uid,
        'status' => $status,
        'updated_at' => $updatedAt,
    ]);
    @fb_delete(znews_creator_status_index_path($other, $uid));

    return $write;
}

function znews_creator_registry_touch(array $auth): array
{
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $snapshot = znews_public_creator_snapshot($user);
    $path = znews_creator_registry_path($uid);
    $now = znews_now();

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $read = fb_get_with_etag($path);
        if (empty($read['ok']) || !is_string($read['etag'] ?? null)) {
            api_response(false, 'ZNEWS_CREATOR_REGISTRY_READ_FAILED', 'Creator access could not be verified.', [], 503);
        }

        $existing = is_array($read['value'] ?? null) ? (array)$read['value'] : [];
        $status = znews_creator_normalize_status($existing['status'] ?? 'ACTIVE');
        $row = array_merge($existing, [
            'schema_version' => 1,
            'creator_uid' => $uid,
            'zpay_uid' => $uid,
            'zpay_account_masked' => znews_creator_mask_account($user),
            'name' => trim((string)($snapshot['name'] ?? 'Z-Pay creator')),
            'profile_photo_url' => trim((string)($snapshot['profile_photo_url'] ?? '')),
            'role_snapshot' => strtoupper(trim((string)($user['role'] ?? 'USER'))),
            'zpay_status_snapshot' => strtoupper(trim((string)($user['status'] ?? 'ACTIVE'))),
            'account_country_snapshot' => znews_creator_country_snapshot($user),
            'wallet_currency_snapshot' => znews_creator_currency_snapshot($user),
            'status' => $status,
            'payout_eligible' => $status === 'ACTIVE',
            'created_at' => max(1, (int)($existing['created_at'] ?? $now)),
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);

        $write = fb_put_if_match($path, $row, (string)$read['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            api_response(false, 'ZNEWS_CREATOR_REGISTRY_WRITE_FAILED', 'Creator access could not be verified.', [], 503);
        }

        if (!znews_creator_sync_status_index($uid, $status, $now)) {
            @fb_patch($path, [
                'index_reconciliation_required' => true,
                'updated_at' => $now,
            ]);
        }

        return $row;
    }

    api_response(false, 'ZNEWS_CREATOR_REGISTRY_BUSY', 'Creator access is busy. Please retry.', [], 409);
}

function znews_creator_registry_require_active(array $auth): array
{
    $row = znews_creator_registry_touch($auth);
    if (znews_creator_normalize_status($row['status'] ?? 'ACTIVE') !== 'ACTIVE') {
        api_response(false, 'ZNEWS_CREATOR_BLOCKED', 'This creator account is blocked from Z Sky 24 creator actions.', [
            'creator_status' => 'BLOCKED',
        ], 403);
    }

    return $row;
}

function znews_creator_registry_set_status(
    string $uid,
    string $status,
    string $reason,
    string $adminUid
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $status = znews_creator_normalize_status($status);
    $reason = substr(znews_normalize_text($reason), 0, 300);
    $path = znews_creator_registry_path($uid);
    $now = znews_now();

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $read = fb_get_with_etag($path);
        if (empty($read['ok']) || !is_string($read['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_CREATOR_REGISTRY_READ_FAILED'];
        }
        if (!is_array($read['value'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_CREATOR_NOT_FOUND', 'http_status' => 404];
        }

        $row = (array)$read['value'];
        $row['status'] = $status;
        $row['payout_eligible'] = $status === 'ACTIVE';
        $row['updated_at'] = $now;
        $row['status_updated_at'] = $now;
        $row['status_updated_by'] = trim($adminUid);
        if ($status === 'BLOCKED') {
            $row['blocked_at'] = $now;
            $row['block_reason'] = $reason;
        } else {
            $row['activated_at'] = $now;
            $row['block_reason'] = '';
        }

        $write = fb_put_if_match($path, $row, (string)$read['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_CREATOR_STATUS_WRITE_FAILED'];
        }

        $indexOk = znews_creator_sync_status_index($uid, $status, $now);
        if (!$indexOk) {
            @fb_patch($path, [
                'index_reconciliation_required' => true,
                'updated_at' => $now,
            ]);
        }

        return [
            'ok' => true,
            'creator' => znews_creator_public_registry($row),
            'index_reconciliation_required' => !$indexOk,
        ];
    }

    return ['ok' => false, 'code' => 'ZNEWS_CREATOR_STATUS_BUSY', 'http_status' => 409];
}

function znews_creator_registry_list(string $status, int $limit = 50): array
{
    $status = znews_creator_normalize_status($status);
    $limit = max(1, min(100, $limit));
    $index = fb_get('ZNEWS_CREATORS_BY_STATUS/' . $status);
    $items = [];

    if (is_array($index)) {
        foreach ($index as $uid => $entry) {
            if (count($items) >= $limit) {
                break;
            }
            if (!is_array($entry)) {
                continue;
            }
            $row = fb_get(znews_creator_registry_path((string)$uid));
            if (!is_array($row)
                || znews_creator_normalize_status($row['status'] ?? 'ACTIVE') !== $status) {
                continue;
            }
            $items[] = znews_creator_public_registry($row);
        }
    }

    usort($items, static fn(array $a, array $b): int =>
        ((int)($b['last_seen_at'] ?? 0)) <=> ((int)($a['last_seen_at'] ?? 0))
    );

    return [
        'status' => $status,
        'items' => array_values($items),
        'count' => count($items),
        'limit' => $limit,
    ];
}
