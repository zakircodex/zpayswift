<?php
declare(strict_types=1);

$weeklyRequestStarted = microtime(true);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/views.php';
require_once dirname(__DIR__) . '/lib/creator_weekly_reviews.php';

api_require_method('GET');

$weeklyStageStarted = microtime(true);
$auth = auth_require_user(false);
$weeklyTiming = [
    'auth_ms' => round((microtime(true) - $weeklyStageStarted) * 1000, 2),
];
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
$weeklyStageStarted = microtime(true);
$registry = fb_get(znews_creator_registry_path($uid));
if (!is_array($registry)) {
    $registry = znews_creator_registry_touch($auth);
}
$weeklyTiming['creator_lookup_ms'] = round((microtime(true) - $weeklyStageStarted) * 1000, 2);
$limit = znews_limit($_GET['limit'] ?? 6, 6, 12);
$cursor = znews_weekly_review_history_cursor($_GET['cursor'] ?? '');
$includeCurrent = !in_array(
    strtolower(trim((string)($_GET['include_current'] ?? '1'))),
    ['0', 'false', 'no'],
    true
);
$includeHistory = !in_array(
    strtolower(trim((string)($_GET['include_history'] ?? '1'))),
    ['0', 'false', 'no'],
    true
);
$refreshCurrent = in_array(
    strtolower(trim((string)($_GET['refresh_current'] ?? '0'))),
    ['1', 'true', 'yes'],
    true
);
$preview = ['review' => null];
if ($includeCurrent) {
    $preview = znews_weekly_review_creator_live_preview($uid, $registry, $refreshCurrent);
    $weeklyTiming = array_merge($weeklyTiming, (array)($preview['timing'] ?? []));
    if (empty($preview['ok'])) {
        api_response(
            false,
            (string)($preview['code'] ?? 'ZNEWS_WEEKLY_PREVIEW_FAILED'),
            'Weekly performance could not be calculated.',
            [],
            503
        );
    }
}
$weeklyStageStarted = microtime(true);
$history = $includeHistory
    ? znews_weekly_review_creator_history_page($uid, $limit, $cursor)
    : ['items' => [], 'next_cursor' => '', 'has_more' => false];
$weeklyTiming['history_ms'] = round((microtime(true) - $weeklyStageStarted) * 1000, 2);

$responseData = [
    'creator' => znews_creator_public_registry($registry),
    'current_preview' => $includeCurrent ? (array)$preview['review'] : null,
    'items' => $history['items'],
    'next_cursor' => $history['next_cursor'],
    'has_more' => $history['has_more'],
    'money_fields_present' => false,
];
$weeklyStageStarted = microtime(true);
json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$weeklyTiming['serialization_ms'] = round((microtime(true) - $weeklyStageStarted) * 1000, 2);
$weeklyTiming['server_total_ms'] = round((microtime(true) - $weeklyRequestStarted) * 1000, 2);

$serverTiming = [];
foreach ($weeklyTiming as $name => $duration) {
    $safeName = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string)$name));
    $serverTiming[] = 'znews_' . trim((string)$safeName, '_') . ';dur=' . max(0, (float)$duration);
}
header('Server-Timing: ' . implode(', ', $serverTiming));
if (getenv('ZNEWS_WEEKLY_TIMING_LOG') === '1') {
    error_log('ZNEWS_WEEKLY_TIMING:' . json_encode([
        'include_current' => $includeCurrent,
        'include_history' => $includeHistory,
        'cache_hit' => !empty($preview['cache_hit']),
        'firebase_reads' => max(0, (int)($preview['firebase_read_count'] ?? 0)) + 1,
        'timing_ms' => $weeklyTiming,
    ], JSON_UNESCAPED_SLASHES));
}

api_response(true, 'ZNEWS_WEEKLY_CREATOR_REVIEWS_OK', 'Weekly creator performance loaded.', $responseData);
