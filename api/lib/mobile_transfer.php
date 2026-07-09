<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function zpay_transfer_money($value): float
{
    if (is_int($value) || is_float($value)) {
        return round((float)$value, 2);
    }

    $clean = str_replace(',', '', trim((string)$value));
    return is_numeric($clean) ? round((float)$clean, 2) : 0.0;
}

function zpay_transfer_text($value, string $currency): string
{
    $amount = zpay_transfer_money($value);
    $currency = wallet_normalize_currency_code($currency, 'BDT');
    if ($currency === 'MYR') {
        return 'RM ' . number_format($amount, 2, '.', '');
    }
    return number_format($amount, 2, '.', '') . ' BDT';
}

function zpay_transfer_clean_reference($value, int $max = 80): string
{
    $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value) ?? '');
    return strlen($clean) > $max ? substr($clean, 0, $max) : $clean;
}

function zpay_transfer_api_base_url(): string
{
    if (function_exists('app_api_url')) {
        return rtrim((string)app_api_url(), '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return rtrim($scheme . '://' . $host . '/api', '/');
}

function zpay_transfer_receipt_token(): string
{
    return function_exists('random_token')
        ? random_token(32)
        : bin2hex(random_bytes(24));
}

function zpay_transfer_receipt_id(): string
{
    return 'TRR' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function zpay_transfer_receipt_url(string $token): string
{
    return zpay_transfer_api_base_url() . '/transfer/receipt.php?t=' . rawurlencode($token);
}

function zpay_transfer_input_phone(array $body): string
{
    return trim((string)(
        $body['recipient_account']
        ?? $body['recipient_phone']
        ?? $body['receiver_phone']
        ?? $body['account']
        ?? $body['phone']
        ?? ''
    ));
}

function zpay_transfer_find_user_by_account(string $account, string $preferredCountry = ''): array
{
    $account = trim($account);
    if ($account === '') {
        return [];
    }

    $countries = [];
    $preferredCountry = function_exists('auth_normalize_country_code')
        ? auth_normalize_country_code($preferredCountry)
        : strtoupper(trim($preferredCountry));
    if ($preferredCountry !== '') {
        $countries[] = $preferredCountry;
    }
    foreach (['MY', 'BD'] as $country) {
        if (!in_array($country, $countries, true)) {
            $countries[] = $country;
        }
    }

    foreach ($countries as $country) {
        $phone = normalize_phone_by_country($account, $country);
        if ($phone === '') {
            continue;
        }
        $uid = auth_find_uid_by_phone_country($phone, $country);
        if ($uid === '') {
            continue;
        }
        $user = fb_get('USERS/' . $uid);
        if (!is_array($user)) {
            continue;
        }
        $user['uid'] = $uid;
        return [
            'uid' => $uid,
            'phone' => normalize_phone_by_country((string)($user['phone'] ?? $phone), auth_phone_country_from_user($user)) ?: $phone,
            'phone_country' => auth_phone_country_from_user($user),
            'user' => $user,
        ];
    }

    return [];
}

function zpay_transfer_public_recipient(array $account, bool $canTransfer, string $reason = ''): array
{
    $user = is_array($account['user'] ?? null) ? $account['user'] : [];
    $role = strtoupper(trim((string)($user['role'] ?? '')));

    return [
        'exists' => !empty($account),
        'can_transfer' => $canTransfer,
        'reason' => $reason,
        'receiver_name' => trim((string)($user['name'] ?? '')),
        'receiver_phone' => (string)($account['phone'] ?? $user['phone'] ?? ''),
        'receiver_name_masked' => zpay_dash_mask_name((string)($user['name'] ?? '')),
        'receiver_phone_masked' => zpay_dash_mask_phone((string)($account['phone'] ?? $user['phone'] ?? '')),
        'receiver_role' => $role,
    ];
}

function zpay_transfer_guard_recipient(array $senderAuth, string $recipientAccount): array
{
    $senderUser = is_array($senderAuth['user'] ?? null) ? $senderAuth['user'] : [];
    $senderUid = (string)($senderUser['uid'] ?? '');
    $preferredCountry = auth_phone_country_from_user($senderUser);
    $recipient = zpay_transfer_find_user_by_account($recipientAccount, $preferredCountry);

    if (!$recipient) {
        api_response(false, 'TRANSFER_RECEIVER_NOT_FOUND', 'No active Z-Pay account was found with this number.', [
            'recipient' => zpay_transfer_public_recipient([], false, 'NOT_FOUND'),
        ], 404);
    }

    $receiverUser = is_array($recipient['user'] ?? null) ? $recipient['user'] : [];
    $receiverUid = (string)($recipient['uid'] ?? '');
    if ($receiverUid === '' || $receiverUid === $senderUid) {
        api_response(false, 'TRANSFER_TO_SELF_NOT_ALLOWED', 'You cannot transfer money to your own account.', [], 422);
    }

    $status = function_exists('auth_status_value')
        ? auth_status_value($receiverUser['status'] ?? 'INACTIVE')
        : strtoupper(trim((string)($receiverUser['status'] ?? 'INACTIVE')));
    $accountStatus = function_exists('auth_status_value')
        ? auth_status_value($receiverUser['account_status'] ?? $status)
        : strtoupper(trim((string)($receiverUser['account_status'] ?? $status)));
    $role = function_exists('auth_status_value')
        ? auth_status_value($receiverUser['role'] ?? '')
        : strtoupper(trim((string)($receiverUser['role'] ?? '')));

    if ($status !== 'ACTIVE' || $accountStatus !== 'ACTIVE') {
        api_response(false, 'TRANSFER_RECEIVER_INACTIVE', 'This Z-Pay account is not available for transfer.', [
            'recipient' => zpay_transfer_public_recipient($recipient, false, 'INACTIVE'),
        ], 422);
    }

    if (!zpay_dash_allowed_mobile_role($role)) {
        api_response(false, 'TRANSFER_RECEIVER_INACTIVE', 'This Z-Pay account is not available for transfer.', [
            'recipient' => zpay_transfer_public_recipient($recipient, false, 'ROLE_NOT_ALLOWED'),
        ], 422);
    }

    $receiverUser['uid'] = $receiverUid;
    $receiverUser['status'] = $status;
    $receiverUser['account_status'] = $accountStatus;
    $receiverUser['role'] = $role;
    $recipient['user'] = $receiverUser;

    return $recipient;
}

function zpay_transfer_account_context(string $uid, array $user = [], array $wallet = []): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_CURRENCY_INVALID',
            'message' => 'Your account currency could not be verified.',
        ];
    }

    if (!$user) {
        $loadedUser = fb_get('USERS/' . $uid);
        $user = is_array($loadedUser) ? $loadedUser : [];
    }
    if (!$wallet) {
        $loadedWallet = fb_get('USER_WALLETS/' . $uid);
        $wallet = is_array($loadedWallet) ? $loadedWallet : [];
    }

    $country = wallet_account_country_code($user, $wallet);
    $currency = wallet_account_currency($user, $wallet);
    $currency = wallet_normalize_currency_code($currency);
    if (!in_array($currency, ['MYR', 'BDT'], true)
        || ($country === 'MY' && $currency !== 'MYR')
        || ($country === 'BD' && $currency !== 'BDT')
    ) {
        return [
            'ok' => false,
            'code' => 'ACCOUNT_CURRENCY_INVALID',
            'message' => 'Your account currency could not be verified.',
            'account_country' => $country,
            'wallet_currency' => $currency,
        ];
    }

    return [
        'ok' => true,
        'uid' => $uid,
        'user' => $user,
        'wallet' => $wallet,
        'account_country' => $country,
        'wallet_currency' => $currency,
        'available_balance' => zpay_transfer_money($wallet['available_balance'] ?? 0),
        'hold_balance' => zpay_transfer_money($wallet['hold_balance'] ?? 0),
    ];
}

