<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/comments.php';

api_require_method('GET');
auth_require_admin_session(true);

$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_comment_cursor_decode($_GET['cursor'] ?? '');

api_response(true, 'ZNEWS_ADMIN_COMMENT_QUEUE_OK', 'Comment review queue loaded.', znews_admin_comment_queue(
    $limit,
    $cursor
));
