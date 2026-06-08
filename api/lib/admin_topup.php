<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function admin_topup_read_bucket(string $bucket): array
{
    $bucket = strtoupper(trim($bucket));

    $allowed = ['PENDING', 'CLAIMED', 'PROCESSING', 'DONE'];
    if (!in_array($bucket, $allowed, true)) {
        return [];
    }

    $items = fb_get('TOPUP_REQUESTS/' . $bucket);
    if (!is_array($items)) {
        return [];
    }

    $list = [];
    foreach ($items as $requestId => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row['request_id'] = (string)($row['request_id'] ?? $requestId);
        $row['_bucket'] = $bucket;
        $list[] = $row;
    }

    usort($list, static function (array $a, array $b): int {
        $aTs = (int)($a['updated_at'] ?? $a['created_at'] ?? 0);
        $bTs = (int)($b['updated_at'] ?? $b['created_at'] ?? 0);
        return $bTs <=> $aTs;
    });

    return $list;
}

function admin_topup_find_request(string $requestId): ?array
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

function admin_topup_attach_status(array $row): array
{
    $requestId = (string)($row['request_id'] ?? '');
    if ($requestId === '') {
        $row['request_status'] = null;
        return $row;
    }

    $status = fb_get('REQUEST_STATUS/' . $requestId);
    $row['request_status'] = is_array($status) ? $status : null;

    return $row;
}

function admin_topup_apply_filters(array $items, array $filters): array
{
    $requestId = trim((string)($filters['request_id'] ?? ''));
    $uid = trim((string)($filters['uid'] ?? ''));
    $operator = normalize_operator($filters['operator'] ?? '');
    $number = normalize_bd_topup_number($filters['topup_number'] ?? '');
    $deviceId = trim((string)($filters['assigned_device_id'] ?? ''));

    return array_values(array_filter($items, static function (array $row) use ($requestId, $uid, $operator, $number, $deviceId): bool {
        if ($requestId !== '' && (string)($row['request_id'] ?? '') !== $requestId) {
            return false;
        }

        if ($uid !== '' && (string)($row['uid'] ?? '') !== $uid) {
            return false;
        }

        if ($operator !== '' && normalize_operator($row['operator'] ?? '') !== $operator) {
            return false;
        }

        if ($number !== '' && normalize_bd_topup_number($row['topup_number'] ?? '') !== $number) {
            return false;
        }

        if ($deviceId !== '' && (string)($row['assigned_device_id'] ?? '') !== $deviceId) {
            return false;
        }

        return true;
    }));
}

function admin_topup_paginate(array $items, int $page, int $limit): array
{
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));

    $total = count($items);
    $offset = ($page - 1) * $limit;

    return [
        'items' => array_slice($items, $offset, $limit),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ],
    ];
}
