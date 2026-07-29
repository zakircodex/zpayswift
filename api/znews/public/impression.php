<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/posts.php';
require_once dirname(__DIR__) . '/lib/post_access.php';
require_once dirname(__DIR__) . '/lib/views.php';
require_once dirname(__DIR__) . '/lib/feed_ranking.php';

api_require_method('POST');
api_require_app_key();

$body = api_read_json_body();
$sessionId = trim((string)($body['feed_session_id'] ?? ''));
$postIds = is_array($body['post_ids'] ?? null) ? (array)$body['post_ids'] : [];

if ($sessionId === '') {
    api_response(false, 'ZNEWS_FEED_SESSION_REQUIRED', 'feed_session_id is required.', [], 422);
}
if (!$postIds) {
    api_response(false, 'ZNEWS_FEED_POST_IDS_REQUIRED', 'post_ids is required.', [], 422);
}

$result = znews_feed_record_impressions($sessionId, $postIds);
api_response(true, 'ZNEWS_FEED_IMPRESSIONS_RECORDED', 'Feed impressions recorded.', $result);
