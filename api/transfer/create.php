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
        $existing = fb_get('TRANSFERS/' . $duplicateTransferId);
        api_response(true, 'TRANSFER_SUCCESS', 'Transfer completed successfully.', [
            'transfer' => zpay_transfer_public_row(is_array($existing) ? $existing : []),
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

    api_response(true, 'TRANSFER_SUCCESS', 'Transfer completed successfully.', [
        'transfer' => zpay_transfer_public_row((array)($result['transfer'] ?? [])),
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
$month = month_key($now);
$senderLedgerId = wallet_make_ledger_id();
$receiverLedgerId = wallet_make_ledger_id();

$senderPhone = (string)($senderUser['phone'] ?? '');
$receiverPhone = (string)($recipient['phone'] ?? $receiverUser['phone'] ?? '');
$senderName = (string)($senderUser['name'] ?? '');
$receiverName = (string)($receiverUser['name'] ?? '');
$senderRole = auth_status_value($senderUser['role'] ?? 'USER');
$receiverRole = auth_status_value($receiverUser['role'] ?? 'USER');

$commonExtra = [
    'transfer_id' => $transferId,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'fee' => 0,
    'fee_amount' => 0,
    'commission' => 0,
    'commission_amount' => 0,
    'reference' => $note,
    'note' => $note,
    'created_at' => $now,
    'updated_at' => $now,
];

$debit = wallet_debit_available($senderUid, $amount, $transferId, 'TRANSFER_OUT', 'Z-Pay account transfer out', array_merge($commonExtra, [
    'ledger_id' => $senderLedgerId,
    'receiver_uid' => $receiverUid,
    'receiver_account_masked' => zpay_dash_mask_phone($receiverPhone),
]));
if (empty($debit['ok'])) {
    fb_patch($idempotencyPath, [
        'status' => 'FAILED',
        'error_code' => (string)($debit['code'] ?? 'WALLET_DEBIT_FAILED'),
        'updated_at' => now_ts(),
    ]);
    api_response(false, (string)($debit['code'] ?? 'WALLET_DEBIT_FAILED'), (string)($debit['message'] ?? 'Transfer could not be processed.'), [], 422);
}

$credit = wallet_credit_available($receiverUid, $amount, $transferId, 'TRANSFER_IN', 'Z-Pay account transfer in', array_merge($commonExtra, [
    'ledger_id' => $receiverLedgerId,
    'sender_uid' => $senderUid,
    'sender_account_masked' => zpay_dash_mask_phone($senderPhone),
]));
if (empty($credit['ok'])) {
    wallet_restore_available_balance($senderUid, (float)$debit['after_available'], (float)$debit['before_available']);
    wallet_delete_ledger_record($senderUid, $now, $senderLedgerId);
    fb_patch($idempotencyPath, [
        'status' => 'FAILED',
        'error_code' => (string)($credit['code'] ?? 'WALLET_CREDIT_FAILED'),
        'updated_at' => now_ts(),
    ]);
    api_response(false, 'TRANSFER_ROLLED_BACK', 'Transfer failed and sender balance was restored.', [], 500);
}

$transfer = [
    'transfer_id' => $transferId,
    'request_id' => $transferId,
    'type' => 'ZPAY_TRANSFER',
    'sender_uid' => $senderUid,
    'sender_account' => $senderPhone,
    'sender_phone' => $senderPhone,
    'sender_name' => $senderName,
    'sender_role' => $senderRole,
    'receiver_uid' => $receiverUid,
    'receiver_account' => $receiverPhone,
    'receiver_phone' => $receiverPhone,
    'receiver_name' => $receiverName,
    'receiver_role' => $receiverRole,
    'amount' => $amount,
    'transfer_amount' => $amount,
    'currency' => $currency,
    'wallet_currency' => $currency,
    'fee' => 0,
    'fee_amount' => 0,
    'commission' => 0,
    'commission_amount' => 0,
    'total_paid' => $amount,
    'total_debit' => $amount,
    'status' => 'SUCCESS',
    'reference' => $note,
    'note' => $note,
    'created_at' => $now,
    'updated_at' => $now,
    'completed_at' => $now,
    'month' => $month,
    'idempotency_key_hash' => hash('sha256', $idempotencyKey),
    'sender_wallet_debited' => true,
    'sender_ledger_id' => $senderLedgerId,
    'receiver_ledger_id' => $receiverLedgerId,
    'sender_before_available' => (float)$debit['before_available'],
    'sender_after_available' => (float)$debit['after_available'],
    'sender_before_hold' => (float)($debit['hold_balance'] ?? 0),
    'sender_after_hold' => (float)($debit['hold_balance'] ?? 0),
    'receiver_before_available' => (float)$credit['before_available'],
    'receiver_after_available' => (float)$credit['after_available'],
    'receiver_before_hold' => (float)($credit['hold_balance'] ?? 0),
    'receiver_after_hold' => (float)($credit['hold_balance'] ?? 0),
    'calculation_version' => 'zpay_transfer_v1',
];

$receipt = zpay_transfer_save_receipt($transfer);
if (!empty($receipt['receipt_url'])) {
    $transfer = array_merge($transfer, [
        'receipt_id' => (string)$receipt['receipt_id'],
        'receipt_token' => (string)$receipt['receipt_token'],
        'receipt_url' => (string)$receipt['receipt_url'],
        'tracking_url' => (string)$receipt['tracking_url'],
        'receipt_created_at' => (int)$receipt['receipt_created_at'],
    ]);
}

$store = wallet_store_transfer_records($transfer, [
    ['uid' => $senderUid, 'row' => array_merge($transfer, [
        'ledger_id' => $senderLedgerId,
        'direction' => 'DEBIT',
        'type' => 'TRANSFER_OUT',
    ])],
    ['uid' => $receiverUid, 'row' => array_merge($transfer, [
        'ledger_id' => $receiverLedgerId,
        'direction' => 'CREDIT',
        'type' => 'TRANSFER_IN',
    ])],
]);
if (empty($store['ok'])) {
    wallet_restore_available_balance($senderUid, (float)$debit['after_available'], (float)$debit['before_available']);
    wallet_restore_available_balance($receiverUid, (float)$credit['after_available'], (float)$credit['before_available']);
    wallet_delete_ledger_record($senderUid, $now, $senderLedgerId);
    wallet_delete_ledger_record($receiverUid, $now, $receiverLedgerId);
    foreach (($receipt['written_paths'] ?? []) as $path) {
        if (is_string($path) && trim($path) !== '') {
            fb_delete($path);
        }
    }
    fb_patch($idempotencyPath, [
        'status' => 'FAILED',
        'error_code' => (string)($store['code'] ?? 'TRANSFER_STORE_FAILED'),
        'updated_at' => now_ts(),
    ]);
    api_response(false, 'TRANSFER_STORE_FAILED', 'Transfer history could not be saved.', [], 500);
}

if (!fb_put('TRANSFERS/' . $transferId, $transfer)
    || !fb_put('TRANSFER_HISTORY/' . $senderUid . '/' . $transferId, array_merge($transfer, ['direction' => 'OUT']))
    || !fb_put('TRANSFER_HISTORY/' . $receiverUid . '/' . $transferId, array_merge($transfer, ['direction' => 'IN']))
) {
    wallet_restore_available_balance($senderUid, (float)$debit['after_available'], (float)$debit['before_available']);
    wallet_restore_available_balance($receiverUid, (float)$credit['after_available'], (float)$credit['before_available']);
    wallet_delete_ledger_record($senderUid, $now, $senderLedgerId);
    wallet_delete_ledger_record($receiverUid, $now, $receiverLedgerId);
    foreach (($store['written_paths'] ?? []) as $path) {
        if (is_string($path) && trim($path) !== '') {
            fb_delete($path);
        }
    }
    foreach (($receipt['written_paths'] ?? []) as $path) {
        if (is_string($path) && trim($path) !== '') {
            fb_delete($path);
        }
    }
    fb_delete('TRANSFERS/' . $transferId);
    fb_delete('TRANSFER_HISTORY/' . $senderUid . '/' . $transferId);
    fb_delete('TRANSFER_HISTORY/' . $receiverUid . '/' . $transferId);
    fb_patch($idempotencyPath, [
        'status' => 'FAILED',
        'error_code' => 'TRANSFER_INDEX_FAILED',
        'updated_at' => now_ts(),
    ]);
    api_response(false, 'TRANSFER_INDEX_FAILED', 'Transfer index could not be saved and balances were restored.', [], 500);
}

fb_patch($idempotencyPath, [
    'status' => 'SUCCESS',
    'transfer_id' => $transferId,
    'updated_at' => now_ts(),
]);

system_log('TRANSFER_SUCCESS', $transferId, 'Account transfer completed', [
    'sender_uid' => $senderUid,
    'receiver_uid' => $receiverUid,
    'amount' => $amount,
    'currency' => $currency,
]);

api_response(true, 'TRANSFER_SUCCESS', 'Transfer completed successfully.', [
    'transfer' => zpay_transfer_public_row($transfer),
]);
