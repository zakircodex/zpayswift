<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}
$auth = admin_mobile_require_session(true);
$config = fb_get('APP_CONFIG');
$config = is_array($config) ? $config : [];

if ($method === 'GET') {
    api_response(true, 'ADMIN_MOBILE_MAINTENANCE_OK', 'Maintenance state loaded.', [
        'enabled' => (bool)($config['maintenance_mode'] ?? false),
        'message' => system_maintenance_message(),
        'updated_at' => max(0, (int)($config['maintenance_updated_at'] ?? $config['updated_at'] ?? 0)),
        'updated_by_admin_uid' => (string)($config['maintenance_updated_by_admin_uid'] ?? $config['updated_by_admin_uid'] ?? ''),
    ]);
}

$body = api_read_json_body();
if (!array_key_exists('enabled', $body)) {
    api_response(false, 'VALIDATION_ERROR', 'Maintenance enabled state is required.', [], 422);
}
$enabled = filter_var($body['enabled'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($enabled === null) {
    api_response(false, 'VALIDATION_ERROR', 'Maintenance enabled state is invalid.', [], 422);
}
$now = now_ts();
$uid = (string)($auth['user']['uid'] ?? '');
$patch = [
    'maintenance_mode' => $enabled,
    'maintenance_updated_at' => $now,
    'maintenance_updated_by_admin_uid' => $uid,
];
if (!fb_patch('APP_CONFIG', $patch)) {
    api_response(false, 'MAINTENANCE_SAVE_FAILED', 'Maintenance state could not be updated.', [], 500);
}

admin_action_log('ADMIN_MOBILE_MAINTENANCE_' . ($enabled ? 'ENABLED' : 'DISABLED'), 'APP_CONFIG', 'Admin mobile changed maintenance state', [
    'maintenance_mode' => $enabled,
    'admin_uid' => $uid,
]);
system_log('ADMIN_MOBILE_MAINTENANCE', 'APP_CONFIG', 'Admin mobile changed maintenance state', [
    'maintenance_mode' => $enabled,
    'admin_uid' => $uid,
]);

api_response(true, 'ADMIN_MOBILE_MAINTENANCE_SAVED', 'Maintenance state updated.', $patch + [
    'enabled' => $enabled,
    'message' => system_maintenance_message(),
]);
