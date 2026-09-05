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
require_once dirname(__DIR__) . '/znews/lib/creator_monthly_performance.php';
require_once dirname(__DIR__) . '/znews/lib/monthly_revenue.php';
require_once dirname(__DIR__) . '/znews/lib/monthly_creator_payouts.php';
require_once dirname(__DIR__) . '/znews/lib/moderation_media.php';
require_once dirname(__DIR__) . '/znews/lib/post_media_attach.php';
require_once dirname(__DIR__) . '/znews/lib/comments.php';

$auth = auth_require_admin_session(true);
$admin = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
$adminUid = trim((string)($admin['uid'] ?? ''));
$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($action === 'posts_queue') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Post moderation queue is GET-only.', [], 405);
    }
    $limit = max(1, min(10, (int)($_GET['limit'] ?? 10)));
    $cursor = trim((string)($_GET['cursor'] ?? ''));
    zsky24_admin_gateway_response(true, 'ZNEWS_MODERATION_QUEUE_OK', 'Post moderation queue loaded.', znews_admin_queue($limit, $cursor));
}

if ($action === 'post_details') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Post moderation details are GET-only.', [], 405);
    }
    $postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
    $data = znews_admin_post_details($postId);
    $rawPost = fb_get(znews_path_post($postId));
    if (is_array($rawPost)) {
        $data['post'] = znews_post_format_with_media($rawPost, true, true);
    }
    zsky24_admin_gateway_response(true, 'ZNEWS_ADMIN_POST_DETAILS_OK', 'Post moderation details loaded.', $data);
}

if ($action === 'comments_queue') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Comment moderation queue is GET-only.', [], 405);
    }
    $limit = max(1, min(10, (int)($_GET['limit'] ?? 10)));
    $cursor = znews_comment_cursor_decode($_GET['cursor'] ?? '');
    zsky24_admin_gateway_response(true, 'ZNEWS_ADMIN_COMMENT_QUEUE_OK', 'Comment moderation queue loaded.', znews_admin_comment_queue($limit, $cursor));
}

if ($action === 'comment_details') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Comment moderation details are GET-only.', [], 405);
    }
    $postId = znews_firebase_key($_GET['post_id'] ?? '', 'post_id');
    $commentId = znews_firebase_key($_GET['comment_id'] ?? '', 'comment_id');
    zsky24_admin_gateway_response(true, 'ZNEWS_ADMIN_COMMENT_DETAILS_OK', 'Comment details loaded.', znews_admin_comment_details($postId, $commentId));
}

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

if ($action === 'monthly_periods') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Monthly period list is GET-only.', [], 405);
    }
    $limit = max(1, min(24, (int)($_GET['limit'] ?? 12)));
    zsky24_admin_gateway_response(true, 'ZNEWS_MONTHLY_PERIODS_OK', 'Monthly performance periods loaded.', [
        'scheme' => znews_monthly_performance_scheme(),
        'calendar_review_scheme' => znews_calendar_review_scheme(),
        'default_month' => znews_monthly_performance_month(),
        'items' => znews_monthly_performance_catalog($limit),
        'read_only' => true,
    ]);
}

if ($action === 'monthly_preview') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Monthly performance preview is GET-only.', [], 405);
    }
    $monthId = trim((string)($_GET['month_id'] ?? ''));
    $result = znews_monthly_performance_preview($monthId);
    if (!empty($result['ok'])) {
        $result = znews_monthly_payout_enrich_performance($result);
    }
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_MONTHLY_PERFORMANCE_PREVIEW_FAILED'),
        !empty($result['ok']) ? 'Monthly creator performance preview loaded.' : 'Monthly creator performance preview could not be loaded.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 422))
    );
}

$raw = file_get_contents('php://input');
$body = trim((string)$raw) === '' ? [] : json_decode((string)$raw, true);
if (!is_array($body)) {
    zsky24_admin_gateway_response(false, 'INVALID_JSON', 'Request body must be valid JSON.', [], 400);
}

