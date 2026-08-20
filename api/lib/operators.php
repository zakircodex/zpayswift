<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function get_operator_runtime(string $operator): ?array
{
    $op = normalize_operator($operator);
    $row = fb_get('OPERATOR_RUNTIME/' . $op);

    if (!is_array($row)) {
        return null;
    }

    $row['code'] = $op;
    return $row;
}

function require_active_operator(string $operator): array
{
    $runtime = get_operator_runtime($operator);

    if (!$runtime) {
        api_response(false, 'INVALID_OPERATOR', 'Operator config not found', [
            'operator' => normalize_operator($operator),
        ], 422);
    }

    if (!(bool)($runtime['active'] ?? false)) {
        api_response(false, 'TOPUP_DISABLED', 'This operator is currently inactive', [
            'operator' => normalize_operator($operator),
        ], 422);
    }

    return $runtime;
}

function operator_config_catalog_row(array $config, string $operator): ?array
{
    $operator = normalize_operator($operator);
    foreach ((array)($config['countries'] ?? []) as $country) {
        foreach ((array)($country['operators'] ?? []) as $row) {
            if (is_array($row) && normalize_operator($row['code'] ?? '') === $operator) {
                return $row;
            }
        }
    }

    return null;
}

function operator_config_atomic_writes(
    string $operator,
    array $config,
    array $runtime,
    array $private
): array {
    $operator = normalize_operator($operator);
    if (!is_valid_operator($operator)) {
        return [];
    }

    $catalog = operator_config_catalog_row($config, $operator);
    if (!is_array($catalog) || normalize_operator($runtime['code'] ?? '') !== $operator) {
        return [];
    }

    foreach ([
        'name',
        'country_code',
        'service_type',
        'active',
        'min_amount',
        'max_amount',
        'quick_amounts',
        'prefixes',
        'sort_order',
    ] as $field) {
        if (!array_key_exists($field, $catalog) || !array_key_exists($field, $runtime) || $catalog[$field] !== $runtime[$field]) {
            return [];
        }
    }

    $revision = (int)($config['updated_at'] ?? 0);
    if (
        $revision <= 0
        || (int)($runtime['updated_at'] ?? 0) !== $revision
        || (int)($private['updated_at'] ?? 0) !== $revision
        || !array_key_exists('retailer_secret_pin', $private)
    ) {
        return [];
    }

    return [
        'TOPUP_CONFIG' => $config,
        'OPERATOR_RUNTIME/' . $operator => $runtime,
        'OPERATOR_PRIVATE/' . $operator => $private,
    ];
}

function operator_config_save_atomic(
    string $operator,
    array $config,
    array $runtime,
    array $private
): bool {
    $writes = operator_config_atomic_writes($operator, $config, $runtime, $private);
    return $writes !== [] && fb_patch('', $writes);
}
