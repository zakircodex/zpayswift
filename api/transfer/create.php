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

$senderUser = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$senderUid = (string)($senderUser['uid'] ?? '');

$previewToken = trim((string)($body['preview_token'] ?? ''));
if ($previewToken !== '') {
    $tokenHash = zpay_transfer_preview_token_hash($previewToken);
    $claim = zpay_transfer_claim_preview_token($tokenHash, $senderUid);
    if (empty($claim['ok'])) {
        api_response(
            false,
            (string)($claim['code'] ?? 'TRANSFER_PREVIEW_INVALID'),
            (string)($claim['message'] ?? 'This transfer preview is invalid. Please review again.'),
            (array)($claim['data'] ?? []),
            (int)($claim['status'] ?? 422)
        );
    }

    $duplicateTransferId = trim((string)($claim['transfer_id'] ?? ''));
    if (!empty($claim['duplicate']) && $duplicateTransferId !== '') {
        $existing = zpay_transfer_replay_preview_result($duplicateTransferId, (array)($claim['preview'] ?? []));
        if (empty($existing['ok'])) {
            api_response(
                false,
                (string)($existing['code'] ?? 'TRANSFER_PROCESSING'),
                (string)($existing['message'] ?? 'This transfer is still being finalized. Please check status.'),
                (array)($existing['data'] ?? []),
                (int)($existing['status'] ?? 409)
            );
        }
        $existingTransfer = (array)($existing['transfer'] ?? []);
        zpay_transfer_schedule_post_response_tasks($existingTransfer);
        api_response(true, 'TRANSFER_SUCCESS', 'Transfer completed successfully.', [
            'transfer' => zpay_transfer_public_row($existingTransfer),
        ]);
    }

    $reference = zpay_transfer_clean_reference($body['reference'] ?? $body['note'] ?? '');
    $result = zpay_transfer_execute_preview((array)($claim['preview'] ?? []), $tokenHash, $reference);
    if (empty($result['ok'])) {
        api_response(
            false,
            (string)($result['code'] ?? 'TRANSFER_FAILED'),
            (string)($result['message'] ?? 'The transfer could not be completed. No money was lost.'),
            (array)($result['data'] ?? []),
            (int)($result['status'] ?? 422)
        );
    }

    $completedTransfer = (array)($result['transfer'] ?? []);
    zpay_transfer_schedule_post_response_tasks($completedTransfer);
    api_response(true, 'TRANSFER_SUCCESS', 'Transfer completed successfully.', [
        'transfer' => zpay_transfer_public_row($completedTransfer),
    ]);
}

$senderStatus = auth_status_value($senderUser['status'] ?? '');
$senderAccountStatus = auth_status_value($senderUser['account_status'] ?? $senderStatus);
if ($senderStatus !== 'ACTIVE' || $senderAccountStatus !== 'ACTIVE') {
    api_response(false, 'ACCOUNT_INACTIVE', 'Your account is not active.', [], 403);
}

$recipientInput = zpay_transfer_input_phone($body);
$amount = zpay_transfer_money($body['amount'] ?? 0);
$pin = trim((string)($body['pin'] ?? ''));
$note = zpay_dash_clean_string($body['note'] ?? '', 140);
$idempotencyKey = zpay_transfer_idempotency_key((string)($body['idempotency_key'] ?? ''));

if ($recipientInput === '') {
    api_response(false, 'VALIDATION_ERROR', 'recipient_account is required.', [], 422);
}
if ($amount <= 0) {
    api_response(false, 'INVALID_AMOUNT', 'Amount must be greater than zero.', [], 422);
}
if ($pin === '') {
    api_response(false, 'PIN_REQUIRED', 'PIN is required.', [], 422);
}
if (!auth_app_pin_ok($senderUser, $pin)) {
    api_response(false, 'WRONG_PIN', 'PIN is incorrect.', [], 401);
}

$recipient = zpay_transfer_guard_recipient($auth, $recipientInput);
$receiverUser = is_array($recipient['user'] ?? null) ? $recipient['user'] : [];
$receiverUid = (string)($recipient['uid'] ?? '');

$senderWallet = fb_get('USER_WALLETS/' . $senderUid);
$receiverWallet = fb_get('USER_WALLETS/' . $receiverUid);
$senderWallet = is_array($senderWallet) ? $senderWallet : [];
$receiverWallet = is_array($receiverWallet) ? $receiverWallet : [];
$currency = wallet_account_currency($senderUser, $senderWallet);
$receiverCurrency = wallet_account_currency($receiverUser, $receiverWallet);
if ($currency !== $receiverCurrency) {
    api_response(false, 'TRANSFER_CURRENCY_MISMATCH', 'Transfers between different wallet currencies are not supported.', [], 422);
}
if ($amount < 1.0) {
    api_response(
        false,
        'TRANSFER_BELOW_MINIMUM',
        $currency === 'MYR' ? 'Minimum transfer amount is RM 1.00.' : 'Minimum transfer amount is 1.00 BDT.',
        ['minimum_amount' => 1.00, 'wallet_currency' => $currency],
        422
    );
}

$transferId = wallet_make_transfer_id();
$idempotencyPath = zpay_transfer_acquire_idempotency($senderUid, $idempotencyKey, $transferId);
$now = now_ts();

$senderPhone = (string)($senderUser['phone'] ?? '');
$receiverPhone = (string)($recipient['phone'] ?? $receiverUser['phone'] ?? '');
$senderName = (string)($senderUser['name'] ?? '');
$receiverName = (string)($receiverUser['name'] ?? '');
$senderRole = auth_status_value($senderUser['role'] ?? 'USER');
$receiverRole = auth_status_value($receiverUser['role'] ?? 'USER');

$result = zpay_transfer_execute_financial([
    'transfer_id' => $transferId,
    'idempotency_path' => $idempotencyPath,
    'idempotency_key_hash' => hash('sha256', $idempotencyKey),
    'sender_uid' => $senderUid,
    'receiver_uid' => $receiverUid,
    'sender_phone' => $senderPhone,
    'receiver_phone' => $receiverPhone,
    'sender_name' => $senderName,
    'receiver_name' => $receiverName,
    'sender_role' => $senderRole,
    'receiver_role' => $receiverRole,
    'amount' => $amount,
    'transfer_amount' => $amount,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'reference' => $note,
    'note' => $note,
    'created_at' => $now,
]);

if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'TRANSFER_FAILED'),
        (string)($result['message'] ?? 'Transfer could not be processed.'),
        (array)($result['data'] ?? []),
        (int)($result['status'] ?? 422)
    );
}

$completedTransfer = (array)($result['transfer'] ?? []);
zpay_transfer_schedule_post_response_tasks($completedTransfer);
api_response(true, 'TRANSFER_SUCCESS', 'Transfer completed successfully.', [
    'transfer' => zpay_transfer_public_row($completedTransfer),
]);
