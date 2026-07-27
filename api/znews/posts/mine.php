<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__) . '/lib/post_access.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_cursor_decode($_GET['cursor'] ?? '');
$includeDeleted = znews_bool($_GET['include_deleted'] ?? false, false);

$page = znews_owned_posts_page($uid, $limit, $cursor, $includeDeleted);

api_response(true, 'ZNEWS_MY_POSTS_OK', 'Posts loaded.', $page);
