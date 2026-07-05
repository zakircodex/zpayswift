<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/topup_config.php';

api_require_app_key();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
}

$auth = auth_require_user(false);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];

if ($method === 'GET') {
    api_response(true, 'TOPUP_CONFIG_READY', 'Top-up configuration loaded.', topup_config());
}

$role = auth_status_value($user['role'] ?? '');
if (!in_array($role, ['ADMIN', 'SUBADMIN'], true)) {
    api_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can update top-up config.', [], 403);
}

$body = api_read_json_body();
$existing = fb_get('TOPUP_CONFIG');
$existing = is_array($existing) ? $existing : [];

$candidate = [
    'countries' => is_array($body['countries'] ?? null) ? $body['countries'] : ($existing['countries'] ?? []),
    'updated_at' => now_ts(),
    'updated_by' => (string)($user['uid'] ?? ''),
    'updated_by_role' => $role,
];

$normalized = topup_config_payload($candidate, false);
$normalized['updated_at'] = now_ts();
$normalized['updated_by'] = (string)($user['uid'] ?? '');
$normalized['updated_by_role'] = $role;

if (!fb_put('TOPUP_CONFIG', $normalized)) {
    api_response(false, 'SERVER_ERROR', 'Failed to save top-up config.', [], 500);
}

if (function_exists('admin_action_log')) {
    admin_action_log('TOPUP_CONFIG_UPDATE', 'TOPUP_CONFIG', 'Top-up config updated', [
        'updated_by' => (string)($user['uid'] ?? ''),
        'updated_by_role' => $role,
    ]);
}

api_response(true, 'TOPUP_CONFIG_SAVED', 'Top-up config saved.', $normalized);
