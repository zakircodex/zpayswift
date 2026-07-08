<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

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

$month = trim((string)($_GET['month'] ?? month_key()));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = month_key();
}

$items = fb_get('MFS_HISTORY/' . $uid . '/' . $month);
if (!is_array($items)) {
    $items = [];
}

$list = array_values(array_filter($items, static fn($row): bool => is_array($row)));

usort($list, static function (array $a, array $b): int {
    $aTime = (int)(($a['updated_at'] ?? 0) ?: ($a['completed_at'] ?? 0) ?: ($a['created_at'] ?? 0));
    $bTime = (int)(($b['updated_at'] ?? 0) ?: ($b['completed_at'] ?? 0) ?: ($b['created_at'] ?? 0));
    return $bTime <=> $aTime;
});

if (function_exists('mfs_public_log_row')) {
    $list = array_map(static function (array $row): array {
        return array_replace($row, mfs_public_log_row($row));
    }, $list);
}

api_response(true, 'MFS_HISTORY_OK', 'MFS history loaded', [
    'month' => $month,
    'items' => $list,
]);
