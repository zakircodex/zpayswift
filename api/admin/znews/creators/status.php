<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();

$uid = znews_firebase_key($body['creator_uid'] ?? $body['uid'] ?? '', 'creator_uid');
$status = strtoupper(trim((string)($body['status'] ?? '')));
if (!in_array($status, ['ACTIVE', 'BLOCKED'], true)) {
    api_response(false, 'ZNEWS_CREATOR_STATUS_INVALID', 'status must be ACTIVE or BLOCKED.', [], 422);
}
$reason = znews_normalize_text($body['reason'] ?? '');
if ($status === 'BLOCKED' && $reason === '') {
    api_response(false, 'ZNEWS_CREATOR_BLOCK_REASON_REQUIRED', 'A block reason is required.', [], 422);
}
$adminUser = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$adminUid = trim((string)($adminUser['uid'] ?? $adminUser['id'] ?? 'ADMIN'));

$result = znews_creator_registry_set_status($uid, $status, $reason, $adminUid);
api_response(
    !empty($result['ok']),
    (string)($result['code'] ?? (!empty($result['ok']) ? 'ZNEWS_CREATOR_STATUS_UPDATED' : 'ZNEWS_CREATOR_STATUS_UPDATE_FAILED')),
    !empty($result['ok'])
        ? ($status === 'ACTIVE' ? 'Creator activated.' : 'Creator blocked.')
        : 'Creator status could not be updated.',
    array_filter([
        'creator' => is_array($result['creator'] ?? null) ? (array)$result['creator'] : null,
        'index_reconciliation_required' => isset($result['index_reconciliation_required'])
            ? (bool)$result['index_reconciliation_required']
            : null,
    ], static fn($value) => $value !== null),
    (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
);