function zpay_transfer_validation_error(string $code, string $message, array $data = [], int $status = 422): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'status' => $status,
    ];
}

function zpay_transfer_prepare_preview(array $senderAuth, string $recipientInput, float $amount): array
{
    $senderUser = is_array($senderAuth['user'] ?? null) ? $senderAuth['user'] : [];
    $senderUid = (string)($senderUser['uid'] ?? '');
    $senderStatus = function_exists('auth_status_value')
        ? auth_status_value($senderUser['status'] ?? '')
        : strtoupper(trim((string)($senderUser['status'] ?? '')));
    $senderAccountStatus = function_exists('auth_status_value')
        ? auth_status_value($senderUser['account_status'] ?? $senderStatus)
        : strtoupper(trim((string)($senderUser['account_status'] ?? $senderStatus)));

    if ($senderStatus !== 'ACTIVE' || $senderAccountStatus !== 'ACTIVE') {
        return zpay_transfer_validation_error('ACCOUNT_INACTIVE', 'Your account is not active.', [], 403);
    }

    if ($recipientInput === '') {
        return zpay_transfer_validation_error('VALIDATION_ERROR', 'receiver_phone is required.');
    }

    if ($amount <= 0) {
        return zpay_transfer_validation_error('INVALID_AMOUNT', 'Amount must be greater than zero.');
    }

    $recipient = zpay_transfer_guard_recipient($senderAuth, $recipientInput);
    $receiverUser = is_array($recipient['user'] ?? null) ? $recipient['user'] : [];
    $receiverUid = (string)($recipient['uid'] ?? '');
    $senderWallet = fb_get('USER_WALLETS/' . $senderUid);
    $receiverWallet = fb_get('USER_WALLETS/' . $receiverUid);
    $senderContext = zpay_transfer_account_context($senderUid, $senderUser, is_array($senderWallet) ? $senderWallet : []);
    if (empty($senderContext['ok'])) {
        return $senderContext;
    }
    $receiverContext = zpay_transfer_account_context($receiverUid, $receiverUser, is_array($receiverWallet) ? $receiverWallet : []);
    if (empty($receiverContext['ok'])) {
        return zpay_transfer_validation_error('TRANSFER_RECEIVER_INACTIVE', 'This Z-Pay account is not available for transfer.');
    }

    $currency = (string)$senderContext['wallet_currency'];
    if ($currency !== (string)$receiverContext['wallet_currency']) {
        return zpay_transfer_validation_error(
            'TRANSFER_CURRENCY_MISMATCH',
            'Transfers between different wallet currencies are not supported.',
            [
                'sender_wallet_currency' => (string)$senderContext['wallet_currency'],
                'receiver_wallet_currency' => (string)$receiverContext['wallet_currency'],
            ]
        );
    }

    $minimum = 1.00;
    if ($amount < $minimum) {
        return zpay_transfer_validation_error(
            'TRANSFER_BELOW_MINIMUM',
            $currency === 'MYR' ? 'Minimum transfer amount is RM 1.00.' : 'Minimum transfer amount is 1.00 BDT.',
            ['minimum_amount' => $minimum, 'wallet_currency' => $currency]
        );
    }

    $available = (float)$senderContext['available_balance'];
    if ($available + 0.0001 < $amount) {
        return zpay_transfer_validation_error(
            'TRANSFER_INSUFFICIENT_BALANCE',
            $currency === 'MYR' ? 'Insufficient MYR balance.' : 'Insufficient BDT balance.',
            [
                'available_balance' => $available,
                'required_amount' => $amount,
                'wallet_currency' => $currency,
            ]
        );
    }

    $now = now_ts();
    $receiverPhone = (string)($recipient['phone'] ?? $receiverUser['phone'] ?? '');
    $senderPhone = (string)($senderUser['phone'] ?? '');
    $senderName = (string)($senderUser['name'] ?? '');
    $receiverName = (string)($receiverUser['name'] ?? '');
    $balanceAfter = zpay_transfer_money($available - $amount);

    return [
        'ok' => true,
        'uid' => $senderUid,
        'sender_uid' => $senderUid,
        'receiver_uid' => $receiverUid,
        'sender_name' => $senderName,
        'sender_phone' => $senderPhone,
        'receiver_name' => $receiverName,
        'receiver_phone' => $receiverPhone,
        'sender_account_country' => (string)$senderContext['account_country'],
        'receiver_account_country' => (string)$receiverContext['account_country'],
        'wallet_currency' => $currency,
        'currency' => $currency,
        'amount' => $amount,
        'transfer_amount' => $amount,
        'fee_amount' => 0.0,
        'commission_amount' => 0.0,
        'total_debit' => $amount,
        'total_paid' => $amount,
        'sender_balance_before' => $available,
        'sender_balance_after' => $balanceAfter,
        'balance_before' => $available,
        'balance_after' => $balanceAfter,
        'created_at' => $now,
        'expires_at' => $now + 300,
        'status' => 'READY',
        'calculation_version' => 'zpay_transfer_v1',
        'recipient' => zpay_transfer_public_recipient($recipient, true, ''),
    ];
}

