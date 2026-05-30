<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'] ?? [];

$body = api_read_json_body();
$uid = trim((string)($body['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

$user = subapi_load_user($uid);
if (!$user) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$role = strtoupper(trim((string)($user['role'] ?? 'USER')));
$status = strtoupper(trim((string)($user['status'] ?? 'INACTIVE')));

if (!subapi_allowed_role($role)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'Only SUBADMIN or ADMIN can have API keys', [], 422);
}

if ($status !== 'ACTIVE') {
    api_response(false, 'USER_INACTIVE', 'User must be ACTIVE to create API key', [], 422);
}

$plainKey = subapi_generate_plain_key($uid);
$record = subapi_create_key_record($uid, $plainKey, (string)($adminUser['uid'] ?? ''));

if (!subapi_store_key_record($uid, $record)) {
    api_response(false, 'SERVER_ERROR', 'Failed to create API key', [], 500);
}

admin_action_log('CREATE_SUBAPI_KEY', $uid, 'Admin created subadmin API key', [
    'uid' => $uid,
    'key_id' => (string)($record['key_id'] ?? ''),
    'role' => $role,
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

system_log('CREATE_SUBAPI_KEY', $uid, 'Admin created subadmin API key', [
    'uid' => $uid,
    'key_id' => (string)($record['key_id'] ?? ''),
    'role' => $role,
    'ip' => client_ip(),
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

api_response(true, 'SUCCESS', 'API key created successfully', [
    'uid' => $uid,
    'key_id' => (string)($record['key_id'] ?? ''),
    'key_mask' => (string)($record['key_mask'] ?? ''),
    'plain_key' => $plainKey,
    'status' => (string)($record['status'] ?? 'ACTIVE'),
    'created_at' => (int)($record['created_at'] ?? now_ts()),
]);