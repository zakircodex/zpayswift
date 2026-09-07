<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views.php';
require_once dirname(__DIR__) . '/lib/feed_ranking.php';
require_once dirname(__DIR__) . '/lib/adsterra_web_ads.php';

api_require_method('POST');
$body = api_read_json_body();

$sessionId = znews_firebase_key($body['feed_session_id'] ?? '', 'feed_session_id');
$postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
$viewer = znews_feed_viewer_context();
$feedSession = znews_feed_load_session($sessionId, $viewer);
$allowedPosts = array_fill_keys(znews_feed_session_order($feedSession), true);
if (!isset($allowedPosts[$postId])) {
    api_response(false, 'ZNEWS_FEED_AD_POST_FORBIDDEN', 'This post is not part of the active feed.', [], 403);
}

$post = znews_view_require_public_post($postId);
$viewerUid = znews_optional_creator_uid();
$creatorUid = trim((string)($post['creator_uid'] ?? ''));
$selfView = $viewerUid !== '' && $creatorUid !== '' && hash_equals($viewerUid, $creatorUid);
$context = znews_view_context();
$android = str_starts_with(
    strtolower(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))),
    'zpayswift-android-znews/'
);
$eligible = !$android && !$selfView && empty($context['bot']);
$viewerClass = $android ? 'ANDROID_APP' : ($viewerUid !== '' ? 'CREATOR' : 'GUEST');
$reason = $android
    ? 'ANDROID_APP_NO_ADS'
    : ($selfView ? 'SELF_VIEW_NO_ADS' : (!empty($context['bot']) ? 'AD_POLICY_NOT_ELIGIBLE' : ''));
$syntheticViewId = 'ZFA' . strtoupper(substr(hash(
    'sha256',
    $sessionId . '|' . $postId . '|' . (string)$context['fingerprint']
), 0, 29));
$deliverySession = [
    'view_id' => $syntheticViewId,
    'post_id' => $postId,
    'viewer_uid' => $viewerUid,
    'fingerprint_hash' => (string)$context['fingerprint'],
    'viewer_class' => $viewerClass,
    'ad_eligible' => $eligible,
    'ad_block_reason' => $reason,
    'self_view' => $selfView,
    'bot_detected' => !empty($context['bot']),
];
$adDelivery = znews_adsterra_web_delivery_after_dwell(
    $deliverySession,
    'post_inline',
    null,
    false
);

api_response(true, 'ZNEWS_FEED_AD_DELIVERY_READY', 'Feed ad delivery evaluated.', [
    'ad_delivery' => $adDelivery,
]);