function zpay_transfer_preview_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

function zpay_transfer_create_preview_token(array $data): string
{
    $token = function_exists('random_token') ? random_token(32) : bin2hex(random_bytes(32));
    $hash = zpay_transfer_preview_token_hash($token);
    $now = now_ts();
    $data['preview_token_hash'] = $hash;
    $data['created_at'] = (int)($data['created_at'] ?? $now);
    $data['expires_at'] = (int)($data['expires_at'] ?? ($now + 300));
    $data['used'] = false;
    $data['used_at'] = 0;
    $data['status'] = 'READY';

    return fb_put('TRANSFER_PREVIEWS/' . $hash, $data) ? $token : '';
}

function zpay_transfer_claim_preview_token(string $tokenHash, string $uid): array
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === '') {
        return zpay_transfer_validation_error('TRANSFER_PREVIEW_INVALID', 'This transfer preview is invalid. Please review again.');
    }

    $path = 'TRANSFER_PREVIEWS/' . $tokenHash;
    for ($i = 0; $i < 5; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || !is_array($res['value'] ?? null) || empty($res['etag'])) {
            return zpay_transfer_validation_error('TRANSFER_PREVIEW_INVALID', 'This transfer preview is invalid. Please review again.');
        }

        $row = $res['value'];
        $status = strtoupper(trim((string)($row['status'] ?? 'READY')));
        if (!empty($row['used']) || $status === 'USED') {
            $transferId = trim((string)($row['transfer_id'] ?? ''));
            if ($transferId !== '') {
                return [
                    'ok' => true,
                    'duplicate' => true,
                    'transfer_id' => $transferId,
                    'preview' => $row,
                ];
            }
            return zpay_transfer_validation_error('TRANSFER_ALREADY_SUBMITTED', 'This transfer has already been submitted.');
        }
        if ($status === 'PROCESSING') {
            return zpay_transfer_validation_error('TRANSFER_ALREADY_SUBMITTED', 'This transfer has already been submitted.', [], 409);
        }
        if ((int)($row['expires_at'] ?? 0) < now_ts()) {
            @fb_patch($path, [
                'status' => 'EXPIRED',
                'updated_at' => now_ts(),
            ]);
            return zpay_transfer_validation_error('TRANSFER_PREVIEW_EXPIRED', 'This transfer preview has expired. Please review again.');
        }
        if ((string)($row['sender_uid'] ?? $row['uid'] ?? '') !== $uid) {
            return zpay_transfer_validation_error('TRANSFER_PREVIEW_INVALID', 'This transfer preview does not belong to this account.', [], 403);
        }
        if (!in_array($status, ['READY', 'ACTIVE', 'FAILED'], true)) {
            return zpay_transfer_validation_error('TRANSFER_ALREADY_SUBMITTED', 'This transfer has already been submitted.', [], 409);
        }

        $claimed = $row;
        $claimed['status'] = 'PROCESSING';
        $claimed['processing_at'] = now_ts();
        $claimed['updated_at'] = now_ts();
        $save = fb_put_if_match($path, $claimed, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(150000);
            continue;
        }
        if (!($save['ok'] ?? false)) {
            return zpay_transfer_validation_error('TRANSFER_PREVIEW_CLAIM_FAILED', 'Transfer preview could not be locked. Please try again.', [], 409);
        }
        $claimed['_token_hash'] = $tokenHash;
        return ['ok' => true, 'preview' => $claimed];
    }

    return zpay_transfer_validation_error('TRANSFER_ALREADY_SUBMITTED', 'This transfer has already been submitted.', [], 409);
}

