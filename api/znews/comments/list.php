<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/comments.php';

api_require_method('GET');

$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_comment_cursor_decode($_GET['cursor'] ?? '');

api_response(true, 'ZNEWS_COMMENTS_OK', 'Comments loaded.', znews_public_comments_page(
    $postId,
    $limit,
    $cursor
));
