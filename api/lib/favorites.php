<?php
declare(strict_types=1);

require_once __DIR__ . '/firebase.php';
require_once __DIR__ . '/helpers.php';

const FAVORITE_NUMBERS_LIMIT = 10;

function favorite_numbers_path(string $uid): string
{
    return 'USER_FAVORITE_NUMBERS/' . $uid;
}

function favorite_clean_name($value): string
{
    return trim((string)$value);
}

function favorite_clean_country($value): string
{
    $country = strtoupper(trim((string)$value));
    return $country === '' ? 'BD' : $country;
}

function favorite_clean_country_code($value): string
{
    $code = strtoupper(trim((string)$value));
    if ($code === '') {
        return 'BD';
    }
    return $code;
}

function favorite_normalize_number(string $country, $number): string
{
    $raw = trim((string)$number);
    if ($raw === '' || strpos($raw, '*') !== false) {
        return '';
    }

    if (strtoupper($country) === 'BD') {
        return normalize_bd_topup_number($raw);
    }

    return digits_only($raw);
}

function favorite_clean_operator($value): string
{
    return normalize_operator((string)$value);
}

function favorite_clean_operator_name($value, string $operator): string
{
    $name = trim((string)$value);
    return $name === '' ? strtoupper($operator) : $name;
}

function favorite_create_id(): string
{
    return 'FAV_' . date('YmdHis') . '_' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function favorite_sort_rows(array $rows): array
{
    uasort($rows, static function ($a, $b): int {
        $left = (int)($a['created_at'] ?? 0);
        $right = (int)($b['created_at'] ?? 0);
        if ($left === $right) {
            return strcmp((string)($a['favorite_id'] ?? ''), (string)($b['favorite_id'] ?? ''));
        }
        return $left <=> $right;
    });
    return $rows;
}

function favorite_public_row(string $favoriteId, array $row): array
{
    return [
        'favorite_id' => $favoriteId,
        'name' => (string)($row['name'] ?? ''),
        'number' => (string)($row['number'] ?? ''),
        'country' => (string)($row['country'] ?? 'BD'),
        'country_code' => (string)($row['country_code'] ?? ($row['country'] ?? 'BD')),
        'operator' => (string)($row['operator'] ?? ''),
        'operator_name' => (string)($row['operator_name'] ?? ($row['operator'] ?? '')),
        'service_type' => (string)($row['service_type'] ?? 'topup'),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}

function favorite_active_rows(array $node): array
{
    $rows = [];
    foreach ($node as $favoriteId => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['status'] ?? 'active') !== 'active') {
            continue;
        }
        $id = (string)($row['favorite_id'] ?? $favoriteId);
        if ($id === '') {
            continue;
        }
        $rows[$id] = favorite_public_row($id, $row);
    }

    return favorite_sort_rows($rows);
}

function favorite_rows_list(array $node): array
{
    return array_values(favorite_active_rows($node));
}

function favorite_duplicate_exists(array $rows, string $country, string $number, ?string $ignoreFavoriteId = null): bool
{
    foreach ($rows as $favoriteId => $row) {
        if ($ignoreFavoriteId !== null && $favoriteId === $ignoreFavoriteId) {
            continue;
        }
        if (strtoupper((string)($row['country'] ?? 'BD')) === strtoupper($country)
            && (string)($row['number'] ?? '') === $number) {
            return true;
        }
    }

    return false;
}

function favorite_validate_create_payload(array $body): array
{
    $name = favorite_clean_name($body['name'] ?? '');
    $country = favorite_clean_country($body['country'] ?? 'BD');
    $countryCode = favorite_clean_country_code($body['country_code'] ?? $country);
    $number = favorite_normalize_number($country, $body['number'] ?? '');
    $operator = favorite_clean_operator($body['operator'] ?? '');
    $operatorName = favorite_clean_operator_name($body['operator_name'] ?? '', $operator);
    $serviceType = trim((string)($body['service_type'] ?? 'topup'));

    if ($name === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Favorite name is required.', 'http_status' => 422];
    }

    if ($number === '' || ($country === 'BD' && !is_valid_bd_topup_number($number))) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Valid mobile number is required.', 'http_status' => 422];
    }

    if ($operator === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Operator is required.', 'http_status' => 422];
    }

    return [
        'ok' => true,
        'favorite' => [
            'name' => $name,
            'number' => $number,
            'country' => $country,
            'country_code' => $countryCode,
            'operator' => $operator,
            'operator_name' => $operatorName,
            'service_type' => $serviceType === '' ? 'topup' : $serviceType,
        ],
    ];
}

