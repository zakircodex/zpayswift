<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/admin_topup.php';

api_require_method('GET');
auth_require_admin_session();

$requestId = trim((string)($_GET['request_id'] ?? ''));
if ($requestId === '') {
    api_response(false, 'VALIDATION_ERROR', 'request_id is required', [], 422);
}

$row = admin_topup_find_request($requestId);
if (!$row) {
    api_response(false, 'NOT_FOUND', 'Topup request not found', [], 404);
}

$row = admin_topup_attach_status($row);

$uid = (string)($row['uid'] ?? '');
$user = $uid !== '' ? fb_get('USERS/' . $uid) : null;
$wallet = $uid !== '' ? fb_get('USER_WALLETS/' . $uid) : null;

api_response(true, 'SUCCESS', 'Topup request loaded', [
    'request' => $row,
    'user' => is_array($user) ? [
        'uid' => (string)($user['uid'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'status' => (string)($user['status'] ?? ''),
    ] : null,
    'wallet' => is_array($wallet) ? [
        'available_balance' => (float)($wallet['available_balance'] ?? 0),
        'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
        'updated_at' => (int)($wallet['updated_at'] ?? 0),
    ] : null,
]);