<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/storage_cleanup.php';
require_once dirname(__DIR__, 2) . '/lib/storage_cleanup_email.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'];

$body = api_read_json_body();
$cleanupLock = storage_cleanup_acquire_lock();
if ($cleanupLock === false) {
    api_response(false, 'STORAGE_CLEANUP_BUSY', 'Cleanup or a settings save is running. Retry when it finishes.', [], 409);
}
register_shutdown_function(static function () use ($cleanupLock): void {
    storage_cleanup_release_lock($cleanupLock);
});
$configRead = storage_cleanup_email_read_record('APP_CONFIG');
$currentCleanupEmail = storage_cleanup_email_settings_from_config($configRead);
if (empty($currentCleanupEmail['ok'])) {
    api_response(false, (string)$currentCleanupEmail['code'], 'App settings could not be verified. Retry before saving.', [], 503);
}
$existing = $configRead['row'];

if ((array_key_exists('storage_cleanup_email_enabled', $body) && !is_bool($body['storage_cleanup_email_enabled']))
    || (array_key_exists('storage_cleanup_email_recipient', $body) && !is_string($body['storage_cleanup_email_recipient']))
) {
    api_response(false, 'VALIDATION_ERROR', 'Storage cleanup email settings are invalid.', [], 422);
}

$topupEnabled = (bool)($body['topup_enabled'] ?? true);
$bundleEnabled = (bool)($body['bundle_enabled'] ?? true);
$maintenanceMode = (bool)($body['maintenance_mode'] ?? false);
$storageCleanupEmailEnabled = array_key_exists('storage_cleanup_email_enabled', $body)
    ? storage_cleanup_email_bool($body['storage_cleanup_email_enabled'])
    : !empty($currentCleanupEmail['enabled']);
$storageCleanupEmailRaw = array_key_exists('storage_cleanup_email_recipient', $body)
    ? (string)$body['storage_cleanup_email_recipient']
    : (string)($currentCleanupEmail['recipient'] ?? '');
$storageCleanupEmailRecipient = storage_cleanup_email_address($storageCleanupEmailRaw);

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
$androidLatestVersionCodeValue = $body['android_latest_version_code']
    ?? $existing['android_latest_version_code']
    ?? APP_RUNTIME_DEFAULT_ANDROID_VERSION_CODE;
$androidLatestVersionCode = filter_var($androidLatestVersionCodeValue, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$androidLatestVersionName = trim((string)($body['android_latest_version_name']
    ?? $existing['android_latest_version_name']
    ?? APP_RUNTIME_DEFAULT_ANDROID_VERSION_NAME));
$androidUpdateUrl = app_config_public_https_url(
    $body['android_update_url']
        ?? $existing['android_update_url']
        ?? APP_RUNTIME_DEFAULT_ANDROID_UPDATE_URL
);
$androidUpdateMessage = trim((string)($body['android_update_message']
    ?? $existing['android_update_message']
    ?? APP_RUNTIME_DEFAULT_ANDROID_UPDATE_MESSAGE));

if (trim($storageCleanupEmailRaw) !== '' && $storageCleanupEmailRecipient === '') {
    api_response(false, 'VALIDATION_ERROR', 'Storage cleanup email must be a valid email address.', [
        'field' => 'storage_cleanup_email_recipient',
    ], 422);
}

if ($storageCleanupEmailEnabled && $storageCleanupEmailRecipient === '') {
    api_response(false, 'VALIDATION_ERROR', 'A valid backup email is required before automatic cleanup can be enabled.', [
        'field' => 'storage_cleanup_email_recipient',
    ], 422);
}

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

if ($androidLatestVersionCode === false) {
    api_response(false, 'VALIDATION_ERROR', 'Android version code must be at least 1.', [
        'field' => 'android_latest_version_code',
    ], 422);
}
$androidLatestVersionCode = (int)$androidLatestVersionCode;

if ($androidLatestVersionName === '' || strlen($androidLatestVersionName) > 40
    || preg_match('/[\x00-\x1F\x7F]/', $androidLatestVersionName) === 1
) {
    api_response(false, 'VALIDATION_ERROR', 'Android version name is invalid.', [
        'field' => 'android_latest_version_name',
    ], 422);
}

if (!$androidUpdateUrl['ok'] || $androidUpdateUrl['url'] === '') {
    api_response(false, 'VALIDATION_ERROR', 'Android Update URL must be a valid HTTPS URL.', [
        'field' => 'android_update_url',
    ], 422);
}

$androidUpdateMessageLength = function_exists('mb_strlen')
    ? mb_strlen($androidUpdateMessage, 'UTF-8')
    : strlen($androidUpdateMessage);
if ($androidUpdateMessage === '' || $androidUpdateMessageLength > 240
    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $androidUpdateMessage) === 1
) {
    api_response(false, 'VALIDATION_ERROR', 'Android update message must be between 1 and 240 characters.', [
        'field' => 'android_update_message',
    ], 422);
}

