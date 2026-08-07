<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function zsky24_admin_gateway_response(
    bool $ok,
    string $code,
    string $message,
    array $data = [],
    int $httpStatus = 200
): void {
    http_response_code($httpStatus);
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zsky24_admin_gateway_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_name('zawtopup_admin_v3');
    session_start();
}

$sessionToken = trim((string)($_SESSION['admin_session_token'] ?? ''));
$sessionExpiresAt = (int)($_SESSION['admin_session_expires_at'] ?? 0);
$storedCsrf = trim((string)($_SESSION['admin_csrf'] ?? ''));

if ($sessionToken === '' || $sessionExpiresAt <= time()) {
    zsky24_admin_gateway_response(false, 'SESSION_EXPIRED', 'Admin session expired.', [], 401);
}

$method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
if (!in_array($method, ['GET', 'POST'], true)) {
    zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method.', [], 405);
}

if ($method === 'POST') {
    $providedCsrf = zsky24_admin_gateway_header('X-CSRF-TOKEN');
    if ($storedCsrf === '' || $providedCsrf === '' || !hash_equals($storedCsrf, $providedCsrf)) {
        zsky24_admin_gateway_response(false, 'CSRF_INVALID', 'Security token mismatch.', [], 403);
    }
}

// Keep the protected dashboard token server-side; never expose it to browser JavaScript.
$_SERVER['HTTP_X_SESSION_TOKEN'] = $sessionToken;
@session_write_close();

require_once dirname(__DIR__) . '/znews/bootstrap.php';
require_once dirname(__DIR__) . '/znews/lib/views.php';
require_once dirname(__DIR__) . '/znews/lib/creator_registry.php';
require_once dirname(__DIR__) . '/znews/lib/creator_payout_batches.php';
require_once dirname(__DIR__) . '/znews/lib/creator_weekly_reviews.php';
require_once dirname(__DIR__) . '/znews/lib/creator_calendar_reviews.php';

$auth = auth_require_admin_session(true);
$admin = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$adminUid = trim((string)($admin['uid'] ?? ''));
$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($action === 'creators_list') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Creator list is GET-only.', [], 405);
    }
    $status = strtoupper(trim((string)($_GET['status'] ?? 'ACTIVE')));
    if (!in_array($status, ['ACTIVE', 'BLOCKED'], true)) {
        zsky24_admin_gateway_response(false, 'ZNEWS_CREATOR_STATUS_INVALID', 'Invalid creator status.', [], 422);
    }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 100)));
    zsky24_admin_gateway_response(
        true,
        'ZNEWS_CREATOR_LIST_OK',
        'Creator list loaded.',
        znews_creator_registry_list($status, $limit)
    );
}

if ($action === 'weekly_periods') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Weekly period list is GET-only.', [], 405);
    }
    $limit = max(4, min(36, (int)($_GET['limit'] ?? 16)));
    $current = znews_calendar_review_period();
    zsky24_admin_gateway_response(true, 'ZNEWS_CALENDAR_PERIODS_OK', 'Calendar review periods loaded.', [
        'scheme' => znews_calendar_review_scheme(),
        'rule' => '01-07, 08-14, 15-21, 22-end of month',
        'default_period' => $current,
        'items' => znews_calendar_review_catalog($limit),
    ]);
}

if ($action === 'weekly_review') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Weekly review is GET-only.', [], 405);
    }
    $periodId = trim((string)($_GET['period_id'] ?? ''));
    if ($periodId === '') {
        $default = znews_calendar_review_period();
        $periodId = (string)($default['period_id'] ?? '');
    }
    $result = znews_calendar_review_get_period($periodId);
    $live = !empty($result['period']['live_preview']) || strtoupper((string)($result['period']['lifecycle_status'] ?? '')) === 'LIVE';
    $upcoming = strtoupper((string)($result['period']['lifecycle_status'] ?? '')) === 'UPCOMING';
    $message = $live
        ? 'Current calendar period preview loaded.'
        : ($upcoming ? 'Upcoming calendar period loaded.' : (!empty($result['ok']) ? 'Calendar review loaded.' : 'Calendar review has not been generated.'));
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? (!empty($result['ok']) ? 'ZNEWS_CALENDAR_REVIEW_OK' : 'ZNEWS_CALENDAR_REVIEW_FAILED')),
        $message,
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 404))
    );
}

