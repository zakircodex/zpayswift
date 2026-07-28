<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/likes.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
$status = znews_like_status($postId, $uid);

api_response(true, 'ZNEWS_ENGAGEMENT_SUMMARY_OK', 'Engagement summary loaded.', [
    'post_id' => $postId,
    'liked' => (bool)$status['liked'],
    'counts' => (array)$status['counts'],
]);
