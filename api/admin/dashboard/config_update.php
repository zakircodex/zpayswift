<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_admin_or_subadmin(true);
$actor = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$body = api_read_json_body();

$payload = [
    'notice_active' => zpay_dash_bool($body['notice_active'] ?? true, true),
    'notice_text' => zpay_dash_clean_string($body['notice_text'] ?? '', 300),
    'dashboard_active' => zpay_dash_bool($body['dashboard_active'] ?? true, true),
    'updated_at' => now_ts(),
    'updated_by' => (string)($actor['uid'] ?? ''),
    'updated_by_role' => (string)($actor['role'] ?? ''),
];

if (!fb_patch('DASHBOARD_CONFIG', $payload)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save dashboard config.', [], 500);
}

if (function_exists('admin_action_log')) {
    admin_action_log('DASHBOARD_CONFIG_UPDATE', 'DASHBOARD_CONFIG', 'Dashboard config updated', [
        'updated_by' => (string)($actor['uid'] ?? ''),
        'updated_by_role' => (string)($actor['role'] ?? ''),
        'notice_active' => $payload['notice_active'],
        'dashboard_active' => $payload['dashboard_active'],
    ]);
}

api_response(true, 'DASHBOARD_CONFIG_SAVED', 'Dashboard config saved.', $payload);
