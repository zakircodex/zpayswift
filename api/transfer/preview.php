<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/auth_android.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/mobile_transfer.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_mobile_user(true);
$body = api_read_json_body();

$recipientInput = zpay_transfer_input_phone($body);
$amount = zpay_transfer_money($body['amount'] ?? $body['transfer_amount'] ?? 0);
$checkOnly = !empty($body['check_only']) || !empty($body['validate_only']);

$preview = zpay_transfer_prepare_preview($auth, $recipientInput, $amount);
if (empty($preview['ok'])) {
    api_response(
        false,
        (string)($preview['code'] ?? 'TRANSFER_PREVIEW_FAILED'),
        (string)($preview['message'] ?? 'Transfer preview could not be loaded.'),
        (array)($preview['data'] ?? []),
        (int)($preview['status'] ?? 422)
    );
}

if ($checkOnly) {
    api_response(true, 'TRANSFER_AMOUNT_READY', 'Transfer amount can be processed.', zpay_transfer_public_preview($preview));
}

$token = zpay_transfer_create_preview_token($preview);
if ($token === '') {
    api_response(false, 'TRANSFER_PREVIEW_FAILED', 'Transfer preview could not be created.', [], 500);
}

api_response(true, 'TRANSFER_PREVIEW_READY', 'Transfer preview ready.', zpay_transfer_public_preview($preview, $token));
