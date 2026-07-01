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
if ($recipientInput === '') {
    api_response(false, 'VALIDATION_ERROR', 'recipient_phone is required.', [], 422);
}

$recipient = zpay_transfer_guard_recipient($auth, $recipientInput);
$senderUser = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$senderUid = (string)($senderUser['uid'] ?? '');
$receiverUid = (string)($recipient['uid'] ?? '');
$senderWallet = fb_get('USER_WALLETS/' . $senderUid);
$receiverWallet = fb_get('USER_WALLETS/' . $receiverUid);
$senderCurrency = wallet_account_currency($senderUser, is_array($senderWallet) ? $senderWallet : []);
$receiverCurrency = wallet_account_currency($recipient['user'], is_array($receiverWallet) ? $receiverWallet : []);
$canTransfer = $senderCurrency === $receiverCurrency;
$reason = $canTransfer ? '' : 'CURRENCY_MISMATCH';

api_response(true, 'RECIPIENT_OK', 'Recipient checked.', [
    'recipient' => zpay_transfer_public_recipient($recipient, $canTransfer, $reason),
]);
