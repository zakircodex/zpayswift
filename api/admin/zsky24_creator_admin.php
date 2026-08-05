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

// The dashboard token remains HttpOnly. Expose it only to the server-side auth layer.
$_SERVER['HTTP_X_SESSION_TOKEN'] = $sessionToken;
@session_write_close();

require_once dirname(__DIR__) . '/znews/bootstrap.php';
require_once dirname(__DIR__) . '/znews/lib/creator_registry.php';
require_once dirname(__DIR__) . '/znews/lib/creator_payout_batches.php';

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

zsky24_admin_gateway_response(false, 'NOT_FOUND', 'Unknown Z Sky creator admin action.', [], 404);
