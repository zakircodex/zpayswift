<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];

$body = api_read_json_body();
$existing = fb_get('APP_CONFIG');
$existing = is_array($existing) ? $existing : [];

$topupEnabled = (bool)($body['topup_enabled'] ?? true);
$bundleEnabled = (bool)($body['bundle_enabled'] ?? true);
$maintenanceMode = (bool)($body['maintenance_mode'] ?? false);

$minTopupAmount = (float)($body['min_topup_amount'] ?? 0);
$maxTopupAmount = (float)($body['max_topup_amount'] ?? 0);

$minBundleAmount = (float)($body['min_bundle_amount'] ?? 0);
$maxBundleAmount = (float)($body['max_bundle_amount'] ?? 0);
$privacyPolicyUrl = app_config_public_https_url(
    array_key_exists('privacy_policy_url', $body) ? $body['privacy_policy_url'] : ($existing['privacy_policy_url'] ?? '')
);
$termsConditionsUrl = app_config_public_https_url(
    array_key_exists('terms_conditions_url', $body) ? $body['terms_conditions_url'] : ($existing['terms_conditions_url'] ?? '')
);

if ($minTopupAmount < 0) {
    api_response(false, 'VALIDATION_ERROR', 'min_topup_amount cannot be negative', ['field' => 'min_topup_amount'], 422);
}

if ($maxTopupAmount < 0) {
    api_response(false, 'VALIDATION_ERROR', 'max_topup_amount cannot be negative', ['field' => 'max_topup_amount'], 422);
}

if ($minBundleAmount < 0) {
    api_response(false, 'VALIDATION_ERROR', 'min_bundle_amount cannot be negative', ['field' => 'min_bundle_amount'], 422);
}

if ($maxBundleAmount < 0) {
    api_response(false, 'VALIDATION_ERROR', 'max_bundle_amount cannot be negative', ['field' => 'max_bundle_amount'], 422);
}

if ($maxTopupAmount > 0 && $minTopupAmount > 0 && $maxTopupAmount < $minTopupAmount) {
    api_response(false, 'VALIDATION_ERROR', 'max_topup_amount must be greater than or equal to min_topup_amount', [], 422);
}

if ($maxBundleAmount > 0 && $minBundleAmount > 0 && $maxBundleAmount < $minBundleAmount) {
    api_response(false, 'VALIDATION_ERROR', 'max_bundle_amount must be greater than or equal to min_bundle_amount', [], 422);
}

if (!$privacyPolicyUrl['ok']) {
    api_response(false, 'VALIDATION_ERROR', 'Privacy Policy URL must be a valid HTTPS URL.', [
        'field' => 'privacy_policy_url',
    ], 422);
}

if (!$termsConditionsUrl['ok']) {
    api_response(false, 'VALIDATION_ERROR', 'Terms & Conditions URL must be a valid HTTPS URL.', [
        'field' => 'terms_conditions_url',
    ], 422);
}

$payload = [
    'topup_enabled' => $topupEnabled,
    'bundle_enabled' => $bundleEnabled,
    'maintenance_mode' => $maintenanceMode,
    'privacy_policy_url' => $privacyPolicyUrl['url'],
    'terms_conditions_url' => $termsConditionsUrl['url'],

    'min_topup_amount' => $minTopupAmount,
    'max_topup_amount' => $maxTopupAmount,

    'min_bundle_amount' => $minBundleAmount,
    'max_bundle_amount' => $maxBundleAmount,

    'updated_at' => now_ts(),
    'updated_by_admin_uid' => (string)($adminUser['uid'] ?? ''),
];

if (!fb_patch('APP_CONFIG', $payload)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save app config', [], 500);
}

admin_action_log('SAVE_APP_CONFIG', 'APP_CONFIG', 'Admin updated app config', [
    'topup_enabled' => $topupEnabled,
    'bundle_enabled' => $bundleEnabled,
    'maintenance_mode' => $maintenanceMode,
    'privacy_policy_url_configured' => $privacyPolicyUrl['url'] !== '',
    'terms_conditions_url_configured' => $termsConditionsUrl['url'] !== '',
    'min_topup_amount' => $minTopupAmount,
    'max_topup_amount' => $maxTopupAmount,
    'min_bundle_amount' => $minBundleAmount,
    'max_bundle_amount' => $maxBundleAmount,
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

system_log('ADMIN_SAVE_APP_CONFIG', 'APP_CONFIG', 'Admin updated app config', [
    'topup_enabled' => $topupEnabled,
    'bundle_enabled' => $bundleEnabled,
    'maintenance_mode' => $maintenanceMode,
    'privacy_policy_url_configured' => $privacyPolicyUrl['url'] !== '',
    'terms_conditions_url_configured' => $termsConditionsUrl['url'] !== '',
    'min_topup_amount' => $minTopupAmount,
    'max_topup_amount' => $maxTopupAmount,
    'min_bundle_amount' => $minBundleAmount,
    'max_bundle_amount' => $maxBundleAmount,
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

api_response(true, 'SUCCESS', 'App config saved successfully', $payload);

function app_config_public_https_url($value): array
{
    $url = trim((string)$value);
    if ($url === '') {
        return ['ok' => true, 'url' => ''];
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return ['ok' => false, 'url' => ''];
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return ['ok' => false, 'url' => ''];
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = trim((string)($parts['host'] ?? ''));
    if ($scheme !== 'https' || $host === '') {
        return ['ok' => false, 'url' => ''];
    }

    return ['ok' => true, 'url' => $url];
}
