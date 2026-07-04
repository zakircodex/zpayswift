<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/operators.php';
require_once dirname(__DIR__, 2) . '/lib/operator_private.php';
require_once dirname(__DIR__, 2) . '/lib/topup_config.php';

api_require_method('GET');
auth_require_admin_session();

$operator = normalize_operator($_GET['operator'] ?? '');
if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Invalid operator', [], 422);
}

$config = topup_config();
$operatorRow = null;
$countryRow = null;

foreach ((array)($config['countries'] ?? []) as $country) {
    foreach ((array)($country['operators'] ?? []) as $row) {
        if (normalize_operator($row['code'] ?? '') === $operator) {
            $operatorRow = $row;
            $countryRow = $country;
            break 2;
        }
    }
}

if (!is_array($operatorRow) || !is_array($countryRow)) {
    api_response(false, 'INVALID_OPERATOR', 'Operator is not supported', [], 422);
}

$runtime = get_operator_runtime($operator) ?: [];
$private = get_operator_private_config($operator) ?: [];
$pinSet = trim((string)($private['retailer_secret_pin'] ?? '')) !== '';

api_response(true, 'SUCCESS', 'Operator loaded', [
    'operator' => $operator,
    'code' => $operator,
    'name' => (string)($operatorRow['name'] ?? $operator),
    'country_code' => (string)($operatorRow['country_code'] ?? $countryRow['code'] ?? ''),
    'country_name' => (string)($countryRow['name'] ?? ''),
    'service_type' => (string)($operatorRow['service_type'] ?? 'PREPAID'),
    'active' => (bool)($operatorRow['active'] ?? false),
    'min_amount' => (float)($operatorRow['min_amount'] ?? 20),
    'max_amount' => (float)($operatorRow['max_amount'] ?? 1000),
    'quick_amounts' => array_values((array)($operatorRow['quick_amounts'] ?? [])),
    'prefixes' => array_values((array)($operatorRow['prefixes'] ?? [])),
    'sort_order' => (int)($operatorRow['sort_order'] ?? 999),
    'execution_ready' => topup_operator_ready_for_submit((string)($countryRow['code'] ?? ''), $operator),
    'dial_template' => (string)($runtime['dial_template'] ?? ''),
    'masked_template' => (string)($runtime['masked_template'] ?? ''),
    'requires_secret_pin' => (bool)($runtime['requires_secret_pin'] ?? true),
    'retailer_secret_pin_set' => $pinSet,
    'retailer_secret_pin_masked' => $pinSet ? '********' : '',
    'updated_at' => (int)max((int)($runtime['updated_at'] ?? 0), (int)($private['updated_at'] ?? 0)),
]);
