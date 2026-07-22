<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
$auth = auth_require_admin_session(true);
$actor = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$body = api_read_json_body();
$existingConfig = fb_get('DASHBOARD_CONFIG');
$existingConfig = is_array($existingConfig) ? $existingConfig : [];

$payload = [
    'notice_active' => zpay_dash_bool($body['notice_active'] ?? ($existingConfig['notice_active'] ?? true), true),
    'notice_text' => zpay_dash_clean_string($body['notice_text'] ?? ($existingConfig['notice_text'] ?? ''), 300),
    'dashboard_active' => zpay_dash_bool($body['dashboard_active'] ?? ($existingConfig['dashboard_active'] ?? true), true),
    'theme' => zpay_dash_theme_from_input($body, $existingConfig),
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
        'theme_name' => $payload['theme']['theme_name'],
    ]);
}

api_response(true, 'DASHBOARD_CONFIG_SAVED', 'Dashboard config saved.', $payload);
