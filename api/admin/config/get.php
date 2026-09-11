<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
$auth = auth_require_admin_session(true);

$row = fb_get('APP_CONFIG');
if (!is_array($row)) {
    $row = [];
}

api_response(true, 'SUCCESS', 'App config loaded', [
    'topup_enabled' => (bool)($row['topup_enabled'] ?? true),
    'bundle_enabled' => (bool)($row['bundle_enabled'] ?? true),
    'maintenance_mode' => (bool)($row['maintenance_mode'] ?? false),
    'privacy_policy_url' => trim((string)($row['privacy_policy_url'] ?? '')),
    'terms_conditions_url' => trim((string)($row['terms_conditions_url'] ?? '')),
    'android_latest_version_code' => app_runtime_positive_int(
        $row['android_latest_version_code'] ?? APP_RUNTIME_DEFAULT_ANDROID_VERSION_CODE,
        APP_RUNTIME_DEFAULT_ANDROID_VERSION_CODE
    ),
    'android_latest_version_name' => app_runtime_version_name(
        $row['android_latest_version_name'] ?? APP_RUNTIME_DEFAULT_ANDROID_VERSION_NAME
    ),
    'android_update_url' => app_runtime_https_url(
        $row['android_update_url'] ?? APP_RUNTIME_DEFAULT_ANDROID_UPDATE_URL
    ),
    'android_update_message' => app_runtime_update_message(
        $row['android_update_message'] ?? APP_RUNTIME_DEFAULT_ANDROID_UPDATE_MESSAGE
    ),

    'min_topup_amount' => (float)($row['min_topup_amount'] ?? 0),
    'max_topup_amount' => (float)($row['max_topup_amount'] ?? 0),

    'min_bundle_amount' => (float)($row['min_bundle_amount'] ?? 0),
    'max_bundle_amount' => (float)($row['max_bundle_amount'] ?? 0),

    'updated_at' => (int)($row['updated_at'] ?? 0),
    'updated_by_admin_uid' => (string)($row['updated_by_admin_uid'] ?? ''),
]);
