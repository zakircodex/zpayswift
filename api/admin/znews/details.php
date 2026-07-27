<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 2) . '/znews/lib/moderation.php';
require_once dirname(__DIR__, 2) . '/znews/lib/post_media_attach.php';

api_require_method('GET');
auth_require_admin_session(true);

$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
$data = znews_admin_post_details($postId);
$rawPost = fb_get(znews_path_post($postId));

if (is_array($rawPost)) {
    $data['post'] = znews_post_format_with_media($rawPost, true, true);
}

api_response(
    true,
    'ZNEWS_ADMIN_POST_DETAILS_OK',
    'Post moderation details loaded.',
    $data
);
