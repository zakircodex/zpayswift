<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/operators.php';
require_once dirname(__DIR__, 2) . '/lib/topup_config.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = (array)($auth['user'] ?? []);

$body = api_read_json_body();

$operator = normalize_operator($body['operator'] ?? $body['code'] ?? '');
$name = topup_clean_text($body['name'] ?? '', 80);
$countryCode = topup_country_code($body['country_code'] ?? $body['country'] ?? '');
$serviceType = topup_service_type($body['service_type'] ?? 'PREPAID');
$active = topup_bool($body['active'] ?? true, true);
$minAmount = topup_money($body['min_amount'] ?? 0);
$maxAmount = topup_money($body['max_amount'] ?? 0);
$quickAmounts = topup_normalize_quick_amounts($body['quick_amounts'] ?? [], $minAmount > 0 ? $minAmount : 0, $maxAmount > 0 ? $maxAmount : 999999);
$prefixes = topup_normalize_prefixes($body['prefixes'] ?? []);
$sortOrder = topup_int($body['sort_order'] ?? 999, 999);
$dialTemplate = trim((string)($body['dial_template'] ?? ''));
$maskedTemplate = trim((string)($body['masked_template'] ?? ''));
$requiresSecretPin = topup_bool($body['requires_secret_pin'] ?? true, true);
$retailerSecretPin = trim((string)($body['retailer_secret_pin'] ?? ''));

if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Invalid operator', [], 422);
}

if (!in_array($countryCode, ['BD', 'MY'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'country_code must be BD or MY', ['field' => 'country_code'], 422);
}

if ($serviceType !== 'PREPAID') {
    api_response(false, 'VALIDATION_ERROR', 'Only PREPAID service_type is supported', ['field' => 'service_type'], 422);
}

if ($name === '') {
    api_response(false, 'VALIDATION_ERROR', 'name is required', ['field' => 'name'], 422);
}

if ($minAmount <= 0) {
    api_response(false, 'VALIDATION_ERROR', 'min_amount must be greater than zero', ['field' => 'min_amount'], 422);
}

if ($maxAmount < $minAmount) {
    api_response(false, 'VALIDATION_ERROR', 'max_amount must be greater than or equal to min_amount', ['field' => 'max_amount'], 422);
}

$existingRaw = fb_get('TOPUP_CONFIG');
$config = topup_config_payload(is_array($existingRaw) ? $existingRaw : [], false);
$found = false;
$catalogCountry = '';

foreach ($config['countries'] as $country) {
    foreach ((array)($country['operators'] ?? []) as $row) {
        if (normalize_operator($row['code'] ?? '') === $operator) {
            $found = true;
            $catalogCountry = (string)($country['code'] ?? '');
            break 2;
        }
    }
}

if (!$found) {
    api_response(false, 'INVALID_OPERATOR', 'Operator is not supported', [], 422);
}

if ($catalogCountry !== $countryCode) {
    api_response(false, 'VALIDATION_ERROR', 'country_code cannot be changed for this operator', ['field' => 'country_code'], 422);
}

$workerDialReady = topup_operator_worker_dial_ready($countryCode, $operator);
if ($active && $workerDialReady) {
    if ($dialTemplate === '') {
        api_response(false, 'VALIDATION_ERROR', 'dial_template is required for active live operators', ['field' => 'dial_template'], 422);
    }
    if (!str_contains($dialTemplate, '{NUMBER}') || !str_contains($dialTemplate, '{AMOUNT}')) {
        api_response(false, 'VALIDATION_ERROR', 'dial_template must contain {NUMBER} and {AMOUNT}', [], 422);
    }
    if ($requiresSecretPin && !str_contains($dialTemplate, '{PIN}')) {
        api_response(false, 'VALIDATION_ERROR', 'dial_template must contain {PIN} when requires_secret_pin is true', [], 422);
    }
}

$existingPrivate = fb_get('OPERATOR_PRIVATE/' . $operator);
$existingPin = is_array($existingPrivate) ? trim((string)($existingPrivate['retailer_secret_pin'] ?? '')) : '';
if ($active && $workerDialReady && $requiresSecretPin && $retailerSecretPin === '' && $existingPin === '') {
    api_response(false, 'VALIDATION_ERROR', 'retailer_secret_pin is required', ['field' => 'retailer_secret_pin'], 422);
}
if ($retailerSecretPin === '') {
    $retailerSecretPin = $existingPin;
}

$now = now_ts();
foreach ($config['countries'] as &$country) {
    if ((string)($country['code'] ?? '') !== $countryCode) {
        continue;
    }
    foreach ($country['operators'] as &$row) {
        if (normalize_operator($row['code'] ?? '') !== $operator) {
            continue;
        }
        $row['code'] = $operator;
        $row['name'] = $name;
        $row['country_code'] = $countryCode;
        $row['service_type'] = $serviceType;
        $row['active'] = $active;
        $row['min_amount'] = $minAmount;
        $row['max_amount'] = $maxAmount;
        $row['quick_amounts'] = $quickAmounts;
        $row['prefixes'] = $prefixes;
        $row['sort_order'] = $sortOrder;
        break 2;
    }
}
unset($country, $row);

$config['updated_at'] = $now;
$config['updated_by'] = (string)($adminUser['uid'] ?? '');
$config['updated_by_role'] = auth_status_value($adminUser['role'] ?? 'ADMIN');

if (!fb_put('TOPUP_CONFIG', $config)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save top-up config', [], 500);
}

$existingRuntime = get_operator_runtime($operator) ?: [];
$runtime = array_merge($existingRuntime, [
    'code' => $operator,
    'name' => $name,
    'country_code' => $countryCode,
    'service_type' => $serviceType,
    'active' => $active,
    'min_amount' => $minAmount,
    'max_amount' => $maxAmount,
    'quick_amounts' => $quickAmounts,
    'prefixes' => $prefixes,
    'sort_order' => $sortOrder,
    'dial_template' => $dialTemplate,
    'masked_template' => $maskedTemplate !== '' ? $maskedTemplate : $dialTemplate,
    'requires_secret_pin' => $requiresSecretPin,
    'updated_at' => $now,
]);

if (!fb_put('OPERATOR_RUNTIME/' . $operator, $runtime)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save operator runtime', [], 500);
}

if (!fb_put('OPERATOR_PRIVATE/' . $operator, [
    'retailer_secret_pin' => $retailerSecretPin,
    'updated_at' => $now,
])) {
    api_response(false, 'SERVER_ERROR', 'Failed to save operator private config', [], 500);
}

admin_action_log('SAVE_OPERATOR', $operator, 'Operator settings updated', [
    'operator' => $operator,
    'country_code' => $countryCode,
    'active' => $active,
]);

system_log('ADMIN_SAVE_OPERATOR', $operator, 'Operator settings updated', [
    'operator' => $operator,
    'country_code' => $countryCode,
    'active' => $active,
]);

api_response(true, 'SUCCESS', 'Operator settings saved', [
    'operator' => $operator,
]);
