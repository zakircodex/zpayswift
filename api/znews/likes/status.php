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

api_response(true, 'ZNEWS_LIKE_STATUS_OK', 'Like status loaded.', znews_like_status($postId, $uid));
