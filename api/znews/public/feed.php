<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__) . '/lib/post_access.php';

api_require_method('GET');

$limit = znews_limit($_GET['limit'] ?? 20, 20, 30);
$cursor = znews_cursor_decode($_GET['cursor'] ?? '');
$page = znews_public_feed_page($limit, $cursor);

api_response(true, 'ZNEWS_PUBLIC_FEED_OK', 'Feed loaded.', $page);
