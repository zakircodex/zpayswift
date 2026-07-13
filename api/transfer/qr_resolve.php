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

$payload = trim((string)($body['qr_payload'] ?? $body['qr_token'] ?? ''));
if ($payload === '') {
    api_response(false, 'INVALID_QR', 'Invalid Z-Pay QR', [], 422);
}
if (stripos($payload, zpay_transfer_qr_payload_prefix()) !== 0 || zpay_transfer_find_user_by_qr_payload($payload) === []) {
    api_response(false, 'INVALID_QR', 'Invalid Z-Pay QR', [], 422);
}

$recipient = zpay_transfer_guard_recipient($auth, $payload);
$senderUser = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$senderUid = (string)($senderUser['uid'] ?? '');
$receiverUid = (string)($recipient['uid'] ?? '');

$senderWallet = fb_get('USER_WALLETS/' . $senderUid);
$receiverWallet = fb_get('USER_WALLETS/' . $receiverUid);
$senderCurrency = wallet_account_currency($senderUser, is_array($senderWallet) ? $senderWallet : []);
$receiverCurrency = wallet_account_currency($recipient['user'], is_array($receiverWallet) ? $receiverWallet : []);
$canTransfer = $senderCurrency === $receiverCurrency;

$public = zpay_transfer_public_recipient($recipient, $canTransfer, $canTransfer ? '' : 'CURRENCY_MISMATCH');
unset($public['receiver_phone'], $public['receiver_phone_masked']);

api_response(true, 'QR_RECEIVER_OK', 'QR receiver loaded.', [
    'recipient' => $public,
    'qr_payload' => zpay_transfer_qr_payload(zpay_transfer_normalize_qr_payload($payload)),
    'sender_wallet_currency' => $senderCurrency,
    'receiver_wallet_currency' => $receiverCurrency,
    'wallet_currency' => $senderCurrency,
    'can_transfer' => $canTransfer,
    'validation_code' => $canTransfer ? '' : 'TRANSFER_CURRENCY_MISMATCH',
    'validation_message' => $canTransfer ? '' : 'Transfers between different wallet currencies are not supported.',
]);
