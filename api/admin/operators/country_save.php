<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/operators.php';
require_once dirname(__DIR__, 2) . '/lib/topup_config.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = (array)($auth['user'] ?? []);
$body = api_read_json_body();

$countryCode = topup_country_code($body['code'] ?? $body['country_code'] ?? '');
$name = topup_clean_text($body['name'] ?? '', 80);
$currency = strtoupper(topup_clean_text($body['currency'] ?? '', 10));
$dialCode = topup_clean_text($body['dial_code'] ?? '', 10);
$active = topup_bool($body['active'] ?? true, true);
$sortOrder = topup_int($body['sort_order'] ?? 999, 999);

if (!in_array($countryCode, ['BD', 'MY'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'code must be BD or MY', ['field' => 'code'], 422);
}
if ($name === '') {
    api_response(false, 'VALIDATION_ERROR', 'name is required', ['field' => 'name'], 422);
}
if ($currency === '') {
    api_response(false, 'VALIDATION_ERROR', 'currency is required', ['field' => 'currency'], 422);
}
if ($dialCode === '') {
    api_response(false, 'VALIDATION_ERROR', 'dial_code is required', ['field' => 'dial_code'], 422);
}

$existingRaw = fb_get('TOPUP_CONFIG');
$config = topup_config_payload(is_array($existingRaw) ? $existingRaw : [], false);
$found = false;

foreach ($config['countries'] as &$country) {
    if ((string)($country['code'] ?? '') !== $countryCode) {
        continue;
    }
    $country['name'] = $name;
    $country['currency'] = $currency;
    $country['dial_code'] = $dialCode;
    $country['active'] = $active;
    $country['sort_order'] = $sortOrder;
    $found = true;
    break;
}
unset($country);

if (!$found) {
    api_response(false, 'VALIDATION_ERROR', 'Country is not supported', ['field' => 'code'], 422);
}

$config['updated_at'] = now_ts();
$config['updated_by'] = (string)($adminUser['uid'] ?? '');
$config['updated_by_role'] = auth_status_value($adminUser['role'] ?? 'ADMIN');

if (!fb_put('TOPUP_CONFIG', $config)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save country config', [], 500);
}

admin_action_log('SAVE_TOPUP_COUNTRY', $countryCode, 'Top-up country settings updated', [
    'country_code' => $countryCode,
    'active' => $active,
]);

api_response(true, 'SUCCESS', 'Country settings saved', [
    'country_code' => $countryCode,
]);
