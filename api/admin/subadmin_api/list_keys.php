<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session(true);

$uid = trim((string)($_GET['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'VALIDATION_ERROR', 'uid is required', ['field' => 'uid'], 422);
}

$user = subapi_load_user($uid);
if (!$user) {
    api_response(false, 'NOT_FOUND', 'User not found', [], 404);
}

$items = subapi_list_keys($uid);
$out = [];

foreach ($items as $keyId => $row) {
    if (!is_array($row)) {
        continue;
    }

    $out[] = [
        'key_id' => (string)($row['key_id'] ?? $keyId),
        'uid' => (string)($row['uid'] ?? $uid),
        'key_mask' => (string)($row['key_mask'] ?? ''),
        'status' => subapi_normalize_status((string)($row['status'] ?? 'ACTIVE')),
        'last_used_at' => (int)($row['last_used_at'] ?? 0),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'created_by_uid' => (string)($row['created_by_uid'] ?? ''),
    ];
}

usort($out, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

api_response(true, 'SUCCESS', 'API key list loaded', [
    'uid' => $uid,
    'items' => $out,
]);