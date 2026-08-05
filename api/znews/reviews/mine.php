<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views.php';
require_once dirname(__DIR__) . '/lib/creator_weekly_reviews.php';

api_require_method('GET');

$auth = auth_require_user(false);
$user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$role = strtoupper(trim((string)($user['role'] ?? '')));
if ($uid === '') {
    api_response(false, 'ZNEWS_AUTH_REQUIRED', 'Authentication required.', [], 401);
}
if (!in_array($role, ['USER', 'RETAILER'], true)) {
    api_response(false, 'ZNEWS_ROLE_NOT_ALLOWED', 'This account cannot use Z Sky 24 creator tools.', [], 403);
}

$uid = znews_firebase_key($uid, 'creator_uid');
$registry = znews_creator_registry_touch($auth);
$limit = znews_limit($_GET['limit'] ?? 12, 12, 52);
$preview = znews_weekly_review_creator_live_preview($uid);
if (empty($preview['ok'])) {
    api_response(
        false,
        (string)($preview['code'] ?? 'ZNEWS_WEEKLY_PREVIEW_FAILED'),
        'Weekly performance could not be calculated.',
        [],
        503
    );
}

api_response(true, 'ZNEWS_WEEKLY_CREATOR_REVIEWS_OK', 'Weekly creator performance loaded.', [
    'creator' => znews_creator_public_registry($registry),
    'current_preview' => (array)$preview['review'],
    'items' => znews_weekly_review_creator_history($uid, $limit),
    'money_fields_present' => false,
]);
