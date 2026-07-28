<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/views.php';

api_require_method('GET');
auth_require_admin_session(true);

$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_view_cursor_decode($_GET['cursor'] ?? '');

api_response(
    true,
    'ZNEWS_VIEW_RISK_QUEUE_OK',
    'View risk queue loaded.',
    znews_view_risk_queue($limit, $cursor)
);
