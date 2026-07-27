<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_service.php';

function znews_transfer_cursor_encode(int $createdAt, string $requestId): string
{
    $json = json_encode([
        'created_at' => max(0, $createdAt),
        'request_id' => $requestId,
    ], JSON_UNESCAPED_SLASHES);
    return is_string($json)
        ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=')
        : '';
}

function znews_transfer_cursor_decode($value): array
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
    $requestId = trim((string)($row['request_id'] ?? ''));
    if ($createdAt === false || $createdAt < 0 || $requestId === '') {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }
    return [
        'created_at' => (int)$createdAt,
        'request_id' => znews_firebase_key($requestId, 'request_id'),
    ];
}

function znews_transfer_paginate(array $items, int $limit, array $cursor = []): array
{
    usort($items, static function (array $a, array $b): int {
        $time = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        return $time !== 0
            ? $time
            : strcmp((string)($b['request_id'] ?? ''), (string)($a['request_id'] ?? ''));
    });

    $result = [];
    foreach ($items as $item) {
        $time = (int)($item['created_at'] ?? 0);
        $id = (string)($item['request_id'] ?? '');
        if ($cursor) {
            $after = $time < (int)$cursor['created_at']
                || ($time === (int)$cursor['created_at']
                    && strcmp($id, (string)$cursor['request_id']) < 0);
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
        $next = znews_transfer_cursor_encode(
            (int)($last['created_at'] ?? 0),
            (string)($last['request_id'] ?? '')
        );
    }

    return [
        'items' => array_values($result),
        'next_cursor' => $next,
        'has_more' => $hasMore,
    ];
}

function znews_transfer_owner_request(string $uid, string $requestId): array
{
    $uid = znews_firebase_key($uid, 'uid');
    $requestId = znews_firebase_key($requestId, 'request_id');
    $row = fb_get(znews_transfer_request_path($requestId));
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_TRANSFER_NOT_FOUND', 'Transfer request not found.', [], 404);
    }
    $owner = trim((string)($row['uid'] ?? ''));
    if ($owner === '' || !hash_equals($owner, $uid)) {
        api_response(false, 'ZNEWS_TRANSFER_NOT_FOUND', 'Transfer request not found.', [], 404);
    }
    return $row;
}

function znews_transfer_user_list(
    string $uid,
    int $limit,
    array $cursor = []
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $rows = fb_get('ZNEWS_USER_TRANSFER_REQUESTS/' . $uid);
    $items = [];
    if (is_array($rows)) {
        foreach ($rows as $requestId => $index) {
            if (!is_array($index)) {
                continue;
            }
            $row = fb_get(znews_transfer_request_path((string)$requestId));
            if (!is_array($row)) {
                continue;
            }
            $owner = trim((string)($row['uid'] ?? ''));
            if ($owner === '' || !hash_equals($owner, $uid)) {
                continue;
            }
            $items[] = znews_transfer_public($row);
        }
    }
    return znews_transfer_paginate($items, $limit, $cursor);
}

function znews_transfer_admin_queue(int $limit, array $cursor = []): array
{
    $rows = fb_get('ZNEWS_TRANSFER_REVIEW_QUEUE');
    $items = [];
    $scanned = 0;
    if (is_array($rows)) {
        foreach ($rows as $requestId => $index) {
            if (++$scanned > znews_transfer_scan_limit()) {
                break;
            }
            if (!is_array($index)) {
                continue;
            }
            $row = fb_get(znews_transfer_request_path((string)$requestId));
            if (!is_array($row)) {
                continue;
            }
            $status = strtoupper(trim((string)($row['status'] ?? '')));
            if (!in_array($status, ['PENDING', 'APPROVING', 'REJECTING', 'RECONCILIATION_REQUIRED'], true)) {
                continue;
            }
            $items[] = znews_transfer_public($row);
        }
    }
    $page = znews_transfer_paginate($items, $limit, $cursor);
    $page['scanned'] = $scanned;
    $page['scan_limit'] = znews_transfer_scan_limit();
    return $page;
}

function znews_transfer_admin_details(string $requestId): array
{
    $requestId = znews_firebase_key($requestId, 'request_id');
    $row = fb_get(znews_transfer_request_path($requestId));
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_TRANSFER_NOT_FOUND', 'Transfer request not found.', [], 404);
    }
    $uid = trim((string)($row['uid'] ?? ''));
    $user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
    $wallet = $uid !== '' ? fb_get('USER_WALLETS/' . $uid) : null;
    $sourceBalance = ($uid !== '' && trim((string)($row['source_currency'] ?? '')) !== '')
        ? fb_get(znews_settlement_creator_balance_path($uid, (string)$row['source_currency']))
        : null;
    return [
        'request' => znews_transfer_public($row),
        'raw_request' => $row,
        'user' => is_array($user) ? [
            'uid' => $uid,
            'name' => trim((string)($user['name'] ?? '')),
            'phone' => trim((string)($user['phone'] ?? '')),
            'status' => strtoupper(trim((string)($user['status'] ?? ''))),
            'role' => strtoupper(trim((string)($user['role'] ?? ''))),
        ] : [],
        'wallet' => is_array($wallet) ? [
            'currency' => wallet_account_currency(is_array($user) ? $user : [], $wallet),
            'available_balance' => wallet_round_money((float)($wallet['available_balance'] ?? 0)),
            'hold_balance' => wallet_round_money((float)($wallet['hold_balance'] ?? 0)),
        ] : [],
        'source_balance' => is_array($sourceBalance)
            ? znews_settlement_balance_public($sourceBalance)
            : [],
    ];
}
