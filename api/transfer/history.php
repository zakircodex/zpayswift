<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/auth_android.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/mobile_transfer.php';

api_require_method('GET');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = (string)($user['uid'] ?? '');
$limit = (int)($_GET['limit'] ?? 50);

api_response(true, 'TRANSFER_HISTORY_OK', 'Transfer history loaded.', [
    'items' => zpay_transfer_user_history($uid, $limit),
]);