$payload = [
    'topup_enabled' => $topupEnabled,
    'bundle_enabled' => $bundleEnabled,
    'maintenance_mode' => $maintenanceMode,
    'privacy_policy_url' => $privacyPolicyUrl['url'],
    'terms_conditions_url' => $termsConditionsUrl['url'],
    'android_latest_version_code' => $androidLatestVersionCode,
    'android_latest_version_name' => $androidLatestVersionName,
    'android_update_url' => $androidUpdateUrl['url'],
    'android_update_message' => $androidUpdateMessage,
    'storage_cleanup_email_enabled' => $storageCleanupEmailEnabled,
    'storage_cleanup_email_recipient' => $storageCleanupEmailRecipient,
    'storage_cleanup_email_revision' => isset($existing['storage_cleanup_email_revision'])
        && $storageCleanupEmailEnabled === $currentCleanupEmail['enabled']
        && $storageCleanupEmailRecipient === $currentCleanupEmail['recipient']
            ? $existing['storage_cleanup_email_revision'] : bin2hex(random_bytes(16)),

    'min_topup_amount' => $minTopupAmount,
    'max_topup_amount' => $maxTopupAmount,

    'min_bundle_amount' => $minBundleAmount,
    'max_bundle_amount' => $maxBundleAmount,

    'updated_at' => now_ts(),
    'updated_by_admin_uid' => (string)($adminUser['uid'] ?? ''),
];

$configWrite = fb_put_if_match('APP_CONFIG', array_replace($existing, $payload), $configRead['etag']);
if (empty($configWrite['ok']) && (int)($configWrite['status'] ?? 0) === 412) {
    api_response(false, 'APP_CONFIG_CHANGED', 'App settings changed while saving. Reload and retry.', [], 409);
}
if (empty($configWrite['ok'])) {
    api_response(false, 'SERVER_ERROR', 'Failed to save app config', [], 500);
}

admin_action_log('SAVE_APP_CONFIG', 'APP_CONFIG', 'Admin updated app config', [
    'topup_enabled' => $topupEnabled,
    'bundle_enabled' => $bundleEnabled,
    'maintenance_mode' => $maintenanceMode,
    'privacy_policy_url_configured' => $privacyPolicyUrl['url'] !== '',
    'terms_conditions_url_configured' => $termsConditionsUrl['url'] !== '',
    'android_latest_version_code' => $androidLatestVersionCode,
    'android_latest_version_name' => $androidLatestVersionName,
    'android_update_url_configured' => $androidUpdateUrl['url'] !== '',
    'storage_cleanup_email_enabled' => $storageCleanupEmailEnabled,
    'storage_cleanup_email_recipient_configured' => $storageCleanupEmailRecipient !== '',
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
    'android_latest_version_code' => $androidLatestVersionCode,
    'android_latest_version_name' => $androidLatestVersionName,
    'android_update_url_configured' => $androidUpdateUrl['url'] !== '',
    'storage_cleanup_email_enabled' => $storageCleanupEmailEnabled,
    'storage_cleanup_email_recipient_configured' => $storageCleanupEmailRecipient !== '',
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