if ($action === 'revenue_status') {
    if ($method !== 'GET') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Revenue status is GET-only.', [], 405);
    }
    $monthId = trim((string)($_GET['month_id'] ?? ''));
    $result = znews_monthly_revenue_status($monthId);
    if (!empty($result['ok'])) {
        $resolvedMonth = (string)($result['month']['month_id'] ?? $monthId);
        $result['fx'] = [
            'USD_BDT' => znews_monthly_fx_public(znews_monthly_fx_get($resolvedMonth, 'BDT')),
            'USD_MYR' => znews_monthly_fx_public(znews_monthly_fx_get($resolvedMonth, 'MYR')),
        ];
    }
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_MONTHLY_REVENUE_STATUS_FAILED'),
        !empty($result['ok']) ? 'Monthly Adsterra revenue status loaded.' : 'Monthly revenue status could not be loaded.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 422))
    );
}

if ($action === 'post_decision') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Post moderation decision is POST-only.', [], 405);
    }
    $postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
    $expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? null, FILTER_VALIDATE_INT);
    if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
        zsky24_admin_gateway_response(false, 'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED', 'Post version is required.', [], 422);
    }
    $decision = strtoupper(trim((string)($body['decision'] ?? '')));
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
        zsky24_admin_gateway_response(false, 'ZNEWS_INVALID_ACTION', 'Approve or Reject is required.', [], 422);
    }
    $approve = $decision === 'APPROVE';
    $verdict = znews_copyright_verdict($body['copyright_verdict'] ?? '', $approve);
    $note = znews_moderation_note($body[$approve ? 'note' : 'reason'] ?? '', !$approve, $approve ? 1000 : 500);
    $idempotencyKey = znews_idempotency_key($body['idempotency_key'] ?? $body['client_request_id'] ?? '');
    $result = znews_admin_moderate_post_with_media(
        $auth,
        $postId,
        (int)$expectedUpdatedAt,
        $idempotencyKey,
        $decision,
        $verdict,
        $note
    );
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? (!empty($result['ok']) ? 'ZNEWS_POST_MODERATED' : 'ZNEWS_POST_MODERATION_FAILED')),
        !empty($result['ok']) ? ($approve ? 'Post approved.' : 'Post rejected.') : (string)($result['message'] ?? 'Post could not be moderated.'),
        array_filter([
            'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : null,
            'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
            'current_updated_at' => $result['data']['current_updated_at'] ?? null,
        ], static fn($value): bool => $value !== null),
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
    );
}

if ($action === 'comment_decision') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Comment moderation decision is POST-only.', [], 405);
    }
    $postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
    $commentId = znews_firebase_key($body['comment_id'] ?? '', 'comment_id');
    $expectedUpdatedAt = filter_var($body['expected_updated_at'] ?? null, FILTER_VALIDATE_INT);
    if ($expectedUpdatedAt === false || $expectedUpdatedAt <= 0) {
        zsky24_admin_gateway_response(false, 'ZNEWS_EXPECTED_UPDATED_AT_REQUIRED', 'Comment version is required.', [], 422);
    }
    $decision = strtoupper(trim((string)($body['decision'] ?? '')));
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
        zsky24_admin_gateway_response(false, 'ZNEWS_INVALID_ACTION', 'Approve or Reject is required.', [], 422);
    }
    $approve = $decision === 'APPROVE';
    $note = znews_comment_moderation_note($body[$approve ? 'note' : 'reason'] ?? '', !$approve);
    $idempotencyKey = znews_idempotency_key($body['idempotency_key'] ?? $body['client_request_id'] ?? '');
    $result = znews_admin_moderate_comment(
        $auth,
        $postId,
        $commentId,
        (int)$expectedUpdatedAt,
        $idempotencyKey,
        $decision,
        $note
    );
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? (!empty($result['ok']) ? 'ZNEWS_COMMENT_MODERATED' : 'ZNEWS_COMMENT_MODERATION_FAILED')),
        !empty($result['ok']) ? ($approve ? 'Comment approved.' : 'Comment rejected.') : (string)($result['message'] ?? 'Comment could not be moderated.'),
        array_filter([
            'comment' => is_array($result['comment'] ?? null) ? (array)$result['comment'] : null,
            'idempotent_replay' => isset($result['idempotent_replay']) ? (bool)$result['idempotent_replay'] : null,
            'details' => is_array($result['data'] ?? null) ? (array)$result['data'] : null,
        ], static fn($value): bool => $value !== null),
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500))
    );
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
    $monthId = trim((string)($body['month_id'] ?? ''));
    $result = $monthId !== ''
        ? znews_monthly_payout_preflight($monthId, $creatorUids)
        : znews_creator_payout_batch_preflight($creatorUids);
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_PAYOUT_PREFLIGHT_FAILED'),
        (string)($result['message'] ?? 'Payout preflight failed.'),
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 422))
    );
}

