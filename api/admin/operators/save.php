<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('POST');
auth_require_admin_session();

$body = api_read_json_body();

$operator = normalize_operator($body['operator'] ?? '');
$name = trim((string)($body['name'] ?? ''));
$active = (bool)($body['active'] ?? true);
$dialTemplate = trim((string)($body['dial_template'] ?? ''));
$maskedTemplate = trim((string)($body['masked_template'] ?? ''));
$requiresSecretPin = (bool)($body['requires_secret_pin'] ?? true);
$retailerSecretPin = trim((string)($body['retailer_secret_pin'] ?? ''));

if (!is_valid_operator($operator)) {
    api_response(false, 'INVALID_OPERATOR', 'Invalid operator', [], 422);
}

if ($name === '') {
    api_response(false, 'VALIDATION_ERROR', 'name is required', ['field' => 'name'], 422);
}

if ($dialTemplate === '') {
    api_response(false, 'VALIDATION_ERROR', 'dial_template is required', ['field' => 'dial_template'], 422);
}

if (!str_contains($dialTemplate, '{NUMBER}') || !str_contains($dialTemplate, '{AMOUNT}')) {
    api_response(false, 'VALIDATION_ERROR', 'dial_template must contain {NUMBER} and {AMOUNT}', [], 422);
}

if ($requiresSecretPin && !str_contains($dialTemplate, '{PIN}')) {
    api_response(false, 'VALIDATION_ERROR', 'dial_template must contain {PIN} when requires_secret_pin is true', [], 422);
}

if ($requiresSecretPin && $retailerSecretPin === '') {
    $existingPrivate = fb_get('OPERATOR_PRIVATE/' . $operator);
    $existingPin = is_array($existingPrivate) ? trim((string)($existingPrivate['retailer_secret_pin'] ?? '')) : '';

    if ($existingPin === '') {
        api_response(false, 'VALIDATION_ERROR', 'retailer_secret_pin is required', ['field' => 'retailer_secret_pin'], 422);
    }

    $retailerSecretPin = $existingPin;
}

$now = now_ts();

$runtime = [
    'code' => $operator,
    'name' => $name,
    'active' => $active,
    'dial_template' => $dialTemplate,
    'masked_template' => $maskedTemplate !== '' ? $maskedTemplate : $dialTemplate,
    'requires_secret_pin' => $requiresSecretPin,
    'updated_at' => $now,
];

$private = [
    'retailer_secret_pin' => $retailerSecretPin,
    'updated_at' => $now,
];

if (!fb_put('OPERATOR_RUNTIME/' . $operator, $runtime)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save operator runtime', [], 500);
}

if (!fb_put('OPERATOR_PRIVATE/' . $operator, $private)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save operator private config', [], 500);
}

admin_action_log('SAVE_OPERATOR', $operator, 'Operator settings updated', [
    'operator' => $operator,
    'active' => $active,
]);

system_log('ADMIN_SAVE_OPERATOR', $operator, 'Operator settings updated', [
    'operator' => $operator,
    'active' => $active,
]);

api_response(true, 'SUCCESS', 'Operator settings saved', [
    'operator' => $operator,
]);
