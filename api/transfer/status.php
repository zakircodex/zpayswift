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
$transferId = zpay_dash_clean_string($_GET['transfer_id'] ?? '', 80);
if ($transferId === '') {
    api_response(false, 'VALIDATION_ERROR', 'transfer_id is required.', [], 422);
}

$row = fb_get('TRANSFERS/' . $transferId);
if (!is_array($row) || !zpay_transfer_user_can_view($row, $uid)) {
    api_response(false, 'NOT_FOUND', 'Transfer not found.', [], 404);
}

api_response(true, 'TRANSFER_STATUS_OK', 'Transfer status loaded.', [
    'transfer' => zpay_transfer_public_row($row),
]);
