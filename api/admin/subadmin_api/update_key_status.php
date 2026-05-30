<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$adminUser = $auth['user'] ?? [];

$body = api_read_json_body();

$uid = trim((string)($body['uid'] ?? ''));
$keyId = trim((string)($body['key_id'] ?? ''));
$status = subapi_normalize_status((string)($body['status'] ?? ''));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

if ($keyId === '') {
    api_response(false, 'VALIDATION_ERROR', 'key_id is required', ['field' => 'key_id'], 422);
}

if (!in_array($status, ['ACTIVE', 'DISABLED', 'REVOKED'], true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid status', ['field' => 'status'], 422);
}

$user = subapi_load_user($uid);
if (!$user) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$keyRow = fb_get('USER_API_KEYS/' . $uid . '/' . $keyId);
if (!is_array($keyRow)) {
    api_response(false, 'NOT_FOUND', 'API key not found', [], 404);
}

$ok = false;
if ($status === 'ACTIVE') {
    $ok = subapi_enable_key($uid, $keyId);
} elseif ($status === 'DISABLED') {
    $ok = subapi_disable_key($uid, $keyId);
} elseif ($status === 'REVOKED') {
    $ok = subapi_revoke_key($uid, $keyId);
}

if (!$ok) {
    api_response(false, 'SERVER_ERROR', 'Failed to update API key status', [], 500);
}

admin_action_log('UPDATE_SUBAPI_KEY_STATUS', $uid, 'Admin updated subadmin API key status', [
    'uid' => $uid,
    'key_id' => $keyId,
    'status' => $status,
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

system_log('UPDATE_SUBAPI_KEY_STATUS', $uid, 'Admin updated subadmin API key status', [
    'uid' => $uid,
    'key_id' => $keyId,
    'status' => $status,
    'ip' => client_ip(),
    'admin_uid' => (string)($adminUser['uid'] ?? ''),
]);

api_response(true, 'SUCCESS', 'API key status updated successfully', [
    'uid' => $uid,
    'key_id' => $keyId,
    'status' => $status,
    'updated_at' => now_ts(),
]);