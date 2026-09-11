<?php
declare(strict_types=1);

$appUpdateAssertions = 0;

function app_update_expect(bool $condition, string $message): void
{
    global $appUpdateAssertions;
    $appUpdateAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
require_once $root . '/api/lib/app_runtime.php';

$config = [
    'android_latest_version_code' => 7,
    'android_latest_version_name' => '2.4.0',
    'android_update_url' => 'https://zpayswift.com/download.php',
    'android_update_message' => 'Update Z-Pay Swift to continue.',
];

$oldClient = app_runtime_android_update($config, 6, '2.3.0');
$currentClient = app_runtime_android_update($config, 7, '2.4.0');
$newerClient = app_runtime_android_update($config, 8, '2.5.0');
$sameCodeOlderName = app_runtime_android_update($config, 7, '2.3.0');
$legacyClient = app_runtime_android_update($config, 0, '2.3.0');
$legacyCurrentClient = app_runtime_android_update($config, 0, '2.4.0');

app_update_expect(!empty($oldClient['required']), 'A lower Android version code must require an update.');
app_update_expect(empty($currentClient['required']), 'The configured Android version must remain allowed.');
app_update_expect(empty($newerClient['required']), 'A newer Android build must remain allowed.');
app_update_expect(!empty($sameCodeOlderName['required']), 'An older version name with the same code must require an update.');
app_update_expect(!empty($legacyClient['required']), 'A legacy client without a version code must be checked by version name.');
app_update_expect(empty($legacyCurrentClient['required']), 'A legacy client on the configured version name must remain allowed.');
app_update_expect(
    !app_runtime_is_android_client_request(['device_id' => 'USER_WEB', 'app_version' => 'WEB']),
    'Browser login requests must remain outside the Android update gate.'
);
app_update_expect(
    app_runtime_is_android_client_request(['device_id' => 'zpa-device', 'app_version' => '2.4.0']),
    'Native Android login requests must use the Android update gate.'
);
app_update_expect((string)$oldClient['latest_version_name'] === '2.4.0', 'Latest version name must be public runtime data.');
app_update_expect((string)$oldClient['update_url'] === 'https://zpayswift.com/download.php', 'Configured HTTPS update URL must be preserved.');
app_update_expect(
    app_runtime_https_url('javascript:alert(1)') === APP_RUNTIME_DEFAULT_ANDROID_UPDATE_URL,
    'Unsafe update URLs must fall back to the canonical HTTPS download page.'
);

$endpoint = (string)file_get_contents($root . '/api/app/runtime.php');
$adminGet = (string)file_get_contents($root . '/api/admin/config/get.php');
$adminSave = (string)file_get_contents($root . '/api/admin/config/save.php');
$adminUi = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');

app_update_expect(str_contains($endpoint, 'api_require_app_key()'), 'Public app runtime endpoint must require the Android app key.');
app_update_expect(str_contains($endpoint, 'app_runtime_android_update'), 'Public app runtime endpoint must use the shared version policy.');
app_update_expect(
    str_contains($endpoint, "fb_get_with_etag('APP_CONFIG')")
        && str_contains($endpoint, "'APP_CONFIG_UNAVAILABLE'"),
    'Version checks must fail closed when remote app config is unavailable.'
);
foreach ([
    'android_latest_version_code',
    'android_latest_version_name',
    'android_update_url',
    'android_update_message',
] as $field) {
    app_update_expect(str_contains($adminGet, $field), "Admin config read must expose {$field}.");
    app_update_expect(str_contains($adminSave, $field), "Admin config save must persist {$field}.");
    app_update_expect(str_contains($adminUi, $field), "System Settings must submit {$field}.");
}

app_update_expect(str_contains($adminUi, 'cfgAndroidVersionCode'), 'System Settings must expose Android version code.');
app_update_expect(str_contains($adminUi, 'cfgAndroidUpdateUrl'), 'System Settings must expose the APK update URL.');

foreach (['check_number.php', 'pin_login.php', 'biometric_login.php'] as $loginEndpoint) {
    $loginSource = (string)file_get_contents($root . '/api/auth/' . $loginEndpoint);
    app_update_expect(
        str_contains($loginSource, 'app_runtime_require_current_android_client($body)'),
        "{$loginEndpoint} must enforce the configured Android release server-side."
    );
}

echo "App update runtime tests passed ({$appUpdateAssertions} assertions).\n";
