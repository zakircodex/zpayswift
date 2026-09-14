<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function get_operator_private_config(string $operator): ?array
{
    $operator = normalize_operator($operator);
    $row = fb_get('OPERATOR_PRIVATE/' . $operator);

    return operator_private_config_from_row($operator, $row);
}

function get_operator_private_config_map(): array
{
    $rows = fb_get('OPERATOR_PRIVATE');
    if (!is_array($rows)) {
        return [];
    }

    $configs = [];
    foreach ($rows as $key => $row) {
        $operatorValue = is_array($row) ? ($row['operator'] ?? $key) : $key;
        $operator = normalize_operator(is_scalar($operatorValue) ? (string)$operatorValue : '');
        $config = operator_private_config_from_row($operator, $row);
        if ($config !== null) {
            $configs[$operator] = $config;
        }
    }

    return $configs;
}

function operator_private_config_from_row(string $operator, mixed $row): ?array
{
    $operator = normalize_operator($operator);

    if ($operator === '' || !is_array($row)) {
        return null;
    }

    return [
        'operator' => $operator,
        'retailer_secret_pin' => (string)($row['retailer_secret_pin'] ?? ''),
        'updated_at' => (int)($row['updated_at'] ?? 0),
    ];
}
