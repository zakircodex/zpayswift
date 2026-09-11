<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$configRead = fb_get_with_etag('APP_CONFIG');
if (empty($configRead['ok'])) {
    api_response(false, 'APP_CONFIG_UNAVAILABLE', 'App configuration is temporarily unavailable.', [], 503);
}
$config = is_array($configRead['value'] ?? null) ? (array)$configRead['value'] : [];
$currentVersionCode = app_runtime_positive_int($_GET['version_code'] ?? 0, 1);
if ((int)($_GET['version_code'] ?? 0) <= 0) {
    $currentVersionCode = 0;
}
$currentVersionName = trim((string)($_GET['version_name'] ?? ''));

api_response(true, 'APP_BOOTSTRAP_READY', 'App configuration loaded', [
    'android_update' => app_runtime_android_update($config, $currentVersionCode, $currentVersionName),
    'service_hours' => [
        'closed_from' => '00:00',
        'opens_at' => '08:00',
        'bd_timezone' => SERVICE_HOURS_BD_TIMEZONE,
        'my_timezone' => SERVICE_HOURS_MY_TIMEZONE,
        'transfer_available' => true,
    ],
]);