function zpay_transfer_mark_preview_used(string $tokenHash, string $transferId): void
{
    if (trim($tokenHash) === '') {
        return;
    }
    @fb_patch('TRANSFER_PREVIEWS/' . $tokenHash, [
        'used' => true,
        'used_at' => now_ts(),
        'status' => 'USED',
        'transfer_id' => $transferId,
        'updated_at' => now_ts(),
    ]);
}

function zpay_transfer_mark_preview_failed(string $tokenHash, string $code, string $message = ''): void
{
    if (trim($tokenHash) === '') {
        return;
    }
    @fb_patch('TRANSFER_PREVIEWS/' . $tokenHash, [
        'status' => 'FAILED',
        'failed_code' => zpay_dash_clean_string($code, 80),
        'failed_message' => zpay_dash_clean_string($message, 160),
        'updated_at' => now_ts(),
    ]);
}

function zpay_transfer_public_preview(array $preview, string $previewToken = ''): array
{
    $currency = wallet_normalize_currency_code((string)($preview['wallet_currency'] ?? $preview['currency'] ?? 'BDT'), 'BDT');
    $amount = zpay_transfer_money($preview['transfer_amount'] ?? $preview['amount'] ?? 0);
    $fee = zpay_transfer_money($preview['fee_amount'] ?? 0);
    $total = zpay_transfer_money($preview['total_debit'] ?? $preview['total_paid'] ?? $amount);
    $balanceAfter = zpay_transfer_money($preview['sender_balance_after'] ?? $preview['balance_after'] ?? 0);

    $data = [
        'receiver_name' => (string)($preview['receiver_name'] ?? ''),
        'receiver_phone' => (string)($preview['receiver_phone'] ?? ''),
        'receiver_account' => (string)($preview['receiver_phone'] ?? ''),
        'amount' => $amount,
        'transfer_amount' => $amount,
        'wallet_currency' => $currency,
        'currency' => $currency,
        'fee_amount' => $fee,
        'commission_amount' => zpay_transfer_money($preview['commission_amount'] ?? 0),
        'total_debit' => $total,
        'total_paid' => $total,
        'total_pay' => $total,
        'balance_before' => zpay_transfer_money($preview['sender_balance_before'] ?? $preview['balance_before'] ?? 0),
        'balance_after' => $balanceAfter,
        'amount_text' => zpay_transfer_text($amount, $currency),
        'fee_text' => zpay_transfer_text($fee, $currency),
        'total_paid_text' => zpay_transfer_text($total, $currency),
        'total_pay_text' => zpay_transfer_text($total, $currency),
        'balance_after_text' => zpay_transfer_text($balanceAfter, $currency),
        'minimum_amount' => 1.00,
        'can_submit' => true,
        'expires_at' => (int)($preview['expires_at'] ?? 0),
        'calculation_version' => (string)($preview['calculation_version'] ?? 'zpay_transfer_v1'),
    ];

    if ($previewToken !== '') {
        $data['preview_token'] = $previewToken;
    }

    if (isset($preview['recipient']) && is_array($preview['recipient'])) {
        $data['recipient'] = $preview['recipient'];
    }

    return $data;
}

function zpay_transfer_idempotency_key(string $raw): string
{
    $key = trim($raw);
    $key = preg_replace('/[^A-Za-z0-9._:-]+/', '', $key) ?? '';
    return strlen($key) > 120 ? substr($key, 0, 120) : $key;
}

function zpay_transfer_idempotency_path(string $senderUid, string $key): string
{
    return 'TRANSFER_IDEMPOTENCY/' . $senderUid . '/' . hash('sha256', $senderUid . '|' . $key);
}

function zpay_transfer_existing_response(string $transferId): void
{
    $row = fb_get('TRANSFERS/' . $transferId);
    $row = is_array($row) ? $row : [];
    api_response(true, 'TRANSFER_ALREADY_PROCESSED', 'Transfer already processed for this idempotency key.', [
        'transfer' => zpay_transfer_public_row($row),
    ]);
}