$raw = file_get_contents('php://input');
$body = trim((string)$raw) === '' ? [] : json_decode((string)$raw, true);
if (!is_array($body)) {
    zsky24_admin_gateway_response(false, 'INVALID_JSON', 'Request body must be valid JSON.', [], 400);
}

if ($action === 'creator_status') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Creator status is POST-only.', [], 405);
    }
    $uid = trim((string)($body['creator_uid'] ?? $body['uid'] ?? ''));
    $status = strtoupper(trim((string)($body['status'] ?? '')));
    $reason = trim((string)($body['reason'] ?? ''));
    if ($uid === '' || !in_array($status, ['ACTIVE', 'BLOCKED'], true)) {
        zsky24_admin_gateway_response(false, 'ZNEWS_CREATOR_STATUS_INVALID', 'Creator and status are required.', [], 422);
    }
    if ($status === 'BLOCKED' && $reason === '') {
        zsky24_admin_gateway_response(false, 'ZNEWS_CREATOR_BLOCK_REASON_REQUIRED', 'A block reason is required.', [], 422);
    }

    $result = znews_creator_registry_set_status($uid, $status, $reason, $adminUid);
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? (!empty($result['ok']) ? 'ZNEWS_CREATOR_STATUS_UPDATED' : 'ZNEWS_CREATOR_STATUS_FAILED')),
        !empty($result['ok']) ? 'Creator status updated.' : 'Creator status could not be updated.',
        array_filter([
            'creator' => is_array($result['creator'] ?? null) ? (array)$result['creator'] : null,
            'index_reconciliation_required' => isset($result['index_reconciliation_required'])
                ? (bool)$result['index_reconciliation_required']
                : null,
        ], static fn($value): bool => $value !== null),
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
    );
}

if ($action === 'payout_preflight') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Payout preflight is POST-only.', [], 405);
    }
    $creatorUids = is_array($body['creator_uids'] ?? null) ? (array)$body['creator_uids'] : [];
    $result = znews_creator_payout_batch_preflight($creatorUids);
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_PAYOUT_PREFLIGHT_FAILED'),
        (string)($result['message'] ?? 'Payout preflight failed.'),
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 422))
    );
}

if ($action === 'weekly_generate') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Weekly review generation is POST-only.', [], 405);
    }
    $periodId = trim((string)($body['period_id'] ?? ''));
    if ($periodId === '') {
        $default = znews_calendar_review_period('', null, 'PREVIOUS_COMPLETED');
        $periodId = (string)($default['period_id'] ?? '');
    }
    $result = znews_calendar_review_generate($periodId, $adminUid);
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_CALENDAR_REVIEW_GENERATE_FAILED'),
        !empty($result['ok']) ? 'Calendar creator review generated.' : 'Calendar creator review could not be generated.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
    );
}

if ($action === 'weekly_status') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Weekly review status is POST-only.', [], 405);
    }
    $periodId = trim((string)($body['period_id'] ?? ''));
    $creatorUid = trim((string)($body['creator_uid'] ?? ''));
    $status = strtoupper(trim((string)($body['status'] ?? '')));
    $reason = trim((string)($body['reason'] ?? ''));
    if ($periodId === '' || $creatorUid === '') {
        zsky24_admin_gateway_response(false, 'ZNEWS_WEEKLY_REVIEW_TARGET_REQUIRED', 'Period and creator are required.', [], 422);
    }
    $result = znews_calendar_review_set_status($periodId, $creatorUid, $status, $reason, $adminUid);
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? (!empty($result['ok']) ? 'ZNEWS_CALENDAR_REVIEW_UPDATED' : 'ZNEWS_CALENDAR_REVIEW_UPDATE_FAILED')),
        !empty($result['ok']) ? 'Calendar creator review updated.' : 'Calendar creator review could not be updated.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
    );
}

zsky24_admin_gateway_response(false, 'NOT_FOUND', 'Unknown Z Sky creator admin action.', [], 404);
