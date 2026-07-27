<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/ad_impressions.php';

api_require_method('GET');
auth_require_admin_session(true);

$limit = znews_limit($_GET['limit'] ?? 20, 20, 50);
$cursor = znews_ad_queue_cursor_decode($_GET['cursor'] ?? '');

api_response(
    true,
    'ZNEWS_AD_REVIEW_QUEUE_OK',
    'Ad impression review queue loaded.',
    znews_ad_review_queue($limit, $cursor)
);
