<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/post_access.php';
require_once dirname(__DIR__) . '/lib/views.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');

znews_post_owner_snapshot($uid, $postId, true);
api_response(true, 'ZNEWS_POST_ANALYTICS_OK', 'Post analytics loaded.', [
    'analytics' => znews_view_analytics_get($postId),
]);