function zpay_transfer_acquire_idempotency(string $senderUid, string $key, string $transferId): string
{
    if ($key === '' || strlen($key) < 8) {
        api_response(false, 'IDEMPOTENCY_KEY_REQUIRED', 'idempotency_key is required.', [], 422);
    }

    $path = zpay_transfer_idempotency_path($senderUid, $key);
    for ($i = 0; $i < 3; $i++) {
        $res = fb_get_with_etag($path);
        if (!($res['ok'] ?? false) || empty($res['etag'])) {
            api_response(false, 'IDEMPOTENCY_UNAVAILABLE', 'Transfer safety check is unavailable.', [], 500);
        }

        $value = $res['value'] ?? null;
        if (is_array($value)) {
            $existingTransferId = trim((string)($value['transfer_id'] ?? ''));
            $status = strtoupper(trim((string)($value['status'] ?? '')));
            if ($existingTransferId !== '' && $status === 'SUCCESS') {
                zpay_transfer_existing_response($existingTransferId);
            }
            api_response(false, 'TRANSFER_PROCESSING', 'This transfer request is already processing.', [
                'transfer_id' => $existingTransferId,
            ], 409);
        }

        $lock = [
            'sender_uid' => $senderUid,
            'idempotency_key_hash' => hash('sha256', $key),
            'transfer_id' => $transferId,
            'status' => 'PROCESSING',
            'created_at' => now_ts(),
            'updated_at' => now_ts(),
        ];
        $save = fb_put_if_match($path, $lock, (string)$res['etag']);
        if (($save['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }
        if (!($save['ok'] ?? false)) {
            api_response(false, 'IDEMPOTENCY_LOCK_FAILED', 'Could not lock transfer request.', [], 500);
        }
        return $path;
    }

    api_response(false, 'TRANSFER_CONFLICT', 'Transfer request conflict. Please retry.', [], 409);
}

function zpay_transfer_save_receipt(array $transfer): array
{
    $transferId = trim((string)($transfer['transfer_id'] ?? ''));
    if ($transferId === '') {
        return [];
    }

    $now = now_ts();
    $receiptId = trim((string)($transfer['receipt_id'] ?? ''));
    if ($receiptId === '') {
        $receiptId = zpay_transfer_receipt_id();
    }
    $token = trim((string)($transfer['receipt_token'] ?? ''));
    if ($token === '') {
        $token = zpay_transfer_receipt_token();
    }
    $url = trim((string)($transfer['receipt_url'] ?? ''));
    if ($url === '') {
        $url = zpay_transfer_receipt_url($token);
    }

    $currency = wallet_normalize_currency_code((string)($transfer['currency'] ?? $transfer['wallet_currency'] ?? 'BDT'), 'BDT');
    $amount = zpay_transfer_money($transfer['amount'] ?? $transfer['transfer_amount'] ?? 0);
    $receipt = [
        'receipt_id' => $receiptId,
        'receipt_token' => $token,
        'receipt_url' => $url,
        'tracking_url' => $url,
        'transfer_id' => $transferId,
        'request_id' => $transferId,
        'title' => 'Z-Pay Swift Transfer Receipt',
        'sender_name' => (string)($transfer['sender_name'] ?? ''),
        'sender_account' => zpay_dash_mask_phone((string)($transfer['sender_account'] ?? '')),
        'receiver_name' => (string)($transfer['receiver_name'] ?? ''),
        'receiver_account' => zpay_dash_mask_phone((string)($transfer['receiver_account'] ?? '')),
        'amount' => $amount,
        'transfer_amount' => $amount,
        'wallet_currency' => $currency,
        'currency' => $currency,
        'fee_amount' => 0.0,
        'commission_amount' => 0.0,
        'total_paid' => $amount,
        'total_debit' => $amount,
        'amount_text' => zpay_transfer_text($amount, $currency),
        'fee_text' => zpay_transfer_text(0, $currency),
        'total_paid_text' => zpay_transfer_text($amount, $currency),
        'reference' => zpay_transfer_clean_reference($transfer['reference'] ?? $transfer['note'] ?? ''),
        'status' => (string)($transfer['status'] ?? 'SUCCESS'),
        'created_at' => (int)($transfer['created_at'] ?? $now),
        'updated_at' => $now,
    ];

    $receiptPath = 'TRANSFER_RECEIPTS/' . $receiptId;
    $indexPath = 'TRANSFER_RECEIPT_INDEX/' . $token;
    if (!fb_put($receiptPath, $receipt)) {
        return ['receipt_error' => 'Receipt save failed'];
    }
    if (!fb_put($indexPath, [
        'receipt_id' => $receiptId,
        'transfer_id' => $transferId,
        'created_at' => (int)$receipt['created_at'],
        'updated_at' => $now,
    ])) {
        fb_delete($receiptPath);
        return ['receipt_error' => 'Receipt index save failed'];
    }

    return [
        'receipt_id' => $receiptId,
        'receipt_token' => $token,
        'receipt_url' => $url,
        'tracking_url' => $url,
        'receipt_created_at' => (int)$receipt['created_at'],
        'written_paths' => [$receiptPath, $indexPath],
    ];
}

function zpay_transfer_load_receipt_by_token(string $token): array
{
    $token = trim($token);
    if ($token === '') {
        return [];
    }
    $index = fb_get('TRANSFER_RECEIPT_INDEX/' . $token);
    if (!is_array($index)) {
        return [];
    }
    $receiptId = trim((string)($index['receipt_id'] ?? ''));
    if ($receiptId === '') {
        return [];
    }
    $receipt = fb_get('TRANSFER_RECEIPTS/' . $receiptId);
    return is_array($receipt) ? $receipt : [];
}

function zpay_transfer_public_receipt(array $receipt): array
{
    $currency = wallet_normalize_currency_code((string)($receipt['wallet_currency'] ?? $receipt['currency'] ?? 'BDT'), 'BDT');
    $amount = zpay_transfer_money($receipt['amount'] ?? $receipt['transfer_amount'] ?? 0);
    return [
        'receipt_id' => (string)($receipt['receipt_id'] ?? ''),
        'transfer_id' => (string)($receipt['transfer_id'] ?? $receipt['request_id'] ?? ''),
        'request_id' => (string)($receipt['request_id'] ?? $receipt['transfer_id'] ?? ''),
        'title' => (string)($receipt['title'] ?? 'Z-Pay Swift Transfer Receipt'),
        'sender_name' => (string)($receipt['sender_name'] ?? ''),
        'sender_account' => (string)($receipt['sender_account'] ?? ''),
        'receiver_name' => (string)($receipt['receiver_name'] ?? ''),
        'receiver_account' => (string)($receipt['receiver_account'] ?? ''),
        'amount' => $amount,
        'transfer_amount' => $amount,
        'wallet_currency' => $currency,
        'currency' => $currency,
        'fee_amount' => 0.0,
        'total_paid' => $amount,
        'amount_text' => (string)($receipt['amount_text'] ?? zpay_transfer_text($amount, $currency)),
        'fee_text' => (string)($receipt['fee_text'] ?? zpay_transfer_text(0, $currency)),
        'total_paid_text' => (string)($receipt['total_paid_text'] ?? zpay_transfer_text($amount, $currency)),
        'reference' => zpay_transfer_clean_reference($receipt['reference'] ?? ''),
        'status' => (string)($receipt['status'] ?? 'SUCCESS'),
        'created_at' => (int)($receipt['created_at'] ?? 0),
        'updated_at' => (int)($receipt['updated_at'] ?? 0),
        'receipt_url' => (string)($receipt['receipt_url'] ?? ''),
        'tracking_url' => (string)($receipt['tracking_url'] ?? $receipt['receipt_url'] ?? ''),
    ];
}

function zpay_transfer_execute_preview(array $preview, string $tokenHash, string $reference = ''): array
{
    $senderUid = trim((string)($preview['sender_uid'] ?? $preview['uid'] ?? ''));
    $receiverUid = trim((string)($preview['receiver_uid'] ?? ''));
    $amount = zpay_transfer_money($preview['transfer_amount'] ?? $preview['amount'] ?? 0);
    $currency = wallet_normalize_currency_code((string)($preview['wallet_currency'] ?? $preview['currency'] ?? ''), '');

    if ($senderUid === '' || $receiverUid === '' || $amount <= 0 || !in_array($currency, ['MYR', 'BDT'], true)) {
        zpay_transfer_mark_preview_failed($tokenHash, 'TRANSFER_PREVIEW_INVALID', 'Transfer preview data is invalid.');
        return zpay_transfer_validation_error('TRANSFER_PREVIEW_INVALID', 'This transfer preview is invalid. Please review again.');
    }

    $senderUser = fb_get('USERS/' . $senderUid);
    $receiverUser = fb_get('USERS/' . $receiverUid);
    $senderUser = is_array($senderUser) ? $senderUser : [];
    $receiverUser = is_array($receiverUser) ? $receiverUser : [];

    $senderStatus = function_exists('auth_status_value') ? auth_status_value($senderUser['status'] ?? '') : strtoupper(trim((string)($senderUser['status'] ?? '')));
    $receiverStatus = function_exists('auth_status_value') ? auth_status_value($receiverUser['status'] ?? '') : strtoupper(trim((string)($receiverUser['status'] ?? '')));
    $senderAccountStatus = function_exists('auth_status_value') ? auth_status_value($senderUser['account_status'] ?? $senderStatus) : $senderStatus;
    $receiverAccountStatus = function_exists('auth_status_value') ? auth_status_value($receiverUser['account_status'] ?? $receiverStatus) : $receiverStatus;
    if ($senderStatus !== 'ACTIVE' || $senderAccountStatus !== 'ACTIVE' || $receiverStatus !== 'ACTIVE' || $receiverAccountStatus !== 'ACTIVE') {
        zpay_transfer_mark_preview_failed($tokenHash, 'TRANSFER_RECEIVER_INACTIVE', 'This Z-Pay account is not available for transfer.');
        return zpay_transfer_validation_error('TRANSFER_RECEIVER_INACTIVE', 'This Z-Pay account is not available for transfer.');
    }

    $senderWallet = fb_get('USER_WALLETS/' . $senderUid);
    $receiverWallet = fb_get('USER_WALLETS/' . $receiverUid);
    $senderContext = zpay_transfer_account_context($senderUid, $senderUser, is_array($senderWallet) ? $senderWallet : []);
    $receiverContext = zpay_transfer_account_context($receiverUid, $receiverUser, is_array($receiverWallet) ? $receiverWallet : []);
    if (empty($senderContext['ok']) || empty($receiverContext['ok']) || (string)$senderContext['wallet_currency'] !== (string)$receiverContext['wallet_currency'] || (string)$senderContext['wallet_currency'] !== $currency) {
        zpay_transfer_mark_preview_failed($tokenHash, 'TRANSFER_CURRENCY_MISMATCH', 'Transfers between different wallet currencies are not supported.');
        return zpay_transfer_validation_error('TRANSFER_CURRENCY_MISMATCH', 'Transfers between different wallet currencies are not supported.');
    }

    if ((float)$senderContext['available_balance'] + 0.0001 < $amount) {
        zpay_transfer_mark_preview_failed($tokenHash, 'TRANSFER_INSUFFICIENT_BALANCE', 'Insufficient balance.');
        return zpay_transfer_validation_error(
            'TRANSFER_INSUFFICIENT_BALANCE',
            $currency === 'MYR' ? 'Insufficient MYR balance.' : 'Insufficient BDT balance.',
            ['wallet_currency' => $currency]
        );
    }

    $transferId = wallet_make_transfer_id();
    $now = now_ts();
    $month = month_key($now);
    $senderLedgerId = wallet_make_ledger_id();
    $receiverLedgerId = wallet_make_ledger_id();
    $reference = zpay_transfer_clean_reference($reference);
    $senderPhone = (string)($senderUser['phone'] ?? $preview['sender_phone'] ?? '');
    $receiverPhone = (string)($receiverUser['phone'] ?? $preview['receiver_phone'] ?? '');
    $senderName = (string)($senderUser['name'] ?? $preview['sender_name'] ?? '');
    $receiverName = (string)($receiverUser['name'] ?? $preview['receiver_name'] ?? '');
    $senderRole = function_exists('auth_status_value') ? auth_status_value($senderUser['role'] ?? 'USER') : strtoupper(trim((string)($senderUser['role'] ?? 'USER')));
    $receiverRole = function_exists('auth_status_value') ? auth_status_value($receiverUser['role'] ?? 'USER') : strtoupper(trim((string)($receiverUser['role'] ?? 'USER')));

    $commonExtra = [
        'transfer_id' => $transferId,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'fee' => 0,
        'fee_amount' => 0,
        'commission' => 0,
        'commission_amount' => 0,
        'reference' => $reference,
        'note' => $reference,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $debit = wallet_debit_available($senderUid, $amount, $transferId, 'ZPAY_TRANSFER_SENT', 'Z-Pay transfer sent', array_merge($commonExtra, [
        'ledger_id' => $senderLedgerId,
        'receiver_uid' => $receiverUid,
        'counterparty_uid' => $receiverUid,
        'counterparty_name' => $receiverName,
        'receiver_account_masked' => zpay_dash_mask_phone($receiverPhone),
    ]));
    if (empty($debit['ok'])) {
        zpay_transfer_mark_preview_failed($tokenHash, (string)($debit['code'] ?? 'WALLET_DEBIT_FAILED'), (string)($debit['message'] ?? 'Transfer could not be processed.'));
        return zpay_transfer_validation_error(
            (string)($debit['code'] ?? 'WALLET_DEBIT_FAILED') === 'INSUFFICIENT_BALANCE' ? 'TRANSFER_INSUFFICIENT_BALANCE' : (string)($debit['code'] ?? 'WALLET_DEBIT_FAILED'),
            (string)($debit['code'] ?? '') === 'INSUFFICIENT_BALANCE'
                ? ($currency === 'MYR' ? 'Insufficient MYR balance.' : 'Insufficient BDT balance.')
                : (string)($debit['message'] ?? 'Transfer could not be processed.'),
            [],
            422
        );
    }

    $credit = wallet_credit_available($receiverUid, $amount, $transferId, 'ZPAY_TRANSFER_RECEIVED', 'Z-Pay transfer received', array_merge($commonExtra, [
        'ledger_id' => $receiverLedgerId,
        'sender_uid' => $senderUid,
        'counterparty_uid' => $senderUid,
        'counterparty_name' => $senderName,
        'sender_account_masked' => zpay_dash_mask_phone($senderPhone),
    ]));
    if (empty($credit['ok'])) {
        wallet_restore_available_balance($senderUid, (float)$debit['after_available'], (float)$debit['before_available']);
        wallet_delete_ledger_record($senderUid, $now, $senderLedgerId);
        zpay_transfer_mark_preview_failed($tokenHash, 'TRANSFER_FAILED', 'The transfer could not be completed. No money was lost.');
        return zpay_transfer_validation_error('TRANSFER_FAILED', 'The transfer could not be completed. No money was lost.', [], 500);
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
        'sender_account_country' => (string)$senderContext['account_country'],
        'receiver_account_country' => (string)$receiverContext['account_country'],
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
        'reference' => $reference,
        'note' => $reference,
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => $now,
        'month' => $month,
        'preview_token_hash' => $tokenHash,
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
            'type' => 'ZPAY_TRANSFER_SENT',
        ])],
        ['uid' => $receiverUid, 'row' => array_merge($transfer, [
            'ledger_id' => $receiverLedgerId,
            'direction' => 'CREDIT',
            'type' => 'ZPAY_TRANSFER_RECEIVED',
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
        zpay_transfer_mark_preview_failed($tokenHash, (string)($store['code'] ?? 'TRANSFER_STORE_FAILED'), 'Transfer history could not be saved.');
        return zpay_transfer_validation_error('TRANSFER_FAILED', 'The transfer could not be completed. No money was lost.', [], 500);
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
        zpay_transfer_mark_preview_failed($tokenHash, 'TRANSFER_FAILED', 'The transfer could not be completed. No money was lost.');
        return zpay_transfer_validation_error('TRANSFER_FAILED', 'The transfer could not be completed. No money was lost.', [], 500);
    }

    zpay_transfer_mark_preview_used($tokenHash, $transferId);
    system_log('TRANSFER_SUCCESS', $transferId, 'Z-Pay transfer completed', [
        'sender_uid' => $senderUid,
        'receiver_uid' => $receiverUid,
        'amount' => $amount,
        'currency' => $currency,
    ]);

    return [
        'ok' => true,
        'code' => 'TRANSFER_SUCCESS',
        'message' => 'Transfer completed successfully.',
        'transfer' => $transfer,
    ];
}

function zpay_transfer_public_row(array $row): array
{
    if (!$row) {
        return [];
    }

    $direction = strtoupper((string)($row['direction'] ?? ''));
    if ($direction === 'OUT') {
        $direction = 'DEBIT';
    } elseif ($direction === 'IN') {
        $direction = 'CREDIT';
    }
    $currency = wallet_normalize_currency_code((string)($row['wallet_currency'] ?? $row['currency'] ?? 'BDT'), 'BDT');
    $amount = zpay_transfer_money($row['amount'] ?? $row['transfer_amount'] ?? 0);
    $fee = zpay_transfer_money($row['fee_amount'] ?? $row['fee'] ?? 0);
    $total = zpay_transfer_money($row['total_paid'] ?? $row['total_debit'] ?? $amount);
    $isCredit = $direction === 'CREDIT';
    $balanceAfter = zpay_transfer_money($isCredit
        ? ($row['receiver_after_available'] ?? $row['after_available'] ?? 0)
        : ($row['sender_after_available'] ?? $row['after_available'] ?? 0));

    return [
        'transfer_id' => (string)($row['transfer_id'] ?? ''),
        'request_id' => (string)($row['request_id'] ?? $row['transfer_id'] ?? ''),
        'type' => 'ZPAY_TRANSFER',
        'direction' => $direction,
        'amount' => $amount,
        'transfer_amount' => $amount,
        'currency' => $currency,
        'wallet_currency' => $currency,
        'fee' => $fee,
        'fee_amount' => $fee,
        'commission' => 0.0,
        'commission_amount' => 0.0,
        'total_paid' => $total,
        'total_debit' => $total,
        'wallet_debit' => $total,
        'amount_text' => zpay_transfer_text($amount, $currency),
        'fee_text' => zpay_transfer_text($fee, $currency),
        'total_paid_text' => zpay_transfer_text($total, $currency),
        'balance_after' => $balanceAfter,
        'balance_after_text' => $balanceAfter > 0 ? zpay_transfer_text($balanceAfter, $currency) : '',
        'status' => (string)($row['status'] ?? ''),
        'reference' => zpay_transfer_clean_reference($row['reference'] ?? $row['note'] ?? ''),
        'note' => zpay_transfer_clean_reference($row['note'] ?? $row['reference'] ?? ''),
        'sender_name' => zpay_dash_mask_name((string)($row['sender_name'] ?? '')),
        'sender_account' => zpay_dash_mask_phone((string)($row['sender_account'] ?? $row['sender_phone'] ?? '')),
        'receiver_name' => zpay_dash_mask_name((string)($row['receiver_name'] ?? '')),
        'receiver_account' => zpay_dash_mask_phone((string)($row['receiver_account'] ?? $row['receiver_phone'] ?? '')),
        'counterparty_name' => zpay_dash_mask_name((string)($isCredit ? ($row['sender_name'] ?? '') : ($row['receiver_name'] ?? ''))),
        'counterparty_phone' => zpay_dash_mask_phone((string)($isCredit ? ($row['sender_account'] ?? $row['sender_phone'] ?? '') : ($row['receiver_account'] ?? $row['receiver_phone'] ?? ''))),
        'receipt_id' => (string)($row['receipt_id'] ?? ''),
        'receipt_url' => (string)($row['receipt_url'] ?? $row['tracking_url'] ?? ''),
        'tracking_url' => (string)($row['tracking_url'] ?? $row['receipt_url'] ?? ''),
        'receipt_created_at' => (int)($row['receipt_created_at'] ?? 0),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'completed_at' => (int)($row['completed_at'] ?? 0),
    ];
}

function zpay_transfer_user_can_view(array $row, string $uid): bool
{
    return $uid !== '' && (
        (string)($row['sender_uid'] ?? '') === $uid
        || (string)($row['receiver_uid'] ?? '') === $uid
    );
}

function zpay_transfer_user_history(string $uid, int $limit = 50): array
{
    $rows = fb_get('TRANSFER_HISTORY/' . $uid);
    $rows = is_array($rows) ? $rows : [];
    $items = [];
    foreach ($rows as $transferId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['transfer_id'] = (string)($row['transfer_id'] ?? $transferId);
        $items[] = zpay_transfer_public_row($row);
    }

    usort($items, static fn(array $a, array $b): int =>
        (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0)
    );

    return array_slice($items, 0, max(1, min(100, $limit)));
}
