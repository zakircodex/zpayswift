<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/operators.php';
require_once dirname(__DIR__, 2) . '/lib/operator_private.php';
require_once dirname(__DIR__, 2) . '/lib/topup_config.php';

api_require_method('GET');
auth_require_admin_session();

$config = topup_config();
$countries = [];
$items = [];

foreach ((array)($config['countries'] ?? []) as $country) {
    $countryCode = (string)($country['code'] ?? '');
    $countries[] = [
        'code' => $countryCode,
        'name' => (string)($country['name'] ?? $countryCode),
        'currency' => (string)($country['currency'] ?? ''),
        'dial_code' => (string)($country['dial_code'] ?? ''),
        'active' => (bool)($country['active'] ?? false),
        'sort_order' => (int)($country['sort_order'] ?? 999),
    ];

    foreach ((array)($country['operators'] ?? []) as $operatorRow) {
        $operator = normalize_operator($operatorRow['code'] ?? '');
        if ($operator === '') {
            continue;
        }

        $runtime = get_operator_runtime($operator) ?: [];
        $private = get_operator_private_config($operator) ?: [];
        $pinSet = trim((string)($private['retailer_secret_pin'] ?? '')) !== '';

        $items[] = [
            'operator' => $operator,
            'code' => $operator,
            'name' => (string)($operatorRow['name'] ?? $operator),
            'country_code' => $countryCode,
            'service_type' => (string)($operatorRow['service_type'] ?? 'PREPAID'),
            'active' => (bool)($operatorRow['active'] ?? false),
            'min_amount' => (float)($operatorRow['min_amount'] ?? 20),
            'max_amount' => (float)($operatorRow['max_amount'] ?? 1000),
            'quick_amounts' => array_values((array)($operatorRow['quick_amounts'] ?? [])),
            'prefixes' => array_values((array)($operatorRow['prefixes'] ?? [])),
            'sort_order' => (int)($operatorRow['sort_order'] ?? 999),
            'execution_ready' => topup_operator_ready_for_submit($countryCode, $operator),
            'dial_template' => (string)($runtime['dial_template'] ?? ''),
            'masked_template' => (string)($runtime['masked_template'] ?? ''),
            'requires_secret_pin' => (bool)($runtime['requires_secret_pin'] ?? true),
            'retailer_secret_pin_set' => $pinSet,
            'retailer_secret_pin_masked' => $pinSet ? '********' : '',
            'updated_at' => (int)max((int)($runtime['updated_at'] ?? 0), (int)($private['updated_at'] ?? 0)),
        ];
    }
}

api_response(true, 'SUCCESS', 'Operator list loaded', [
    'topup_enabled' => (bool)($config['topup_enabled'] ?? true),
    'maintenance_mode' => (bool)($config['maintenance_mode'] ?? false),
    'countries' => $countries,
    'items' => $items,
]);
