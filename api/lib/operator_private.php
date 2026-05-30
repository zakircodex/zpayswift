<?php
declare(strict_types=1);

function get_operator_private_config(string $operator): ?array
{
    $operator = normalize_operator($operator);
    $row = fb_get('OPERATOR_PRIVATE/' . $operator);

    if (!is_array($row)) {
        return null;
    }

    return [
        'operator' => $operator,
        'retailer_secret_pin' => (string)($row['retailer_secret_pin'] ?? ''),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}