if ($action === 'revenue_sync') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Revenue sync is POST-only.', [], 405);
    }
    $monthId = trim((string)($body['month_id'] ?? ''));
    $result = znews_monthly_revenue_sync($monthId, $adminUid);
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_ADSTERRA_REVENUE_SYNC_FAILED'),
        !empty($result['ok']) ? 'Adsterra revenue synchronized.' : 'Adsterra revenue could not be synchronized.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 503))
    );
}

if ($action === 'revenue_lock') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Revenue lock is POST-only.', [], 405);
    }
    if (!hash_equals('LOCK_REVENUE', strtoupper(trim((string)($body['confirmation'] ?? ''))))) {
        zsky24_admin_gateway_response(false, 'ZNEWS_REVENUE_LOCK_CONFIRMATION_REQUIRED', 'Explicit revenue lock confirmation is required.', [], 422);
    }
    $result = znews_monthly_revenue_lock(
        trim((string)($body['month_id'] ?? '')),
        trim((string)($body['sync_id'] ?? '')),
        $adminUid
    );
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_REVENUE_LOCK_FAILED'),
        !empty($result['ok']) ? 'Final Adsterra revenue locked.' : 'Final revenue could not be locked.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 503))
    );
}

if ($action === 'payout_fx_lock') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Payout FX lock is POST-only.', [], 405);
    }
    if (!hash_equals('LOCK_FX', strtoupper(trim((string)($body['confirmation'] ?? ''))))) {
        zsky24_admin_gateway_response(false, 'ZNEWS_PAYOUT_FX_CONFIRMATION_REQUIRED', 'Explicit FX lock confirmation is required.', [], 422);
    }
    $result = znews_monthly_fx_lock(
        trim((string)($body['month_id'] ?? '')),
        trim((string)($body['currency'] ?? '')),
        trim((string)($body['rate'] ?? '')),
        trim((string)($body['source_reference'] ?? '')),
        (int)($body['rate_timestamp'] ?? 0),
        $adminUid
    );
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_PAYOUT_FX_LOCK_FAILED'),
        !empty($result['ok']) ? 'Payout FX rate locked.' : 'Payout FX rate could not be locked.',
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 503))
    );
}

if ($action === 'payout_execute') {
    if ($method !== 'POST') {
        zsky24_admin_gateway_response(false, 'METHOD_NOT_ALLOWED', 'Payout execution is POST-only.', [], 405);
    }
    if (!hash_equals('EXECUTE_PAYOUT', strtoupper(trim((string)($body['confirmation'] ?? ''))))) {
        zsky24_admin_gateway_response(false, 'ZNEWS_PAYOUT_CONFIRMATION_REQUIRED', 'Explicit payout confirmation is required.', [], 422);
    }
    $creatorUids = is_array($body['creator_uids'] ?? null) ? (array)$body['creator_uids'] : [];
    $result = znews_monthly_payout_execute(
        trim((string)($body['month_id'] ?? '')),
        $creatorUids,
        znews_idempotency_key($body['idempotency_key'] ?? $body['client_request_id'] ?? ''),
        $adminUid
    );
    zsky24_admin_gateway_response(
        !empty($result['ok']),
        (string)($result['code'] ?? 'ZNEWS_PAYOUT_BATCH_FAILED'),
        (string)($result['message'] ?? (!empty($result['ok']) ? 'Creator payout completed.' : 'Creator payout failed.')),
        $result,
        (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 503))
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
