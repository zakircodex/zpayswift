<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function wallet_ledger_response(bool $ok, string $code, string $message, array $data = [], int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wallet_ledger_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        wallet_ledger_response(false, 'METHOD_NOT_ALLOWED', 'Invalid HTTP method', [], 405);
    }
}

function wallet_ledger_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

function wallet_ledger_host(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

function wallet_ledger_api_base_url(): string
{
    if (function_exists('app_api_url')) {
        return rtrim(app_api_url(), '/');
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/wallet_ledger_list.php';
    $apiPath = dirname($script);
    return rtrim(wallet_ledger_scheme() . '://' . wallet_ledger_host() . $apiPath, '/');
}

function wallet_ledger_internal_api_request(string $method, string $relativePath, ?array $body = null, array $headers = []): array
{
    $url = wallet_ledger_api_base_url() . '/' . ltrim($relativePath, '/');

    $ch = curl_init();
    $finalHeaders = ['Accept: application/json'];

    foreach ($headers as $k => $v) {
        $finalHeaders[] = $k . ': ' . $v;
    }

    if ($body !== null) {
        $finalHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $finalHeaders,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => 0,
            'json' => null,
            'error' => $err ?: 'Unknown cURL error',
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'status' => $status,
            'json' => null,
            'error' => 'Invalid JSON response from internal API',
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300 && !empty($json['ok']),
        'status' => $status,
        'json' => $json,
        'error' => null,
    ];
}

function wallet_ledger_extract_session_token(): string
{
    $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

function wallet_ledger_require_actor(): array
{
    $sessionToken = wallet_ledger_extract_session_token();
    if ($sessionToken === '') {
        wallet_ledger_response(false, 'UNAUTHORIZED', 'Session token is required', [], 401);
    }

    $res = wallet_ledger_internal_api_request('GET', 'auth/session.php', null, [
        'X-APP-KEY' => APP_KEY,
        'X-SESSION-TOKEN' => $sessionToken,
    ]);

    if (!$res['ok']) {
        $json = $res['json'] ?? [];
        wallet_ledger_response(
            false,
            (string)($json['code'] ?? 'SESSION_EXPIRED'),
            (string)($json['message'] ?? 'Session expired'),
            [],
            $res['status'] > 0 ? $res['status'] : 401
        );
    }

    $actor = (array)($res['json']['data'] ?? []);
    $role = strtoupper(trim((string)($actor['role'] ?? '')));
    $status = strtoupper(trim((string)($actor['status'] ?? 'INACTIVE')));

    if (!in_array($role, ['SUBADMIN', 'ADMIN'], true)) {
        wallet_ledger_response(false, 'FORBIDDEN', 'Only ADMIN or SUBADMIN can view wallet ledger', [], 403);
    }

    if ($status !== 'ACTIVE') {
        wallet_ledger_response(false, 'FORBIDDEN', 'Account is inactive', [], 403);
    }

    return $actor;
}

function wallet_ledger_actor_can_access_target(array $actor, array $target): bool
{
    $actorRole = strtoupper(trim((string)($actor['role'] ?? '')));
    $actorUid  = trim((string)($actor['uid'] ?? ''));

    if ($actorRole === 'ADMIN') {
        return true;
    }

    if ($actorRole === 'SUBADMIN') {
        $parent    = trim((string)($target['parent_subadmin_uid'] ?? ''));
        $createdBy = trim((string)($target['created_by_uid'] ?? ''));
        return $parent === $actorUid || $createdBy === $actorUid;
    }

    return false;
}

function wallet_ledger_cursor_decode(string $cursor): array
{
    $cursor = trim($cursor);
    if ($cursor === '' || preg_match('/^[A-Za-z0-9_-]{1,1024}$/', $cursor) !== 1) {
        return ['month' => '', 'row_cursor' => ''];
    }
    $padding = strlen($cursor) % 4;
    if ($padding > 0) $cursor .= str_repeat('=', 4 - $padding);
    $json = base64_decode(strtr($cursor, '-_', '+/'), true);
    $row = is_string($json) ? json_decode($json, true) : null;
    $month = is_array($row) ? trim((string)($row['month'] ?? '')) : '';
    $rowCursor = is_array($row) ? trim((string)($row['row_cursor'] ?? '')) : '';
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) $month = '';
    if ($rowCursor !== '' && preg_match('/^[A-Za-z0-9_-]{1,512}$/', $rowCursor) !== 1) $rowCursor = '';
    return ['month' => $month, 'row_cursor' => $rowCursor];
}

function wallet_ledger_cursor_encode(string $month, string $rowCursor): string
{
    $json = json_encode(['month' => $month, 'row_cursor' => $rowCursor], JSON_UNESCAPED_SLASHES);
    return is_string($json) ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=') : '';
}

function wallet_ledger_subadmin_page(string $uid, string $cursor = '', int $limit = 10): array
{
    $limit = min(10, max(1, $limit));
    $monthRows = fb_get('WALLET_LEDGER/' . $uid, ['shallow' => 'true']);
    $months = array_values(array_filter(array_map('strval', array_keys(is_array($monthRows) ? $monthRows : [])),
        static fn(string $month): bool => preg_match('/^\d{4}-\d{2}$/', $month) === 1
    ));
    rsort($months, SORT_STRING);
    $state = wallet_ledger_cursor_decode($cursor);
    $monthIndex = 0;
    if ($state['month'] !== '') {
        $found = array_search($state['month'], $months, true);
        if ($found !== false) $monthIndex = (int)$found;
    }

    $items = [];
    $nextCursor = '';
    $rowCursor = (string)$state['row_cursor'];
    while ($monthIndex < count($months) && count($items) < $limit) {
        $month = $months[$monthIndex];
        $remaining = $limit - count($items);
        $page = admin_firebase_cursor_page(
            'WALLET_LEDGER/' . $uid . '/' . $month,
            $remaining,
            $rowCursor,
            null,
            static function (array $row, string $ledgerId) use ($uid, $month): array {
                return [
                    'ledger_id' => (string)($row['ledger_id'] ?? $ledgerId),
                    'uid' => (string)($row['uid'] ?? $uid),
                    'type' => (string)($row['type'] ?? ''),
                    'direction' => (string)($row['direction'] ?? ''),
                    'amount' => (float)($row['amount'] ?? 0),
                    'currency' => (string)($row['currency'] ?? 'BDT'),
                    'before_available' => (float)($row['before_available'] ?? 0),
                    'after_available' => (float)($row['after_available'] ?? 0),
                    'before_hold' => (float)($row['before_hold'] ?? 0),
                    'after_hold' => (float)($row['after_hold'] ?? 0),
                    'ref_id' => (string)($row['ref_id'] ?? ''),
                    'note' => (string)($row['note'] ?? ''),
                    'created_at' => (int)($row['created_at'] ?? 0),
                    'created_by_uid' => (string)($row['created_by_uid'] ?? ''),
                    'created_by_role' => (string)($row['created_by_role'] ?? ''),
                    'month_bucket' => $month,
                ];
            }
        );
        $items = array_merge($items, (array)($page['items'] ?? []));

        if (!empty($page['pagination']['has_more'])) {
            $nextCursor = wallet_ledger_cursor_encode($month, (string)($page['pagination']['next_cursor'] ?? ''));
            break;
        }

        $monthIndex++;
        $rowCursor = '';
        if ($monthIndex < count($months)) {
            $nextCursor = wallet_ledger_cursor_encode($months[$monthIndex], '');
        }
    }

    return ['items' => array_values($items), 'pagination' => [
        'limit' => $limit,
        'count' => count($items),
        'has_more' => $nextCursor !== '',
        'cursor' => $cursor,
        'next_cursor' => $nextCursor,
    ]];
}

wallet_ledger_require_method('GET');
$actor = wallet_ledger_require_actor();

$targetUid = trim((string)($_GET['uid'] ?? ''));
$limit = (int)($_GET['limit'] ?? 100);
$cursor = trim((string)($_GET['cursor'] ?? ''));

if ($targetUid === '') {
    wallet_ledger_response(false, 'VALIDATION_ERROR', 'Target user ID is required', [], 422);
}

if ($limit <= 0) $limit = 100;
if ($limit > 500) $limit = 500;

$targetUser = fb_get('USERS/' . $targetUid);
if (!is_array($targetUser)) {
    wallet_ledger_response(false, 'NOT_FOUND', 'Target user not found', [], 404);
}

$targetRole = strtoupper(trim((string)($targetUser['role'] ?? '')));
if (!in_array($targetRole, ['USER', 'RETAILER'], true)) {
    wallet_ledger_response(false, 'FORBIDDEN', 'Only USER or RETAILER ledger can be viewed here', [], 403);
}

if (!wallet_ledger_actor_can_access_target($actor, $targetUser)) {
    wallet_ledger_response(false, 'FORBIDDEN', 'You cannot access this account', [], 403);
}

if (strtoupper(trim((string)($actor['role'] ?? ''))) === 'SUBADMIN') {
    $page = wallet_ledger_subadmin_page($targetUid, $cursor, min(10, max(1, $limit)));
    $wallet = fb_get('USER_WALLETS/' . $targetUid);
    $wallet = is_array($wallet) ? $wallet : [];
    wallet_ledger_response(true, 'SUCCESS', 'Wallet ledger loaded successfully', [
        'target_uid' => $targetUid,
        'target_name' => (string)($targetUser['name'] ?? ''),
        'target_phone' => (string)($targetUser['phone'] ?? ''),
        'target_role' => (string)($targetUser['role'] ?? ''),
        'available_balance' => (float)($wallet['available_balance'] ?? 0),
        'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
        'items' => (array)($page['items'] ?? []),
        'pagination' => (array)($page['pagination'] ?? []),
    ]);
}

$allMonths = fb_get('WALLET_LEDGER/' . $targetUid);
$allMonths = is_array($allMonths) ? $allMonths : [];

$items = [];

foreach ($allMonths as $month => $rows) {
    if (!is_array($rows)) continue;

    foreach ($rows as $ledgerId => $row) {
        if (!is_array($row)) continue;

        $items[] = [
            'ledger_id' => (string)($row['ledger_id'] ?? $ledgerId),
            'uid' => (string)($row['uid'] ?? $targetUid),
            'type' => (string)($row['type'] ?? ''),
            'direction' => (string)($row['direction'] ?? ''),
            'amount' => (float)($row['amount'] ?? 0),
            'currency' => (string)($row['currency'] ?? 'BDT'),
            'before_available' => (float)($row['before_available'] ?? 0),
            'after_available' => (float)($row['after_available'] ?? 0),
            'before_hold' => (float)($row['before_hold'] ?? 0),
            'after_hold' => (float)($row['after_hold'] ?? 0),
            'ref_id' => (string)($row['ref_id'] ?? ''),
            'note' => (string)($row['note'] ?? ''),
            'created_at' => (int)($row['created_at'] ?? 0),
            'created_by_uid' => (string)($row['created_by_uid'] ?? ''),
            'created_by_role' => (string)($row['created_by_role'] ?? ''),
            'month_bucket' => (string)$month,
        ];
    }
}

usort($items, static function (array $a, array $b): int {
    return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
});

if (count($items) > $limit) {
    $items = array_slice($items, 0, $limit);
}

$wallet = fb_get('USER_WALLETS/' . $targetUid);
$wallet = is_array($wallet) ? $wallet : [];

wallet_ledger_response(true, 'SUCCESS', 'Wallet ledger loaded successfully', [
    'target_uid' => $targetUid,
    'target_name' => (string)($targetUser['name'] ?? ''),
    'target_phone' => (string)($targetUser['phone'] ?? ''),
    'target_role' => (string)($targetUser['role'] ?? ''),
    'available_balance' => (float)($wallet['available_balance'] ?? 0),
    'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
    'items' => array_values($items),
]);
