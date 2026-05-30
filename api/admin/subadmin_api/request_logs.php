<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session(true);

$uid = trim((string)($_GET['uid'] ?? ''));
$limit = (int)($_GET['limit'] ?? 100);

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

if ($limit <= 0) {
    $limit = 100;
}
if ($limit > 500) {
    $limit = 500;
}

$user = subapi_load_user($uid);
if (!$user) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$role = strtoupper(trim((string)($user['role'] ?? 'USER')));
if (!subapi_allowed_role($role)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'Only SUBADMIN or ADMIN request logs are supported', [], 422);
}

$items = subapi_list_request_logs($uid);
$out = [];

foreach ($items as $requestId => $row) {
    if (!is_array($row)) {
        continue;
    }

    $out[] = [
        'request_id' => (string)($row['request_id'] ?? $requestId),
        'uid' => (string)($row['uid'] ?? $uid),
        'key_id' => (string)($row['key_id'] ?? ''),
        'action' => (string)($row['action'] ?? ''),
        'request_type' => (string)($row['request_type'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'operator' => (string)($row['operator'] ?? ''),
        'topup_number' => (string)($row['topup_number'] ?? $row['number'] ?? ''),
        'amount' => (float)($row['amount'] ?? 0),
        'message' => (string)($row['message'] ?? ''),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'raw' => $row,
    ];
}

usort($out, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

if (count($out) > $limit) {
    $out = array_slice($out, 0, $limit);
}

api_response(true, 'SUCCESS', 'API request logs loaded', [
    'uid' => $uid,
    'items' => array_values($out),
]);