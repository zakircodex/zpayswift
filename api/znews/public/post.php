<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__) . '/lib/post_access.php';
require_once dirname(__DIR__) . '/lib/engagement.php';

api_require_method('GET');

$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
$post = znews_public_post_by_id($postId);

if (!is_array($post)) {
    api_response(false, 'ZNEWS_POST_NOT_FOUND', 'Post not found.', [], 404);
}

api_response(true, 'ZNEWS_PUBLIC_POST_OK', 'Post loaded.', [
    'post' => znews_engagement_overlay($post),
]);
