<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements_service.php';

function znews_settlement_cursor_encode(int $createdAt, string $id): string
{
    $json = json_encode([
        'created_at' => max(0, $createdAt),
        'id' => $id,
    ], JSON_UNESCAPED_SLASHES);

    return is_string($json)
        ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=')
        : '';
}

function znews_settlement_cursor_decode($value): array
{
    $cursor = trim((string)$value);
    if ($cursor === '') {
        return [];
    }
    if (strlen($cursor) > 512 || preg_match('/[^A-Za-z0-9_-]/', $cursor) === 1) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    $cursor .= str_repeat('=', (4 - strlen($cursor) % 4) % 4);
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $row = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    $createdAt = filter_var($row['created_at'] ?? null, FILTER_VALIDATE_INT);
    $id = trim((string)($row['id'] ?? ''));
    if ($createdAt === false || $createdAt < 0 || $id === '') {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    return [
        'created_at' => (int)$createdAt,
        'id' => znews_firebase_key($id, 'cursor_id'),
    ];
}

function znews_settlement_paginate(
    array $items,
    int $limit,
    array $cursor,
    string $idField
): array {
    usort($items, static function (array $a, array $b) use ($idField): int {
        $time = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        return $time !== 0
            ? $time
            : strcmp((string)($b[$idField] ?? ''), (string)($a[$idField] ?? ''));
    });

    $result = [];
    foreach ($items as $item) {
        $time = (int)($item['created_at'] ?? 0);
        $id = (string)($item[$idField] ?? '');
        if ($cursor) {
            $after = $time < (int)$cursor['created_at']
                || ($time === (int)$cursor['created_at']
                    && strcmp($id, (string)$cursor['id']) < 0);
            if (!$after) {
                continue;
            }
        }

        $result[] = $item;
        if (count($result) > $limit) {
            break;
        }
    }

    $hasMore = count($result) > $limit;
    if ($hasMore) {
        array_pop($result);
    }
    $next = '';
    if ($hasMore && $result) {
        $last = $result[count($result) - 1];
        $next = znews_settlement_cursor_encode(
            (int)($last['created_at'] ?? 0),
            (string)($last[$idField] ?? '')
        );
    }

    return [
        'items' => array_values($result),
        'next_cursor' => $next,
        'has_more' => $hasMore,
    ];
}

function znews_settlement_admin_queue(int $limit, array $cursor = []): array
{
    $rows = fb_get('ZNEWS_AD_IMPRESSIONS');
    $items = [];
    $scanned = 0;

    if (is_array($rows)) {
        foreach ($rows as $impressionId => $row) {
            if (++$scanned > znews_settlement_scan_limit()) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }

            $status = strtoupper(trim((string)($row['status'] ?? '')));
            $verification = strtoupper(trim((string)($row['verification_status'] ?? '')));
            $settlementStatus = strtoupper(trim((string)($row['settlement_status'] ?? 'NOT_SETTLED')));
            $revenue = max(0, (int)($row['reported_revenue_micros'] ?? 0));
            if ($status !== 'VERIFIED'
                || $verification !== 'VERIFIED'
                || !in_array($settlementStatus, ['NOT_SETTLED', 'SETTLING'], true)
                || $revenue <= 0) {
                continue;
            }

            $items[] = [
                'impression_id' => (string)($row['impression_id'] ?? $impressionId),
                'settlement_id' => trim((string)($row['settlement_id'] ?? '')),
                'post_id' => trim((string)($row['post_id'] ?? '')),
                'creator_uid' => trim((string)($row['creator_uid'] ?? '')),
                'network' => strtoupper(trim((string)($row['network'] ?? ''))),
                'currency' => strtoupper(trim((string)($row['currency'] ?? ''))),
                'reported_revenue_micros' => $revenue,
                'reported_revenue' => znews_settlement_decimal($revenue),
                'settlement_status' => $settlementStatus,
                'reconciliation_required' => !empty($row['reconciliation_required'])
                    || !empty($row['settlement_reconciliation_required']),
                'created_at' => max(0, (int)($row['received_at'] ?? $row['created_at'] ?? 0)),
                'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
            ];
        }
    }

    $page = znews_settlement_paginate($items, $limit, $cursor, 'impression_id');
    $page['scanned'] = $scanned;
    $page['scan_limit'] = znews_settlement_scan_limit();

    return $page;
}

function znews_settlement_admin_details(string $settlementId): array
{
    $settlementId = znews_firebase_key($settlementId, 'settlement_id');
    $row = fb_get(znews_settlement_path($settlementId));
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_SETTLEMENT_NOT_FOUND', 'Settlement not found.', [], 404);
    }

    $impressionId = trim((string)($row['impression_id'] ?? ''));
    $impression = $impressionId !== ''
        ? fb_get(znews_ad_impression_path($impressionId))
        : null;
    if (is_array($impression)) {
        unset($impression['payload_hash'], $impression['nonce_hash'], $impression['provider_event_hash']);
    }

    return [
        'settlement' => znews_settlement_public($row),
        'raw_settlement' => $row,
        'impression' => is_array($impression) ? $impression : [],
    ];
}

function znews_creator_balance_summary(string $uid): array
{
    $uid = znews_firebase_key($uid, 'uid');
    $rows = fb_get('ZNEWS_CREATOR_BALANCES/' . $uid);
    $balances = [];

    if (is_array($rows)) {
        foreach ($rows as $currency => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['currency'] = (string)($row['currency'] ?? $currency);
            $balances[] = znews_settlement_balance_public($row);
        }
    }

    usort($balances, static fn(array $a, array $b): int =>
        strcmp((string)$a['currency'], (string)$b['currency'])
    );

    return [
        'creator_share_percent' => 50,
        'platform_share_percent' => 50,
        'balances' => $balances,
        'main_wallet_transfer_enabled' => false,
        'minimum_transfer_threshold_enabled' => false,
    ];
}

function znews_creator_ledger(
    string $uid,
    string $currency,
    int $limit,
    array $cursor = []
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $currency = znews_ad_currency($currency);
    $rows = fb_get('ZNEWS_CREATOR_LEDGER/' . $uid . '/' . $currency);
    $items = [];

    if (is_array($rows)) {
        foreach ($rows as $entryId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['entry_id'] = (string)($row['entry_id'] ?? $entryId);
            $items[] = $row;
        }
    }

    $page = znews_settlement_paginate($items, $limit, $cursor, 'entry_id');
    $page['currency'] = $currency;

    return $page;
}
