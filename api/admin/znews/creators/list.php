<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';

api_require_method('GET');
auth_require_admin_session(true);

$status = strtoupper(trim((string)($_GET['status'] ?? 'ACTIVE')));
if (!in_array($status, ['ACTIVE', 'BLOCKED'], true)) {
    api_response(false, 'ZNEWS_CREATOR_STATUS_INVALID', 'status must be ACTIVE or BLOCKED.', [], 422);
}
$limit = znews_limit($_GET['limit'] ?? 50, 50, 100);

api_response(
    true,
    'ZNEWS_CREATOR_LIST_OK',
    $status === 'ACTIVE' ? 'Active creators loaded.' : 'Blocked creators loaded.',
    znews_creator_registry_list($status, $limit)
);
