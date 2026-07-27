<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/post_media_attach.php';
require_once dirname(__DIR__) . '/lib/ad_impressions.php';

api_require_method('GET');
api_require_app_key();

$auth = znews_require_creator(true);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
$postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
znews_post_owner_snapshot($uid, $postId, false);

api_response(true, 'ZNEWS_AD_ANALYTICS_OK', 'Ad analytics loaded.', [
    'analytics' => znews_ad_analytics_get($postId),
    'settlement_enabled' => false,
    'credit_enabled' => false,
]);
