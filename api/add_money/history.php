<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/add_money.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));

if ($uid === '') {
    api_response(false, 'UNAUTHORIZED', 'Session is required', [], 401);
}

$role = strtoupper(trim((string)($user['role'] ?? $user['account_type'] ?? 'USER')));
if (!in_array($role, ['USER', 'RETAILER'], true)) {
    api_response(false, 'ROLE_NOT_ALLOWED', 'This account type is not allowed in this app.', [], 403);
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

api_response(true, 'ADD_MONEY_HISTORY_OK', 'Add money history loaded', [
    'uid' => $uid,
    'items' => add_money_public_request_rows(add_money_list_user_history($uid, $limit)),
]);
