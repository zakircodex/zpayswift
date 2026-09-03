<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__) . '/lib/post_access.php';
require_once dirname(__DIR__) . '/lib/engagement.php';
require_once dirname(__DIR__) . '/lib/views.php';
require_once dirname(__DIR__) . '/lib/feed_ranking.php';

api_require_method('GET');

$limit = znews_limit($_GET['limit'] ?? 20, 20, 20);
$category = znews_normalize_category($_GET['category'] ?? '', true);
$page = znews_fair_feed_page($limit, $_GET['cursor'] ?? '', $category);

api_response(true, 'ZNEWS_PUBLIC_FEED_OK', 'Feed loaded.', $page);
