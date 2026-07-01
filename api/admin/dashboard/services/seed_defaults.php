<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_admin_or_subadmin(true);
$actor = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$now = now_ts();
$saved = [];

foreach (zpay_dash_default_services() as $key => $service) {
    $row = zpay_dash_normalize_service(array_merge($service, [
        'updated_at' => $now,
        'updated_by' => (string)($actor['uid'] ?? ''),
    ]), $key);
    if (!fb_put('DASHBOARD_SERVICES/' . $row['service_key'], $row)) {
        api_response(false, 'SERVER_ERROR', 'Failed to seed dashboard services.', ['service_key' => $key], 500);
    }
    $saved[] = $row;
}

api_response(true, 'SERVICES_SEEDED', 'Default dashboard services saved.', ['items' => $saved]);
