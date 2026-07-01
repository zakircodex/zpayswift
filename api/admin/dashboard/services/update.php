<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_admin_or_subadmin(true);
$actor = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$body = api_read_json_body();
$now = now_ts();

$services = [];
if (isset($body['services']) && is_array($body['services'])) {
    $services = $body['services'];
} else {
    $services = [$body];
}

$saved = [];
foreach ($services as $service) {
    if (!is_array($service)) {
        continue;
    }
    $serviceKey = strtoupper(zpay_dash_clean_string($service['service_key'] ?? '', 60));
    $serviceKey = preg_replace('/[^A-Z0-9_]+/', '_', $serviceKey) ?? '';
    $serviceKey = trim($serviceKey, '_');
    if ($serviceKey === '') {
        api_response(false, 'VALIDATION_ERROR', 'service_key is required.', [], 422);
    }

    $existing = fb_get('DASHBOARD_SERVICES/' . $serviceKey);
    $existing = is_array($existing) ? $existing : (zpay_dash_default_services()[$serviceKey] ?? []);
    $row = zpay_dash_normalize_service(array_merge($existing, $service, [
        'service_key' => $serviceKey,
        'updated_at' => $now,
        'updated_by' => (string)($actor['uid'] ?? ''),
    ]), $serviceKey);

    if (!fb_put('DASHBOARD_SERVICES/' . $serviceKey, $row)) {
        api_response(false, 'SERVER_ERROR', 'Failed to update dashboard service.', ['service_key' => $serviceKey], 500);
    }
    $saved[] = $row;
}

api_response(true, 'SERVICES_UPDATED', 'Dashboard services updated.', ['items' => $saved]);
