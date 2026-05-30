<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$uid = (string)$auth['user']['uid'];

$wallet = fb_get('USER_WALLETS/' . $uid);

if (!is_array($wallet)) {
    api_response(false, 'NOT_FOUND', 'Wallet not found', [], 404);
}

api_response(true, 'SUCCESS', 'Wallet loaded', [
    'available_balance' => (float)($wallet['available_balance'] ?? 0),
    'hold_balance' => (float)($wallet['hold_balance'] ?? 0),
    'total_topup_spent' => (float)($wallet['total_topup_spent'] ?? 0),
    'total_bundle_spent' => (float)($wallet['total_bundle_spent'] ?? 0),
    'total_refund' => (float)($wallet['total_refund'] ?? 0),
    'updated_at' => (int)($wallet['updated_at'] ?? 0),
]);