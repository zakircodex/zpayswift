<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/comments.php';

api_require_method('GET');
auth_require_admin_session(true);

$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
$commentId = znews_firebase_key($_GET['comment_id'] ?? '', 'comment_id');

api_response(
    true,
    'ZNEWS_ADMIN_COMMENT_DETAILS_OK',
    'Comment details loaded.',
    znews_admin_comment_details($postId, $commentId)
);