function favorite_update_user_node(string $uid, callable $mutator): array
{
    $path = favorite_numbers_path($uid);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $current = fb_get_with_etag($path);
        $status = (int)($current['status'] ?? 0);
        if (empty($current['ok']) && $status !== 404) {
            return [
                'ok' => false,
                'code' => 'FAVORITE_STORAGE_UNAVAILABLE',
                'message' => 'Favorite number could not be updated. Please try again.',
                'http_status' => 503,
            ];
        }

        $etag = (string)($current['etag'] ?? '');
        $node = is_array($current['value'] ?? null) ? $current['value'] : [];
        $mutation = $mutator($node);
        if (empty($mutation['ok'])) {
            return $mutation;
        }

        $nextNode = is_array($mutation['node'] ?? null) ? $mutation['node'] : [];
        $write = fb_put_if_match($path, $nextNode, $etag);
        $writeStatus = (int)($write['status'] ?? 0);

        if (!empty($write['ok'])) {
            $data = is_array($mutation['data'] ?? null) ? $mutation['data'] : [];
            return ['ok' => true, 'node' => $nextNode, 'data' => $data];
        }

        if ($writeStatus === 412) {
            continue;
        }

        return [
            'ok' => false,
            'code' => 'FAVORITE_STORAGE_UNAVAILABLE',
            'message' => 'Favorite number could not be updated. Please try again.',
            'http_status' => 503,
        ];
    }

    return [
        'ok' => false,
        'code' => 'FAVORITE_CONFLICT',
        'message' => 'Favorite number could not be updated. Please try again.',
        'http_status' => 409,
    ];
}

function favorite_create_for_user(string $uid, array $body): array
{
    $validated = favorite_validate_create_payload($body);
    if (empty($validated['ok'])) {
        return $validated;
    }

    $favorite = $validated['favorite'];

    return favorite_update_user_node($uid, static function (array $node) use ($favorite): array {
        $rows = favorite_active_rows($node);
        if (favorite_duplicate_exists($rows, (string)$favorite['country'], (string)$favorite['number'])) {
            return [
                'ok' => false,
                'code' => 'FAVORITE_ALREADY_EXISTS',
                'message' => 'This number is already in your favorites.',
                'http_status' => 409,
            ];
        }

        if (count($rows) >= FAVORITE_NUMBERS_LIMIT) {
            return [
                'ok' => false,
                'code' => 'FAVORITE_LIMIT_REACHED',
                'message' => 'You can save up to 10 favorite numbers.',
                'http_status' => 409,
            ];
        }

        $now = now_ts();
        $favoriteId = favorite_create_id();
        while (isset($node[$favoriteId])) {
            $favoriteId = favorite_create_id();
        }

        $node[$favoriteId] = array_merge($favorite, [
            'favorite_id' => $favoriteId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'ok' => true,
            'node' => $node,
            'data' => [
                'favorite' => favorite_public_row($favoriteId, $node[$favoriteId]),
                'favorites' => favorite_rows_list($node),
                'count' => count(favorite_active_rows($node)),
                'limit' => FAVORITE_NUMBERS_LIMIT,
            ],
        ];
    });
}

function favorite_update_for_user(string $uid, array $body): array
{
    $favoriteId = trim((string)($body['favorite_id'] ?? ''));
    $name = favorite_clean_name($body['name'] ?? '');

    if ($favoriteId === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Favorite ID is required.', 'http_status' => 422];
    }

    if ($name === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Favorite name is required.', 'http_status' => 422];
    }

    return favorite_update_user_node($uid, static function (array $node) use ($favoriteId, $name): array {
        $rows = favorite_active_rows($node);
        if (!isset($rows[$favoriteId])) {
            return [
                'ok' => false,
                'code' => 'FAVORITE_NOT_FOUND',
                'message' => 'Favorite number was not found.',
                'http_status' => 404,
            ];
        }

        $node[$favoriteId]['name'] = $name;
        $node[$favoriteId]['updated_at'] = now_ts();
        $node[$favoriteId]['status'] = 'active';

        return [
            'ok' => true,
            'node' => $node,
            'data' => [
                'favorite' => favorite_public_row($favoriteId, $node[$favoriteId]),
                'favorites' => favorite_rows_list($node),
                'count' => count(favorite_active_rows($node)),
                'limit' => FAVORITE_NUMBERS_LIMIT,
            ],
        ];
    });
}

function favorite_delete_for_user(string $uid, array $body): array
{
    $favoriteId = trim((string)($body['favorite_id'] ?? ''));

    if ($favoriteId === '') {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Favorite ID is required.', 'http_status' => 422];
    }

    return favorite_update_user_node($uid, static function (array $node) use ($favoriteId): array {
        $rows = favorite_active_rows($node);
        if (!isset($rows[$favoriteId])) {
            return [
                'ok' => false,
                'code' => 'FAVORITE_NOT_FOUND',
                'message' => 'Favorite number was not found.',
                'http_status' => 404,
            ];
        }

        unset($node[$favoriteId]);

        return [
            'ok' => true,
            'node' => $node,
            'data' => [
                'favorites' => favorite_rows_list($node),
                'count' => count(favorite_active_rows($node)),
                'limit' => FAVORITE_NUMBERS_LIMIT,
            ],
        ];
    });
}
