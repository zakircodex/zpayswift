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
