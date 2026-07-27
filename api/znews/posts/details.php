<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/post_media_attach.php';
require_once dirname(__DIR__) . '/lib/engagement.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');

$owned = znews_post_owner_snapshot($uid, $postId, false);
$post = znews_post_format_with_media((array)$owned['post'], true);

api_response(
    true,
    'ZNEWS_POST_DETAILS_OK',
    'Post loaded.',
    ['post' => znews_engagement_overlay($post)]
);
