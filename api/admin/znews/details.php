<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 2) . '/znews/lib/moderation.php';

api_require_method('GET');
auth_require_admin_session(true);

$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
api_response(true, 'ZNEWS_ADMIN_POST_DETAILS_OK', 'Post details loaded.', znews_admin_post_details($postId));
